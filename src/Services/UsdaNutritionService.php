<?php

namespace App\Services;

use App\Database;
use PDO;

/**
 * Deterministic nutrition calculator powered by USDA FoodData Central (FDC).
 * Normalizes recipe ingredient lines into gram weights, searches Foundation/SR Legacy
 * (with Survey/Branded fallback), caches USDA per-100g nutrient profiles in MySQL,
 * and computes per-serving macros and micronutrients mathematically.
 */
class UsdaNutritionService {
    private const FDC_SEARCH_URL = 'https://api.nal.usda.gov/fdc/v1/foods/search';
    private const GEMINI_BASE = 'https://generativelanguage.googleapis.com';
    private const NORMALIZER_MODEL = 'gemini-3.5-flash-lite';

    // USDA FoodData Central nutrient IDs
    private const NID_ENERGY_KCAL = 1008;
    private const NID_ENERGY_ATWATER_GEN = 2047;
    private const NID_ENERGY_ATWATER_SPEC = 2048;
    private const NID_ENERGY_KJ = 1062;
    private const NID_PROTEIN = 1003;
    private const NID_FAT = 1004;
    private const NID_CARBS = 1005;
    private const NID_FIBER = 1079;

    /**
     * Tracked micronutrients matching the Vault's 13-item panel specification:
     * Dietary Fiber, Sodium, and Saturated Fat are mandatory first 3; top 10 others follow by % DV.
     */
    private const TRACKED_MICROS = [
        ['id' => 1079, 'name' => 'Dietary Fiber', 'unit' => 'g',   'dv' => 28.0,   'mandatory' => true],
        ['id' => 1093, 'name' => 'Sodium',        'unit' => 'mg',  'dv' => 2300.0, 'mandatory' => true],
        ['id' => 1258, 'name' => 'Saturated Fat', 'unit' => 'g',   'dv' => 20.0,   'mandatory' => true],
        ['id' => 1092, 'name' => 'Potassium',     'unit' => 'mg',  'dv' => 4700.0, 'mandatory' => false],
        ['id' => 1089, 'name' => 'Iron',          'unit' => 'mg',  'dv' => 18.0,   'mandatory' => false],
        ['id' => 1087, 'name' => 'Calcium',       'unit' => 'mg',  'dv' => 1300.0, 'mandatory' => false],
        ['id' => 1090, 'name' => 'Magnesium',     'unit' => 'mg',  'dv' => 420.0,  'mandatory' => false],
        ['id' => 1095, 'name' => 'Zinc',          'unit' => 'mg',  'dv' => 11.0,   'mandatory' => false],
        ['id' => 1162, 'name' => 'Vitamin C',     'unit' => 'mg',  'dv' => 90.0,   'mandatory' => false],
        ['id' => 1106, 'name' => 'Vitamin A',     'unit' => 'mcg', 'dv' => 900.0,  'mandatory' => false],
        ['id' => 1177, 'name' => 'Folate',        'unit' => 'mcg', 'dv' => 400.0,  'mandatory' => false],
        ['id' => 1185, 'name' => 'Vitamin K',     'unit' => 'mcg', 'dv' => 120.0,  'mandatory' => false],
        ['id' => 1175, 'name' => 'Vitamin B6',    'unit' => 'mg',  'dv' => 1.7,    'mandatory' => false],
        ['id' => 1109, 'name' => 'Vitamin E',     'unit' => 'mg',  'dv' => 15.0,   'mandatory' => false],
        ['id' => 1178, 'name' => 'Vitamin B12',   'unit' => 'mcg', 'dv' => 2.4,    'mandatory' => false],
    ];

    private PDO $db;
    private string $usdaApiKey;
    private bool $usingDemoKey;
    private string $geminiApiKey;
    private ?string $caBundle;
    private bool $cacheTableReady = false;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::connect();
        $env = Database::loadEnv();

        $configuredUsdaKey = trim((string)($env['USDA_API_KEY'] ?? $env['FDC_API_KEY'] ?? ''));
        $this->usingDemoKey = ($configuredUsdaKey === '');
        $this->usdaApiKey = $this->usingDemoKey ? 'DEMO_KEY' : $configuredUsdaKey;
        $this->geminiApiKey = trim((string)($env['GEMINI_API_KEY'] ?? ''));
        $this->caBundle = $this->resolveCaBundlePath();
    }

    /**
     * Ensures the local MySQL cache table for USDA food lookups exists.
     */
    private function ensureCacheTable(): void {
        if ($this->cacheTableReady) {
            return;
        }
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS usda_food_cache (
                query_key VARCHAR(191) NOT NULL PRIMARY KEY,
                fdc_id INT NOT NULL,
                description VARCHAR(255) NOT NULL,
                data_type VARCHAR(64) NOT NULL,
                nutrients_json TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $this->cacheTableReady = true;
    }

    /**
     * Resolves an SSL CA certificate bundle path for outbound HTTPS curl calls on Windows/AMPPS.
     */
    private function resolveCaBundlePath(): ?string {
        $configured = trim((string)ini_get('curl.cainfo')) ?: trim((string)ini_get('openssl.cafile'));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $phpDir = dirname(PHP_BINARY);
        $candidates = [
            $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem',
            'C:\\Program Files\\Ampps\\php82\\extras\\ssl\\cacert.pem',
            'C:\\Program Files\\Ampps\\php\\extras\\ssl\\cacert.pem',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Parses a servings count from strings such as "** 2 servings", "4-6 servings", or "4".
     */
    public function parseServingsCount($yields): float {
        $raw = trim((string)$yields);
        if ($raw === '') {
            return 4.0;
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:-|to)\s*(\d+(?:\.\d+)?)/i', $raw, $range)) {
            $avg = ((float)$range[1] + (float)$range[2]) / 2.0;
            return max(1.0, min(50.0, $avg));
        }

        if (preg_match('/(\d+(?:\.\d+)?)/', $raw, $single)) {
            return max(1.0, min(50.0, (float)$single[1]));
        }

        return 4.0;
    }

    /**
     * Converts raw recipe ingredients + yield string into deterministic per-serving nutrition.
     *
     * @param array $rawIngredients Strings or associative ingredient rows from DB.
     * @param string $yields Recipe yield label (e.g. "2 servings").
     * @param string $recipeTitle Optional recipe title for Emma's Deep Dive summary.
     * @return array
     */
    public function calculateRecipeNutrition(array $rawIngredients, string $yields = '4 servings', string $recipeTitle = ''): array {
        $servings = $this->parseServingsCount($yields);
        $cleanedLines = $this->cleanRawIngredientLines($rawIngredients);

        if (empty($cleanedLines)) {
            return [
                'success' => false,
                'message' => 'No valid ingredients found to calculate.',
                'servings' => $servings,
                'matchedCount' => 0,
                'totalIngredients' => 0,
                'usingDemoKey' => $this->usingDemoKey,
            ];
        }

        $normalized = $this->normalizeIngredients($cleanedLines);
        $measurable = array_values(array_filter(
            $normalized,
            fn($item) => !empty($item['usda_query']) && (float)($item['grams'] ?? 0) > 0
        ));

        if (empty($measurable)) {
            return [
                'success' => false,
                'message' => 'Could not parse ingredient weights for USDA lookup.',
                'servings' => $servings,
                'matchedCount' => 0,
                'totalIngredients' => count($cleanedLines),
                'usingDemoKey' => $this->usingDemoKey,
            ];
        }

        // Deduplicate queries + fallback alternatives and fetch per-100g profiles
        $uniqueQueries = [];
        foreach ($measurable as $item) {
            $key = $this->normalizeQueryKey($item['usda_query']);
            if ($key !== '' && !isset($uniqueQueries[$key])) {
                $uniqueQueries[$key] = [
                    'query' => $item['usda_query'],
                    'fallback' => trim((string)($item['fallback_query'] ?? '')),
                ];
            }
        }

        $batchResult = $this->lookupFoodsBatch($uniqueQueries);
        $foodProfiles = $batchResult['profiles'] ?? [];
        $rateLimited = !empty($batchResult['rateLimited']);

        // Layer 4: If any ingredients still lack a USDA or closest-alternative match, ask Emma (Gemini) for per-100g estimates
        $unmatchedByKey = [];
        foreach ($measurable as $item) {
            $key = $this->normalizeQueryKey($item['usda_query']);
            if ($key !== '' && empty($foodProfiles[$key]['per100g']) && !isset($unmatchedByKey[$key])) {
                $unmatchedByKey[$key] = [
                    'key' => $key,
                    'original' => $item['original'],
                    'label' => $item['usda_query'],
                ];
            }
        }

        $emmaFallbackCount = 0;
        if (!empty($unmatchedByKey) && $this->geminiApiKey !== '') {
            $emmaProfiles = $this->estimateUnmatchedWithEmma(array_values($unmatchedByKey));
            foreach ($emmaProfiles as $eKey => $eProfile) {
                $foodProfiles[$eKey] = $eProfile;
                $emmaFallbackCount++;
            }
        }

        $totalCalories = 0.0;
        $totalProtein = 0.0;
        $totalCarbs = 0.0;
        $totalFat = 0.0;
        $totalFiber = 0.0;

        $microTotals = [];
        $microTopContributors = [];
        foreach (self::TRACKED_MICROS as $spec) {
            $microTotals[$spec['name']] = 0.0;
            $microTopContributors[$spec['name']] = [];
        }

        $breakdown = [];
        $matchedCount = 0;

        foreach ($measurable as $item) {
            $key = $this->normalizeQueryKey($item['usda_query']);
            $grams = (float)$item['grams'];
            $profile = $foodProfiles[$key] ?? null;

            if (!$profile || empty($profile['per100g'])) {
                $breakdown[] = [
                    'original' => $item['original'],
                    'usda_query' => $item['usda_query'],
                    'grams' => round($grams, 1),
                    'matched' => false,
                    'fdc_id' => null,
                    'fdc_description' => null,
                    'calories' => 0,
                    'protein_g' => 0,
                    'carbs_g' => 0,
                    'fat_g' => 0,
                ];
                continue;
            }

            $matchedCount++;
            $factor = $grams / 100.0;
            $p = $profile['per100g'];

            $itemCals = ($p['calories'] ?? 0.0) * $factor;
            $itemPro = ($p['protein_g'] ?? 0.0) * $factor;
            $itemCarb = ($p['carbs_g'] ?? 0.0) * $factor;
            $itemFat = ($p['fat_g'] ?? 0.0) * $factor;
            $itemFib = ($p['fiber_g'] ?? 0.0) * $factor;

            $totalCalories += $itemCals;
            $totalProtein += $itemPro;
            $totalCarbs += $itemCarb;
            $totalFat += $itemFat;
            $totalFiber += $itemFib;

            $isEmmaFallback = ($profile['data_type'] ?? '') === 'Emma Estimate';
            $sourceLabel = $item['usda_query'] . ($isEmmaFallback ? ' [Emma est.]' : '');

            foreach (self::TRACKED_MICROS as $spec) {
                $mName = $spec['name'];
                $mVal = (float)($p['micros'][$mName] ?? 0.0) * $factor;
                $microTotals[$mName] += $mVal;
                if ($mVal > 0.01) {
                    $microTopContributors[$mName][] = [
                        'label' => $sourceLabel,
                        'amount' => $mVal,
                    ];
                }
            }

            $breakdown[] = [
                'original' => $item['original'],
                'usda_query' => $item['usda_query'],
                'grams' => round($grams, 1),
                'matched' => true,
                'fdc_id' => (int)$profile['fdc_id'],
                'fdc_description' => $profile['description'],
                'data_type' => $profile['data_type'],
                'calories' => round($itemCals / $servings, 1),
                'protein_g' => round($itemPro / $servings, 1),
                'carbs_g' => round($itemCarb / $servings, 1),
                'fat_g' => round($itemFat / $servings, 1),
                'total_recipe_fat_g' => round($itemFat, 1),
            ];
        }

        $totalMeasurable = count($measurable);
        $matchedRatio = $totalMeasurable > 0 ? ($matchedCount / $totalMeasurable) : 0.0;

        if ($matchedCount === 0 || $matchedRatio < 0.4) {
            $failMsg = $rateLimited
                ? ($this->usingDemoKey
                    ? 'USDA FoodData Central rate limit reached (DEMO_KEY allows 30 req/hr). Add a free USDA_API_KEY to .env for 1,000 req/hr.'
                    : 'USDA FoodData Central rate limit reached. Please try again shortly.')
                : 'Too few ingredients matched in USDA FoodData Central.';
            return [
                'success' => false,
                'message' => $failMsg,
                'rateLimited' => $rateLimited,
                'servings' => $servings,
                'matchedCount' => $matchedCount,
                'totalIngredients' => $totalMeasurable,
                'breakdown' => $breakdown,
                'usingDemoKey' => $this->usingDemoKey,
            ];
        }

        $perServingCals = round($totalCalories / $servings, 0);
        $perServingPro = round($totalProtein / $servings, 1);
        $perServingCarb = round($totalCarbs / $servings, 1);
        $perServingFat = round($totalFat / $servings, 1);
        $perServingFib = round($totalFiber / $servings, 1);

        // Build full matched ingredient contributor summary for NutritionPanel breakdown
        $matchedRows = array_values(array_filter($breakdown, fn($b) => !empty($b['matched'])));
        usort($matchedRows, fn($a, $b) => ($b['calories'] <=> $a['calories']));
        $topSummaryParts = array_map(
            fn($r) => sprintf(
                '%sg %s%s (%sg C / %sg F / %sg P per srv)',
                $r['grams'],
                $r['usda_query'],
                ($r['data_type'] ?? '') === 'Emma Estimate' ? ' [Emma est.]' : '',
                $r['carbs_g'],
                $r['fat_g'],
                $r['protein_g']
            ),
            $matchedRows
        );
        $servingsLabel = (abs($servings - round($servings)) < 0.01)
            ? (string)(int)round($servings)
            : (string)round($servings, 1);

        $emmaCountUsed = count(array_filter($matchedRows, fn($r) => ($r['data_type'] ?? '') === 'Emma Estimate'));
        $usdaCountUsed = $matchedCount - $emmaCountUsed;
        $matchNote = $emmaCountUsed > 0
            ? sprintf('%d/%d matched, %d USDA + %d Emma est.', $matchedCount, $totalMeasurable, $usdaCountUsed, $emmaCountUsed)
            : sprintf('%d/%d ingredients matched', $matchedCount, $totalMeasurable);

        $mathSummary = sprintf(
            'USDA FoodData Central (%s %s, %s): %s',
            $servingsLabel,
            $servings == 1.0 ? 'serving' : 'servings',
            $matchNote,
            implode('; ', $topSummaryParts)
        );

        // Build mandatory first 3 micros + top 10 additional by % DV
        $mandatoryMicros = [];
        $optionalMicros = [];

        foreach (self::TRACKED_MICROS as $spec) {
            $mName = $spec['name'];
            $perServingVal = $microTotals[$mName] / $servings;
            $roundedAmt = $perServingVal >= 100
                ? round($perServingVal, 0)
                : ($perServingVal >= 10 ? round($perServingVal, 1) : round($perServingVal, 2));
            $dvPct = $spec['dv'] > 0
                ? min(999.99, round(($perServingVal / $spec['dv']) * 100, 1))
                : 0.0;

            $contribs = $microTopContributors[$mName];
            usort($contribs, fn($a, $b) => ($b['amount'] <=> $a['amount']));
            $topNames = array_map(fn($c) => $c['label'], array_slice($contribs, 0, 2));
            $microMath = !empty($topNames)
                ? 'USDA FDC — primary sources: ' . implode(', ', $topNames)
                : 'USDA FDC (0 in matched ingredients)';

            $entry = [
                'name' => $mName,
                'nutrient_name' => $mName,
                'amount' => $roundedAmt,
                'unit' => $spec['unit'],
                'dv' => $dvPct,
                'daily_value_percentage' => $dvPct,
                'math_calculations' => $microMath,
            ];

            if ($spec['mandatory']) {
                $mandatoryMicros[] = $entry;
            } else {
                $optionalMicros[] = $entry;
            }
        }

        usort($optionalMicros, fn($a, $b) => ($b['dv'] <=> $a['dv']));
        $finalMicros = array_merge($mandatoryMicros, array_slice($optionalMicros, 0, 10));

        $macrosArray = [
            'calories' => $perServingCals,
            'protein_g' => $perServingPro,
            'carbs_g' => $perServingCarb,
            'fat_g' => $perServingFat,
            'fiber_g' => $perServingFib,
            'math_calculations' => $mathSummary,
        ];
        $deepDive = $this->formatDeepDiveMarkdown(
            $macrosArray,
            $finalMicros,
            $servings,
            $matchedCount,
            $totalMeasurable,
            $recipeTitle,
            $breakdown
        );

        return [
            'success' => true,
            'source' => 'usda_fdc',
            'usingDemoKey' => $this->usingDemoKey,
            'servings' => $servings,
            'matchedCount' => $matchedCount,
            'totalIngredients' => $totalMeasurable,
            'macros' => $macrosArray,
            'micros' => $finalMicros,
            'breakdown' => $breakdown,
            'deep_dive' => $deepDive,
        ];
    }

    /**
     * Maps an ingredient name/query to its practical whole-food health benefit.
     */
    private function describeIngredientBenefit(string $ingredientLabel): string {
        $q = strtolower($ingredientLabel);
        if (preg_match('/\b(oat|oats|quinoa|buckwheat|millet|barley|farro|brown rice|wild rice|whole[- ]wheat|spelt)\b/', $q)) {
            return 'slow-release complex carbohydrates, soluble beta-glucan fiber, and steady energy';
        }
        if (preg_match('/\b(almond flour|coconut flour|oat flour|chickpea flour|flour)\b/', $q)) {
            return 'wholesome plant-based structure along with minerals and prebiotic fiber';
        }
        if (preg_match('/\b(flax|chia|hemp)\b/', $q)) {
            return 'plant-based omega-3 fatty acids, mucilage fiber, and lignans for heart and digestive health';
        }
        if (preg_match('/\b(almond|walnut|cashew|pecan|pistachio|tahini|sesame|sunflower|pumpkin seed|peanut)\b/', $q)) {
            return 'heart-healthy unsaturated fats, natural creaminess without refined oils, and trace minerals';
        }
        if (preg_match('/\b(bean|lentil|chickpea|garbanzo|tofu|tempeh|edamame|pea protein)\b/', $q)) {
            return 'complete plant protein, resistant starch for gut flora, and long-lasting satiety';
        }
        if (preg_match('/\b(cinnamon|turmeric|ginger|clove|nutmeg|cardamom|cocoa|cacao|vanilla)\b/', $q)) {
            return 'concentrated antioxidant polyphenols that warm the palate and support blood-sugar balance';
        }
        if (preg_match('/\b(date|dates|banana|apple|applesauce|raisin|berry|berries|blueberry|strawberry|fig|mango|pumpkin)\b/', $q)) {
            return 'natural whole-fruit sweetness, soluble pectin fiber, and protective phytonutrients';
        }
        if (preg_match('/\b(spinach|kale|arugula|chard|broccoli|collard|parsley|cilantro|basil|greens)\b/', $q)) {
            return 'chlorophyll, folates, and cellular-protective phytonutrients with virtually zero caloric weight';
        }
        if (preg_match('/\b(garlic|onion|shallot|leek|scallion|chive)\b/', $q)) {
            return 'prebiotic inulin fibers and organosulfur compounds that bolster immune resilience';
        }
        if (preg_match('/\b(sweet potato|carrot|squash|bell pepper|tomato)\b/', $q)) {
            return 'carotenoid antioxidants like beta-carotene and lycopene for skin, vision, and recovery';
        }
        if (preg_match('/\b(lemon|lime|orange|citrus|vinegar)\b/', $q)) {
            return 'bright natural acidity and vitamin-rich zest that also enhance plant-iron absorption';
        }
        if (preg_match('/\b(soy milk|oat milk|almond milk|plant milk)\b/', $q)) {
            return 'dairy-free moisture along with plant protein or fortify-friendly lightness';
        }

        return 'clean, whole-food plant nourishment that layers flavor without refined oils or additives';
    }

    /**
     * Builds an Emma-voiced Deep Dive focused on health aspects, ingredient benefits,
     * and pairing advice — intentionally avoiding repeating stats already shown in the Nutrition Panel.
     */
    public function formatDeepDiveMarkdown(
        array $macros,
        array $micros,
        float $servings,
        int $matchedCount,
        int $totalMeasurable,
        string $recipeTitle = '',
        array $breakdown = []
    ): string {
        $cleanTitle = trim((string)preg_replace('/^#+\s*/', '', $recipeTitle));
        $cleanTitle = trim($cleanTitle, "[]\"'* ");
        $dishRef = $cleanTitle !== '' ? "**{$cleanTitle}**" : 'this whole-food plant-based dish';

        $satFatDv = 0.0;
        $fiberDv = 0.0;
        $standoutNames = [];
        foreach ($micros as $m) {
            $name = (string)($m['name'] ?? $m['nutrient_name'] ?? '');
            $dv = (float)($m['dv'] ?? $m['daily_value_percentage'] ?? 0);
            if ($name === 'Saturated Fat') {
                $satFatDv = $dv;
            } elseif ($name === 'Dietary Fiber') {
                $fiberDv = $dv;
            } elseif ($dv >= 10.0 && count($standoutNames) < 3) {
                $standoutNames[] = strtolower($name);
            }
        }

        $traits = ['naturally free of cholesterol and excess oil'];
        if ($satFatDv <= 10.0) {
            $traits[] = 'kind to cardiovascular health';
        }
        if ($fiberDv >= 15.0) {
            $traits[] = 'wonderfully rich in gut-nurturing plant fiber for gentle satiety';
        } else {
            $traits[] = 'light and easy to digest without sluggishness';
        }
        if (!empty($standoutNames)) {
            $traits[] = 'a notably bright source of ' . (count($standoutNames) > 1
                ? implode(', ', array_slice($standoutNames, 0, -1)) . ' and ' . end($standoutNames)
                : $standoutNames[0]);
        }

        $p1 = sprintf(
            "### Nutritional & Health Perspective\nWhat makes %s such a clever kitchen creation is how effortlessly it delivers clean, whole-food nourishment while remaining %s — letting every ingredient earn its place rather than leaning on heavy refined fats or empty calories.",
            $dishRef,
            implode(', ', $traits)
        );

        // Spotlight 2-4 matched ingredients (prioritizing highest weight/caloric contributors, skipping salt/water)
        $matchedIngredients = array_values(array_filter(
            $breakdown,
            fn($row) => !empty($row['matched']) && !preg_match('/^\s*(?:water|ice|sea salt|salt|kosher salt|baking powder|baking soda)\s*$/i', (string)($row['usda_query'] ?? ''))
        ));
        usort($matchedIngredients, fn($a, $b) => ($b['grams'] ?? 0) <=> ($a['grams'] ?? 0));

        $seenLabels = [];
        $spotlights = [];
        foreach ($matchedIngredients as $item) {
            $rawLabel = trim((string)($item['usda_query'] ?? ''));
            $label = trim((string)preg_replace('/,\s*raw\b/i', '', $rawLabel));
            $lowerKey = strtolower($label);
            if ($label === '' || isset($seenLabels[$lowerKey])) {
                continue;
            }
            $seenLabels[$lowerKey] = true;
            $displayName = ucfirst($label);
            $benefit = $this->describeIngredientBenefit($label);
            $spotlights[] = "* **{$displayName}:** Brings {$benefit}.";
            if (count($spotlights) >= 4) {
                break;
            }
        }

        $sections = [$p1];
        if (!empty($spotlights)) {
            $sections[] = "### Ingredient Spotlight\n" . implode("\n", $spotlights);
        }

        // Tailored pairing & serving advice based on dish type
        $titleAndIngContext = strtolower($cleanTitle . ' ' . implode(' ', array_keys($seenLabels)));
        $isSweetOrBake = (bool)preg_match(
            '/\b(cookie|cookies|brownie|cake|muffin|muffins|pie|tart|bar|bars|pudding|oatmeal|granola|pancake|waffle|smoothie|parfait|snickerdoodle|shortbread|scone|biscuit|sweet|dessert)\b/',
            $titleAndIngContext
        );

        if ($isSweetOrBake) {
            $pairingBody = "Enjoy alongside a warm mug of herbal rooibos, chamomile, or lightly steamed unsweetened soy or oat milk for extra plant protein, or serve with a handful of fresh berries whose bright tartness balances natural whole-food sweetness.";
        } else {
            $pairingBody = "For balanced nutrient absorption, pair with a squeeze of fresh lemon or lime (vitamin C boosts non-heme plant iron uptake), or round out the plate with crisp leafy greens, fermented veggies for probiotics, or a light crunchy slaw.";
        }
        $sections[] = "### Emma's Pairing & Serving Advice\n{$pairingBody}";

        return implode("\n\n", $sections);
    }

    /**
     * Filters section headings and equipment/non-food lines from stored or incoming ingredients.
     */
    private function cleanRawIngredientLines(array $rawIngredients): array {
        $cleaned = [];
        foreach ($rawIngredients as $item) {
            if (is_array($item)) {
                $qty = (float)($item['quantity'] ?? 0);
                $unit = trim((string)($item['unit'] ?? ''));
                $name = trim((string)($item['ingredient_name'] ?? $item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                // Only prepend stored quantity/unit when the name doesn't already look quantified
                if ($qty > 0 && strtolower($unit) !== 'serving' && !preg_match('/^\d/', $name)) {
                    $line = trim($qty . ($unit !== '' ? ' ' . $unit : '') . ' ' . $name);
                } else {
                    $line = $name;
                }
            } else {
                $line = trim((string)$item);
            }

            $line = preg_replace('/^\*+\s*/', '', $line);
            $line = preg_replace('/\*+$/', '', $line);
            $line = trim((string)$line);

            if ($line === '') {
                continue;
            }

            // Skip markdown subheadings like "For the Chimichurri Sauce:"
            if (preg_match('/^for\s+the\s+.+:?$/i', $line) || str_ends_with($line, ':')) {
                continue;
            }
            // Skip non-food tools (skewers, toothpicks, parchment)
            if (preg_match('/\b(wooden|metal|bamboo)\s+skewers?\b|\bparchment\s+paper\b|\btoothpicks?\b/i', $line)) {
                continue;
            }

            $cleaned[] = $line;
        }
        return $cleaned;
    }

    /**
     * Uses Gemini structured JSON mode to convert recipe ingredient lines into clean USDA search terms and gram weights.
     * Falls back deterministically to regex parsing if Gemini is unavailable.
     */
    private function normalizeIngredients(array $lines): array {
        if ($this->geminiApiKey !== '') {
            $geminiResult = $this->normalizeWithGemini($lines);
            if ($geminiResult !== null && count($geminiResult) > 0) {
                return $geminiResult;
            }
        }

        return array_map(fn($line) => $this->fallbackParseIngredient($line), $lines);
    }

    private function normalizeWithGemini(array $lines): ?array {
        $numbered = [];
        foreach ($lines as $idx => $line) {
            $numbered[] = ($idx + 1) . '. ' . $line;
        }

        $prompt = "Convert each recipe ingredient line below into a clean generic USDA FoodData Central search query, a closest generic USDA fallback query, and an accurate metric weight in grams for the ENTIRE batch quantity stated in the line.\n\n"
            . "CRITICAL CONVERSION RULES:\n"
            . "1. Exact weight conversions: 1 oz = 28.35g (e.g. 14 oz tofu = 397g; 14-16 oz = 425g), 1 lb = 453.6g, 1 kg = 1000g.\n"
            . "2. Match raw vs cooked vs dry to how the ingredient is measured (e.g. '1 cup cooked chickpeas' -> usda_query 'chickpeas, cooked', grams 164; '1 can (15 oz) chickpeas, drained' -> 'chickpeas, canned, drained solids', grams 240; '8 oz dry rice noodles' -> 'rice noodles, dry', grams 227; '1 cup light coconut milk' -> 'coconut milk, canned, light', grams 240).\n"
            . "3. Use official USDA naming for WFPB specialty pantry items AND supply a close USDA generic fallback_query:\n"
            . "   - 'low-sodium tamari' / 'reduced-sodium tamari' -> usda_query 'soy sauce, reduced sodium', fallback_query 'tamari'\n"
            . "   - 'tamari' -> usda_query 'tamari', fallback_query 'soy sauce, reduced sodium'\n"
            . "   - 'coconut aminos' -> usda_query 'coconut aminos', fallback_query 'soy sauce, reduced sodium'\n"
            . "   - 'nutritional yeast' -> usda_query 'nutritional yeast', fallback_query 'yeast'\n"
            . "   - 'mushroom seasoning paste' -> usda_query 'mushroom seasoning', fallback_query 'mushrooms, shiitake, dried'\n"
            . "   - 'miso paste' -> usda_query 'miso', fallback_query 'soy sauce, reduced sodium'\n"
            . "   - 'liquid aminos' -> usda_query 'liquid aminos', fallback_query 'soy sauce'\n"
            . "   - Preserve 'tofu, extra firm', 'tofu, firm', 'tofu, silken', 'tempeh', 'tahini, sesame butter', 'coconut milk, light'. Strip prep verbs (pressed, diced, minced, smashed, charred).\n"
            . "   - For everyday whole foods already in USDA (e.g. 'broccoli, raw'), fallback_query can be empty string \"\".\n"
            . "4. If 'A or B' appears, calculate using A only.\n"
            . "5. Common volume/piece weights: 1 tbsp olive/sesame/other = ~14g, 1 tbsp herbs chopped = ~4g, 1 tbsp lemon/lime juice/vinegar/soy sauce/tamari/coconut aminos = 15g, 1 tbsp tomato paste/miso/mushroom paste = 16g, 1 tbsp tahini/nut butter = 16g, 1 tbsp nutritional yeast = 5g, 1 clove garlic = 3g, 1 medium potato = 170g, 1 lb baby potatoes = 454g, 1 cup raw leafy greens/herbs = 30g, 1 cup brussels sprouts = 88g.\n"
            . "6. Salt/black pepper 'to taste' -> small realistic pinch (salt 0.5g, black pepper 0.3g). Water -> grams 0.\n\n"
            . "Ingredients:\n" . implode("\n", $numbered);

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'items' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'original' => ['type' => 'STRING'],
                                    'usda_query' => ['type' => 'STRING'],
                                    'fallback_query' => ['type' => 'STRING'],
                                    'grams' => ['type' => 'NUMBER'],
                                ],
                                'required' => ['original', 'usda_query', 'fallback_query', 'grams'],
                            ],
                        ],
                    ],
                    'required' => ['items'],
                ],
            ],
        ];

        $url = self::GEMINI_BASE . '/v1beta/models/' . self::NORMALIZER_MODEL . ':generateContent';
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->geminiApiKey,
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ];
        if ($this->caBundle !== null) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
            return null;
        }

        $decoded = json_decode($raw, true);
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text === '') {
            return null;
        }

        $parsed = json_decode($text, true);
        $items = $parsed['items'] ?? (is_array($parsed) && isset($parsed[0]) ? $parsed : null);
        if (!is_array($items)) {
            return null;
        }

        $normalized = [];
        foreach ($items as $idx => $it) {
            if (!is_array($it)) {
                continue;
            }
            $orig = trim((string)($it['original'] ?? ($lines[$idx] ?? '')));
            $query = trim((string)($it['usda_query'] ?? ''));
            $fallbackQuery = trim((string)($it['fallback_query'] ?? ''));
            $grams = max(0.0, (float)($it['grams'] ?? 0));
            if ($orig !== '' && $query !== '') {
                $normalized[] = [
                    'original' => $orig,
                    'usda_query' => $query,
                    'fallback_query' => $fallbackQuery,
                    'grams' => $grams,
                ];
            }
        }
        return !empty($normalized) ? $normalized : null;
    }

    /**
     * Deterministic regex parser used as a fallback if LLM normalization is unavailable.
     */
    private function fallbackParseIngredient(string $line): array {
        $work = strtr($line, ['½' => ' 0.5', '⅓' => ' 0.333', '⅔' => ' 0.667', '¼' => ' 0.25', '¾' => ' 0.75', '⅛' => ' 0.125']);
        $work = preg_replace('/\s+/', ' ', trim($work));

        $grams = 50.0;
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:-\s*(\d+(?:\.\d+)?)\s*)?(g|grams?|kg|oz|ounces?|lb|lbs|pounds?|ml|l)\b/i', $work, $m)) {
            $qty = !empty($m[2]) ? (((float)$m[1] + (float)$m[2]) / 2.0) : (float)$m[1];
            $unit = strtolower($m[3]);
            if (in_array($unit, ['g', 'gram', 'grams'], true)) {
                $grams = $qty;
            } elseif ($unit === 'kg') {
                $grams = $qty * 1000.0;
            } elseif (in_array($unit, ['oz', 'ounce', 'ounces'], true)) {
                $grams = $qty * 28.35;
            } elseif (in_array($unit, ['lb', 'lbs', 'pound', 'pounds'], true)) {
                $grams = $qty * 453.6;
            } elseif ($unit === 'ml') {
                $grams = $qty;
            } elseif ($unit === 'l') {
                $grams = $qty * 1000.0;
            }
        } elseif (preg_match('/^(?:(\d+)\s+(\d+)\/(\d+)|(\d+)\/(\d+)|(\d+(?:\.\d+)?))\s*(cups?|tbsps?|tablespoons?|tsps?|teaspoons?|cloves?|cans?|blocks?)?\b/i', $work, $m)) {
            if (!empty($m[1]) && !empty($m[3])) {
                $qty = (float)$m[1] + ((float)$m[2] / (float)$m[3]);
            } elseif (!empty($m[4]) && !empty($m[5])) {
                $qty = (float)$m[4] / (float)$m[5];
            } else {
                $qty = (float)($m[6] ?: 1);
            }
            $unit = strtolower($m[7] ?? '');
            if (str_starts_with($unit, 'cup')) {
                $grams = $qty * 150.0;
            } elseif (str_starts_with($unit, 'tbsp') || str_starts_with($unit, 'tablespoon')) {
                $grams = $qty * 15.0;
            } elseif (str_starts_with($unit, 'tsp') || str_starts_with($unit, 'teaspoon')) {
                $grams = $qty * 5.0;
            } elseif (str_starts_with($unit, 'clove')) {
                $grams = $qty * 3.0;
            } elseif (str_starts_with($unit, 'can')) {
                $grams = $qty * 400.0;
            } elseif (str_starts_with($unit, 'block')) {
                $grams = $qty * 397.0;
            } else {
                $grams = $qty * 100.0;
            }
        }

        // Clean measurement/prep noise to form a decent USDA search string
        $query = preg_replace('/\([^)]*\)/', ' ', $work);
        $query = preg_replace('/^\d[\d\s\/.\-]*\s*(?:cups?|tbsps?|tablespoons?|tsps?|teaspoons?|g|grams?|kg|oz|ounces?|lb|lbs|pounds?|ml|l|cloves?|cans?|blocks?|slices?|sprigs?|bunches?|heads?|stalks?|medium|large|small)?\b/i', '', $query);
        $query = explode(',', (string)$query)[0];
        $query = preg_split('/\bor\b/i', (string)$query)[0] ?? $query;
        $query = preg_replace('/\s+/', ' ', trim((string)$query));

        return [
            'original' => $line,
            'usda_query' => $query ?: $line,
            'fallback_query' => '',
            'grams' => round($grams, 1),
        ];
    }

    private function normalizeQueryKey(string $query): string {
        $q = mb_strtolower(trim($query));
        $q = preg_replace('/\s+/', ' ', $q);
        return mb_substr((string)$q, 0, 191);
    }

    /**
     * Checks local cache first, then batch-fetches uncached queries from USDA FDC across:
     * Pass 1 (Foundation/SR Legacy/Survey), Pass 2 (Branded), Pass 3 (closest fallback_query).
     */
    private function lookupFoodsBatch(array $uniqueItems): array {
        $this->ensureCacheTable();
        $results = [];
        $misses = [];

        $keys = array_keys($uniqueItems);
        if (!empty($keys)) {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $this->db->prepare("SELECT query_key, fdc_id, description, data_type, nutrients_json FROM usda_food_cache WHERE query_key IN ($placeholders)");
            $stmt->execute($keys);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $decoded = json_decode((string)$row['nutrients_json'], true);
                if (is_array($decoded)) {
                    $results[$row['query_key']] = [
                        'fdc_id' => (int)$row['fdc_id'],
                        'description' => $row['description'],
                        'data_type' => $row['data_type'],
                        'per100g' => $decoded,
                    ];
                }
            }
        }

        foreach ($uniqueItems as $key => $spec) {
            if (!isset($results[$key])) {
                $misses[$key] = $spec['query'];
            }
        }

        if (empty($misses)) {
            return ['profiles' => $results, 'rateLimited' => false];
        }

        // Pass 1: Parallel relevance-sorted search across Foundation, SR Legacy, and Survey (FNDDS)
        $pass1Responses = $this->multiPostUsdaSearch($misses, ['Foundation', 'SR Legacy', 'Survey (FNDDS)'], 15);
        $pass2Queue = [];
        $bestPass1 = [];
        $anyRateLimited = false;

        foreach ($misses as $key => $queryText) {
            if (!empty($pass1Responses[$key]['rateLimited'])) {
                $anyRateLimited = true;
            }
            $foods = $pass1Responses[$key]['foods'] ?? [];
            $candidate = $this->selectBestCandidate($queryText, $foods);
            if ($candidate && $candidate['score'] >= 16.0) {
                $bestPass1[$key] = $candidate;
            } else {
                if ($candidate) {
                    $bestPass1[$key] = $candidate;
                }
                if (empty($pass1Responses[$key]['rateLimited'])) {
                    $pass2Queue[$key] = $queryText;
                }
            }
        }

        // Pass 2: Fallback to Branded only for items without a strong Pass 1 hit
        $pass2Responses = !empty($pass2Queue)
            ? $this->multiPostUsdaSearch($pass2Queue, ['Branded'], 10)
            : [];

        $cacheInsert = $this->db->prepare("
            INSERT INTO usda_food_cache (query_key, fdc_id, description, data_type, nutrients_json)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                fdc_id = VALUES(fdc_id),
                description = VALUES(description),
                data_type = VALUES(data_type),
                nutrients_json = VALUES(nutrients_json)
        ");

        $pass3Queue = [];
        foreach ($misses as $key => $queryText) {
            if (!empty($pass2Responses[$key]['rateLimited'])) {
                $anyRateLimited = true;
            }
            $chosen = $bestPass1[$key] ?? null;
            if (isset($pass2Responses[$key]['foods'])) {
                $alt = $this->selectBestCandidate($queryText, $pass2Responses[$key]['foods']);
                if ($alt && (!$chosen || $alt['score'] > $chosen['score'])) {
                    $chosen = $alt;
                }
            }

            if ($chosen && $chosen['score'] >= 10.0) {
                $per100g = $this->extractPer100gNutrients($chosen['food']);
                $profile = [
                    'fdc_id' => (int)($chosen['food']['fdcId'] ?? 0),
                    'description' => (string)($chosen['food']['description'] ?? $queryText),
                    'data_type' => (string)($chosen['food']['dataType'] ?? 'SR Legacy'),
                    'per100g' => $per100g,
                ];
                $results[$key] = $profile;

                try {
                    $cacheInsert->execute([
                        $key,
                        $profile['fdc_id'],
                        mb_substr($profile['description'], 0, 255),
                        mb_substr($profile['data_type'], 0, 64),
                        json_encode($per100g),
                    ]);
                } catch (\Throwable $e) {
                    error_log('USDA cache write skipped: ' . $e->getMessage());
                }
            } elseif (!$anyRateLimited) {
                $fbQuery = trim((string)($uniqueItems[$key]['fallback'] ?? ''));
                if ($fbQuery !== '' && $this->normalizeQueryKey($fbQuery) !== $key) {
                    $pass3Queue[$key] = $fbQuery;
                }
            }
        }

        // Pass 3: Try closest USDA generic alternative (cache first, then multi-datatype search)
        if (!empty($pass3Queue)) {
            $fbKeyToOrigKeys = [];
            $fbUnique = [];
            foreach ($pass3Queue as $origKey => $fbQuery) {
                $fbKey = $this->normalizeQueryKey($fbQuery);
                $fbKeyToOrigKeys[$fbKey][] = $origKey;
                $fbUnique[$fbKey] = $fbQuery;
            }

            $fbKeys = array_keys($fbUnique);
            $placeholders = implode(',', array_fill(0, count($fbKeys), '?'));
            $stmt = $this->db->prepare("SELECT query_key, fdc_id, description, data_type, nutrients_json FROM usda_food_cache WHERE query_key IN ($placeholders)");
            $stmt->execute($fbKeys);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $decoded = json_decode((string)$row['nutrients_json'], true);
                if (is_array($decoded)) {
                    foreach ($fbKeyToOrigKeys[$row['query_key']] ?? [] as $origKey) {
                        $results[$origKey] = [
                            'fdc_id' => (int)$row['fdc_id'],
                            'description' => $row['description'],
                            'data_type' => $row['data_type'],
                            'per100g' => $decoded,
                        ];
                    }
                }
                unset($fbUnique[$row['query_key']]);
            }

            if (!empty($fbUnique)) {
                $pass3Responses = $this->multiPostUsdaSearch(
                    $fbUnique,
                    ['Foundation', 'SR Legacy', 'Survey (FNDDS)', 'Branded'],
                    12
                );
                foreach ($fbUnique as $fbKey => $fbQuery) {
                    if (!empty($pass3Responses[$fbKey]['rateLimited'])) {
                        $anyRateLimited = true;
                    }
                    $candidate = $this->selectBestCandidate($fbQuery, $pass3Responses[$fbKey]['foods'] ?? []);
                    if ($candidate && $candidate['score'] >= 10.0) {
                        $per100g = $this->extractPer100gNutrients($candidate['food']);
                        $profile = [
                            'fdc_id' => (int)($candidate['food']['fdcId'] ?? 0),
                            'description' => (string)($candidate['food']['description'] ?? $fbQuery),
                            'data_type' => (string)($candidate['food']['dataType'] ?? 'SR Legacy'),
                            'per100g' => $per100g,
                        ];
                        foreach ($fbKeyToOrigKeys[$fbKey] ?? [] as $origKey) {
                            $results[$origKey] = $profile;
                            try {
                                $cacheInsert->execute([
                                    $origKey,
                                    $profile['fdc_id'],
                                    mb_substr($profile['description'], 0, 255),
                                    mb_substr($profile['data_type'], 0, 64),
                                    json_encode($per100g),
                                ]);
                            } catch (\Throwable $e) {
                                error_log('USDA fallback cache write skipped: ' . $e->getMessage());
                            }
                        }
                    }
                }
            }
        }

        return ['profiles' => $results, 'rateLimited' => $anyRateLimited];
    }

    /**
     * Layer 4 fallback: asks Emma (Gemini) for per-100g macro and micronutrient profiles ONLY for
     * ingredients that could not be matched in USDA FoodData Central or its closest-alternative pass.
     */
    private function estimateUnmatchedWithEmma(array $unmatchedItems): array {
        if (empty($unmatchedItems) || $this->geminiApiKey === '') {
            return [];
        }

        $listLines = [];
        foreach ($unmatchedItems as $idx => $item) {
            $listLines[] = sprintf(
                '%d. key="%s" | ingredient="%s"',
                $idx + 1,
                $item['key'],
                $item['original']
            );
        }

        $prompt = "Provide accurate culinary/nutritionist per-100g values (NOT per recipe — strictly per 100 grams of each edible ingredient) for these plant-based ingredients that were absent from USDA searches:\n\n"
            . implode("\n", $listLines);

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'items' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'key' => ['type' => 'STRING'],
                                    'calories' => ['type' => 'NUMBER'],
                                    'protein_g' => ['type' => 'NUMBER'],
                                    'carbs_g' => ['type' => 'NUMBER'],
                                    'fat_g' => ['type' => 'NUMBER'],
                                    'fiber_g' => ['type' => 'NUMBER'],
                                    'dietary_fiber_g' => ['type' => 'NUMBER'],
                                    'sodium_mg' => ['type' => 'NUMBER'],
                                    'saturated_fat_g' => ['type' => 'NUMBER'],
                                    'potassium_mg' => ['type' => 'NUMBER'],
                                    'iron_mg' => ['type' => 'NUMBER'],
                                    'calcium_mg' => ['type' => 'NUMBER'],
                                    'magnesium_mg' => ['type' => 'NUMBER'],
                                    'zinc_mg' => ['type' => 'NUMBER'],
                                    'vitamin_c_mg' => ['type' => 'NUMBER'],
                                    'vitamin_a_mcg' => ['type' => 'NUMBER'],
                                    'folate_mcg' => ['type' => 'NUMBER'],
                                    'vitamin_k_mcg' => ['type' => 'NUMBER'],
                                    'vitamin_b6_mg' => ['type' => 'NUMBER'],
                                    'vitamin_e_mg' => ['type' => 'NUMBER'],
                                    'vitamin_b12_mcg' => ['type' => 'NUMBER'],
                                ],
                                'required' => [
                                    'key', 'calories', 'protein_g', 'carbs_g', 'fat_g', 'fiber_g',
                                    'dietary_fiber_g', 'sodium_mg', 'saturated_fat_g',
                                ],
                            ],
                        ],
                    ],
                    'required' => ['items'],
                ],
            ],
        ];

        $url = self::GEMINI_BASE . '/v1beta/models/' . self::NORMALIZER_MODEL . ':generateContent';
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->geminiApiKey,
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ];
        if ($this->caBundle !== null) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
            return [];
        }

        $decoded = json_decode($raw, true);
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text === '') {
            return [];
        }

        $parsed = json_decode($text, true);
        $rows = $parsed['items'] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        $profiles = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['key'])) {
                continue;
            }
            $k = $this->normalizeQueryKey((string)$row['key']);
            $fiber = max(0.0, (float)($row['dietary_fiber_g'] ?? $row['fiber_g'] ?? 0));

            $micros = [
                'Dietary Fiber' => round($fiber, 4),
                'Sodium' => round(max(0.0, (float)($row['sodium_mg'] ?? 0)), 4),
                'Saturated Fat' => round(max(0.0, (float)($row['saturated_fat_g'] ?? 0)), 4),
                'Potassium' => round(max(0.0, (float)($row['potassium_mg'] ?? 0)), 4),
                'Iron' => round(max(0.0, (float)($row['iron_mg'] ?? 0)), 4),
                'Calcium' => round(max(0.0, (float)($row['calcium_mg'] ?? 0)), 4),
                'Magnesium' => round(max(0.0, (float)($row['magnesium_mg'] ?? 0)), 4),
                'Zinc' => round(max(0.0, (float)($row['zinc_mg'] ?? 0)), 4),
                'Vitamin C' => round(max(0.0, (float)($row['vitamin_c_mg'] ?? 0)), 4),
                'Vitamin A' => round(max(0.0, (float)($row['vitamin_a_mcg'] ?? 0)), 4),
                'Folate' => round(max(0.0, (float)($row['folate_mcg'] ?? 0)), 4),
                'Vitamin K' => round(max(0.0, (float)($row['vitamin_k_mcg'] ?? 0)), 4),
                'Vitamin B6' => round(max(0.0, (float)($row['vitamin_b6_mg'] ?? 0)), 4),
                'Vitamin E' => round(max(0.0, (float)($row['vitamin_e_mg'] ?? 0)), 4),
                'Vitamin B12' => round(max(0.0, (float)($row['vitamin_b12_mcg'] ?? 0)), 4),
            ];

            $profiles[$k] = [
                'fdc_id' => 0,
                'description' => $k . ' (Emma Estimate)',
                'data_type' => 'Emma Estimate',
                'per100g' => [
                    'calories' => round(max(0.0, (float)($row['calories'] ?? 0)), 2),
                    'protein_g' => round(max(0.0, (float)($row['protein_g'] ?? 0)), 3),
                    'carbs_g' => round(max(0.0, (float)($row['carbs_g'] ?? 0)), 3),
                    'fat_g' => round(max(0.0, (float)($row['fat_g'] ?? 0)), 3),
                    'fiber_g' => round($fiber, 3),
                    'micros' => $micros,
                ],
            ];
        }

        return $profiles;
    }

    /**
     * Executes USDA FDC /foods/search POST requests concurrently with curl_multi.
     */
    private function multiPostUsdaSearch(array $queriesByKey, array $dataTypes, int $pageSize): array {
        $url = self::FDC_SEARCH_URL . '?api_key=' . urlencode($this->usdaApiKey);
        $mh = curl_multi_init();
        $handles = [];

        foreach ($queriesByKey as $key => $queryText) {
            $ch = curl_init($url);
            $payload = json_encode([
                'query' => $queryText,
                'dataType' => $dataTypes,
                'pageSize' => $pageSize,
            ]);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 12,
                CURLOPT_CONNECTTIMEOUT => 6,
            ];
            if ($this->caBundle !== null) {
                $opts[CURLOPT_CAINFO] = $this->caBundle;
            }
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 0.5);
            }
        } while ($active && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $key => $ch) {
            $raw = curl_multi_getcontent($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($httpCode === 429) {
                error_log('USDA FDC rate limit hit (HTTP 429) — configure a dedicated USDA_API_KEY in .env.');
                $out[$key] = ['foods' => [], 'rateLimited' => true];
                continue;
            }

            if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
                $out[$key] = ['foods' => [], 'rateLimited' => false];
                continue;
            }

            $decoded = json_decode($raw, true);
            $out[$key] = [
                'foods' => is_array($decoded['foods'] ?? null) ? $decoded['foods'] : [],
                'rateLimited' => false,
            ];
        }

        curl_multi_close($mh);
        return $out;
    }

    /**
     * Word-boundary token matcher supporting plural stems and coriander/cilantro aliasing.
     */
    private function tokenMatchesDesc(string $token, string $desc): bool {
        $candidates = [$token];
        if ($token === 'coriander') {
            $candidates[] = 'cilantro';
        } elseif ($token === 'cilantro') {
            $candidates[] = 'coriander';
        } elseif ($token === 'tamari' && str_contains($desc, 'soy sauce')) {
            $candidates[] = 'soy';
        } elseif ($token === 'soy' && str_contains($desc, 'tamari')) {
            $candidates[] = 'tamari';
        }

        foreach ($candidates as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '(?:e?s)?\b/', $desc)) {
                return true;
            }
            $stem = strlen($word) >= 4 ? rtrim($word, 's') : $word;
            if ($stem !== $word && strlen($stem) >= 3 && preg_match('/\b' . preg_quote($stem, '/') . '(?:e?s)?\b/', $desc)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Scores search candidates to select the closest culinary/nutritional match (e.g. "tofu, extra firm" over "tofu yogurt").
     */
    private function selectBestCandidate(string $queryText, array $foods): ?array {
        if (empty($foods)) {
            return null;
        }

        $qLower = strtolower($queryText);
        $qTokens = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', $qLower),
            fn($t) => strlen($t) >= 2 && !in_array($t, ['with', 'and', 'the', 'for', 'style', 'or'], true)
        ));
        if (empty($qTokens)) {
            return null;
        }

        $descriptors = [
            'raw', 'fresh', 'cooked', 'boiled', 'steamed', 'baked', 'roasted',
            'canned', 'drained', 'solids', 'dry', 'dried', 'ground', 'whole',
            'powder', 'paste', 'extra', 'firm', 'silken', 'soft', 'regular',
            'light', 'lite', 'low', 'reduced', 'sodium', 'free', 'organic',
            'unsalted', 'salted', 'pure', 'natural', 'vegan', 'plant', 'based',
            'gluten', 'made', 'from', 'unsweetened', 'sweetened', 'red', 'green',
            'yellow', 'black', 'white', 'baby', 'small', 'large', 'medium',
            'seed', 'seeds', 'leaf', 'leaves', 'juice', 'spices', 'spice',
            'table', 'sea', 'kosher', 'iodized', 'flakes', 'crushed', 'minced',
        ];

        $coreTokens = array_values(array_filter(
            $qTokens,
            fn($t) => !in_array($t, $descriptors, true)
        ));
        if (empty($coreTokens)) {
            $coreTokens = [$qTokens[0]];
        }

        $penalizeUnlessRequested = [
            'fried', 'deep-fried', 'breaded', 'battered', 'chip', 'chips',
            'cracker', 'crackers', 'candy', 'candied', 'cake', 'cookie',
            'pie', 'ice cream', 'yogurt', 'dessert', 'pudding', 'frosting',
            'sweetened', 'syrup', 'pickled', 'oil', 'butter',
        ];

        $queryWantsFresh = (str_contains($qLower, 'fresh') || str_contains($qLower, 'raw')) && !str_contains($qLower, 'dried');

        $best = null;
        foreach ($foods as $food) {
            if (!is_array($food) || empty($food['description'])) {
                continue;
            }
            $desc = strtolower((string)$food['description']);

            // Hard requirement:
            // - 1 or 2 core nouns (e.g. "parsley", "coconut aminos", "nutritional yeast"): ALL core nouns must match
            //   so "coconut aminos" never falsely matches "Coconut milk".
            // - 3+ core nouns (e.g. "yukon gold potato"): primary core noun + majority must match.
            $coreHits = 0;
            foreach ($coreTokens as $cTok) {
                if ($this->tokenMatchesDesc($cTok, $desc)) {
                    $coreHits++;
                }
            }
            if ($coreHits === 0) {
                continue;
            }
            if (count($coreTokens) <= 2 && $coreHits < count($coreTokens)) {
                continue;
            }
            if (count($coreTokens) >= 3 && $coreHits < ceil(count($coreTokens) * 0.6)) {
                continue;
            }

            $score = 0.0;
            foreach ($coreTokens as $cIdx => $cTok) {
                if ($this->tokenMatchesDesc($cTok, $desc)) {
                    $score += ($cIdx === 0) ? 24.0 : 14.0;
                } else {
                    $score -= 12.0;
                }
            }

            foreach ($qTokens as $tok) {
                if (in_array($tok, $descriptors, true) && $this->tokenMatchesDesc($tok, $desc)) {
                    $score += 6.0;
                }
            }

            // Strong preference when USDA's primary subject (before first comma) contains our primary core noun
            $leadSegment = explode(',', $desc)[0];
            if ($this->tokenMatchesDesc($coreTokens[0], $leadSegment) || $this->tokenMatchesDesc($coreTokens[count($coreTokens) - 1], $leadSegment)) {
                $score += 18.0;
            }

            // Avoid matching dried spices for fresh herbs when a fresh match exists
            if ($queryWantsFresh && preg_match('/\bdried\b/', $desc)) {
                $score -= 18.0;
            }
            // Avoid matching fresh sweet peppers for dried chili/cayenne spices
            if (str_contains($qLower, 'spice') && preg_match('/\braw\b|\bbell\b/', $desc)) {
                $score -= 22.0;
            }

            // Penalize processed/derivative mismatches unless requested
            foreach ($penalizeUnlessRequested as $badWord) {
                if (!str_contains($qLower, $badWord) && preg_match('/\b' . preg_quote($badWord, '/') . '\b/', $desc)) {
                    if ($badWord === 'butter' && preg_match('/tahini|peanut|almond|cashew|nut/', $qLower)) {
                        continue;
                    }
                    $score -= 22.0;
                }
            }

            $dt = (string)($food['dataType'] ?? '');
            if ($dt === 'Foundation') {
                $score += 10.0;
            } elseif ($dt === 'SR Legacy') {
                $score += 9.0;
            } elseif ($dt === 'Survey (FNDDS)') {
                $score += 4.0;
            }

            if ($best === null || $score > $best['score']) {
                $best = ['score' => $score, 'food' => $food];
            }
        }

        return $best;
    }

    /**
     * Extracts normalized per-100g macros and tracked micronutrients from a USDA FDC food object.
     */
    private function extractPer100gNutrients(array $food): array {
        $byId = [];
        foreach (($food['foodNutrients'] ?? []) as $n) {
            if (!is_array($n)) {
                continue;
            }
            $nid = (int)($n['nutrientId'] ?? 0);
            if ($nid <= 0 || !isset($n['value'])) {
                continue;
            }
            $val = (float)$n['value'];
            $unit = strtoupper(trim((string)($n['unitName'] ?? '')));

            if (in_array($nid, [self::NID_ENERGY_KCAL, self::NID_ENERGY_ATWATER_GEN, self::NID_ENERGY_ATWATER_SPEC, self::NID_ENERGY_KJ], true)) {
                if ($unit === 'KJ') {
                    $val /= 4.184;
                }
            }
            $byId[$nid] = $val;
        }

        $calories = $byId[self::NID_ENERGY_KCAL]
            ?? $byId[self::NID_ENERGY_ATWATER_GEN]
            ?? $byId[self::NID_ENERGY_ATWATER_SPEC]
            ?? ($byId[self::NID_ENERGY_KJ] ?? 0.0);

        $micros = [];
        foreach (self::TRACKED_MICROS as $spec) {
            $micros[$spec['name']] = round((float)($byId[$spec['id']] ?? 0.0), 4);
        }

        return [
            'calories' => round((float)$calories, 2),
            'protein_g' => round((float)($byId[self::NID_PROTEIN] ?? 0.0), 3),
            'carbs_g' => round((float)($byId[self::NID_CARBS] ?? 0.0), 3),
            'fat_g' => round((float)($byId[self::NID_FAT] ?? 0.0), 3),
            'fiber_g' => round((float)($byId[self::NID_FIBER] ?? 0.0), 3),
            'micros' => $micros,
        ];
    }

    /**
     * Built-in curated catalog of popular WFPB condiments, sauces, dressings, and quick sides
     * with USDA FoodData Central per-100g nutrition profiles for instant zero-latency search.
     */
    private function getCuratedCondimentCatalog(): array {
        return [
            [
                'fdc_id' => 168556,
                'description' => 'Tomato Ketchup (Catsup)',
                'category' => 'condiment',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 17.0,
                'servingLabel' => '1 tbsp (17g)',
                'per100g' => [
                    'calories' => 101, 'protein_g' => 1.04, 'carbs_g' => 27.4, 'fat_g' => 0.1, 'fiber_g' => 0.3,
                    'micros' => ['Dietary Fiber' => 0.3, 'Sodium' => 907, 'Saturated Fat' => 0.02, 'Potassium' => 281, 'Iron' => 0.36, 'Calcium' => 15, 'Magnesium' => 13, 'Zinc' => 0.17, 'Vitamin C' => 4.1, 'Vitamin A' => 26, 'Folate' => 9, 'Vitamin K' => 3.3, 'Vitamin B6' => 0.11, 'Vitamin E' => 1.46],
                ],
            ],
            [
                'fdc_id' => 172238,
                'description' => 'Yellow Mustard, Prepared',
                'category' => 'condiment',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 5.0,
                'servingLabel' => '1 tsp (5g)',
                'per100g' => [
                    'calories' => 60, 'protein_g' => 3.74, 'carbs_g' => 5.83, 'fat_g' => 3.34, 'fiber_g' => 4.0,
                    'micros' => ['Dietary Fiber' => 4.0, 'Sodium' => 1100, 'Saturated Fat' => 0.18, 'Potassium' => 138, 'Iron' => 1.61, 'Calcium' => 63, 'Magnesium' => 49, 'Zinc' => 0.64, 'Vitamin C' => 1.5, 'Vitamin A' => 4, 'Folate' => 7, 'Vitamin K' => 1.4, 'Vitamin B6' => 0.07, 'Vitamin E' => 0.39],
                ],
            ],
            [
                'fdc_id' => 2710551,
                'description' => 'Dijon Mustard',
                'category' => 'condiment',
                'data_type' => 'Foundation',
                'defaultUnit' => 'tsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 5.0,
                'servingLabel' => '1 tsp (5g)',
                'per100g' => [
                    'calories' => 120, 'protein_g' => 4.5, 'carbs_g' => 8.0, 'fat_g' => 6.0, 'fiber_g' => 3.0,
                    'micros' => ['Dietary Fiber' => 3.0, 'Sodium' => 1800, 'Saturated Fat' => 0.4, 'Potassium' => 150, 'Iron' => 1.8, 'Calcium' => 55, 'Magnesium' => 45, 'Zinc' => 0.6],
                ],
            ],
            [
                'fdc_id' => 171186,
                'description' => 'Sriracha Hot Chili Sauce',
                'category' => 'sauce',
                'data_type' => 'Survey (FNDDS)',
                'defaultUnit' => 'tsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 6.0,
                'servingLabel' => '1 tsp (6g)',
                'per100g' => [
                    'calories' => 93, 'protein_g' => 2.0, 'carbs_g' => 19.0, 'fat_g' => 1.0, 'fiber_g' => 2.0,
                    'micros' => ['Dietary Fiber' => 2.0, 'Sodium' => 2050, 'Saturated Fat' => 0.1, 'Potassium' => 250, 'Iron' => 0.6, 'Calcium' => 18, 'Vitamin C' => 18.0, 'Vitamin A' => 55],
                ],
            ],
            [
                'fdc_id' => 169062,
                'description' => 'Salsa (Red Tomato, Ready-to-Serve)',
                'category' => 'sauce',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 2.0,
                'servingGrams' => 32.0,
                'servingLabel' => '2 tbsp (32g)',
                'per100g' => [
                    'calories' => 29, 'protein_g' => 1.5, 'carbs_g' => 6.6, 'fat_g' => 0.2, 'fiber_g' => 1.8,
                    'micros' => ['Dietary Fiber' => 1.8, 'Sodium' => 430, 'Saturated Fat' => 0.03, 'Potassium' => 291, 'Iron' => 0.47, 'Calcium' => 25, 'Magnesium' => 15, 'Vitamin C' => 4.2, 'Vitamin A' => 25, 'Folate' => 11, 'Vitamin K' => 5.4],
                ],
            ],
            [
                'fdc_id' => 172420,
                'description' => 'Hummus, Traditional Chickpea',
                'category' => 'sauce',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 2.0,
                'servingGrams' => 30.0,
                'servingLabel' => '2 tbsp (30g)',
                'per100g' => [
                    'calories' => 166, 'protein_g' => 7.9, 'carbs_g' => 14.3, 'fat_g' => 9.6, 'fiber_g' => 6.0,
                    'micros' => ['Dietary Fiber' => 6.0, 'Sodium' => 379, 'Saturated Fat' => 1.44, 'Potassium' => 228, 'Iron' => 2.44, 'Calcium' => 38, 'Magnesium' => 71, 'Zinc' => 1.83, 'Folate' => 83, 'Vitamin B6' => 0.2],
                ],
            ],
            [
                'fdc_id' => 170567,
                'description' => 'Guacamole, Classic Avocado Dip',
                'category' => 'side',
                'data_type' => 'Survey (FNDDS)',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 2.0,
                'servingGrams' => 30.0,
                'servingLabel' => '2 tbsp (30g)',
                'per100g' => [
                    'calories' => 157, 'protein_g' => 1.9, 'carbs_g' => 8.5, 'fat_g' => 14.1, 'fiber_g' => 6.6,
                    'micros' => ['Dietary Fiber' => 6.6, 'Sodium' => 315, 'Saturated Fat' => 2.04, 'Potassium' => 473, 'Iron' => 0.56, 'Calcium' => 14, 'Magnesium' => 28, 'Zinc' => 0.62, 'Vitamin C' => 11.4, 'Vitamin A' => 11, 'Folate' => 79, 'Vitamin K' => 20.4, 'Vitamin E' => 1.98, 'Vitamin B6' => 0.25],
                ],
            ],
            [
                'fdc_id' => 170189,
                'description' => 'Tahini (Roasted Sesame Seed Paste)',
                'category' => 'sauce',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 15.0,
                'servingLabel' => '1 tbsp (15g)',
                'per100g' => [
                    'calories' => 595, 'protein_g' => 17.0, 'carbs_g' => 21.2, 'fat_g' => 53.8, 'fiber_g' => 9.3,
                    'micros' => ['Dietary Fiber' => 9.3, 'Sodium' => 115, 'Saturated Fat' => 7.53, 'Potassium' => 414, 'Iron' => 8.95, 'Calcium' => 426, 'Magnesium' => 95, 'Zinc' => 4.62, 'Folate' => 98, 'Vitamin B6' => 0.15, 'Vitamin E' => 0.25],
                ],
            ],
            [
                'fdc_id' => 174277,
                'description' => 'Soy Sauce, Traditional Shoyu',
                'category' => 'condiment',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 16.0,
                'servingLabel' => '1 tbsp (16g)',
                'per100g' => [
                    'calories' => 53, 'protein_g' => 8.14, 'carbs_g' => 4.93, 'fat_g' => 0.57, 'fiber_g' => 0.8,
                    'micros' => ['Dietary Fiber' => 0.8, 'Sodium' => 5493, 'Saturated Fat' => 0.08, 'Potassium' => 435, 'Iron' => 1.45, 'Calcium' => 33, 'Magnesium' => 74, 'Zinc' => 0.87, 'Folate' => 14, 'Vitamin B6' => 0.15],
                ],
            ],
            [
                'fdc_id' => 172447,
                'description' => 'Tamari (Gluten-Free Soy Sauce)',
                'category' => 'condiment',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 18.0,
                'servingLabel' => '1 tbsp (18g)',
                'per100g' => [
                    'calories' => 60, 'protein_g' => 10.5, 'carbs_g' => 5.57, 'fat_g' => 0.1, 'fiber_g' => 0.8,
                    'micros' => ['Dietary Fiber' => 0.8, 'Sodium' => 5586, 'Saturated Fat' => 0.02, 'Potassium' => 212, 'Iron' => 2.38, 'Calcium' => 20, 'Magnesium' => 40, 'Zinc' => 0.43],
                ],
            ],
            [
                'fdc_id' => 2514742,
                'description' => 'Coconut Aminos Seasoning Sauce',
                'category' => 'condiment',
                'data_type' => 'Branded',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 15.0,
                'servingLabel' => '1 tbsp (15g)',
                'per100g' => [
                    'calories' => 100, 'protein_g' => 0.0, 'carbs_g' => 20.0, 'fat_g' => 0.0, 'fiber_g' => 0.0,
                    'micros' => ['Dietary Fiber' => 0.0, 'Sodium' => 1800, 'Saturated Fat' => 0.0, 'Potassium' => 120],
                ],
            ],
            [
                'fdc_id' => 171183,
                'description' => 'Barbecue Sauce (BBQ)',
                'category' => 'sauce',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 2.0,
                'servingGrams' => 34.0,
                'servingLabel' => '2 tbsp (34g)',
                'per100g' => [
                    'calories' => 172, 'protein_g' => 0.8, 'carbs_g' => 40.9, 'fat_g' => 0.56, 'fiber_g' => 0.9,
                    'micros' => ['Dietary Fiber' => 0.9, 'Sodium' => 1027, 'Saturated Fat' => 0.09, 'Potassium' => 232, 'Iron' => 0.52, 'Calcium' => 25, 'Magnesium' => 14, 'Vitamin C' => 1.2, 'Vitamin A' => 13, 'Vitamin E' => 0.81],
                ],
            ],
            [
                'fdc_id' => 175040,
                'description' => 'Nutritional Yeast Flakes',
                'category' => 'condiment',
                'data_type' => 'Branded',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 2.0,
                'servingGrams' => 10.0,
                'servingLabel' => '2 tbsp (10g)',
                'per100g' => [
                    'calories' => 375, 'protein_g' => 50.0, 'carbs_g' => 31.2, 'fat_g' => 3.1, 'fiber_g' => 25.0,
                    'micros' => ['Dietary Fiber' => 25.0, 'Sodium' => 156, 'Saturated Fat' => 0.0, 'Potassium' => 2100, 'Iron' => 5.6, 'Calcium' => 0, 'Zinc' => 25.0, 'Folate' => 2400, 'Vitamin B6' => 15.0, 'Vitamin B12' => 48.0],
                ],
            ],
            [
                'fdc_id' => 169997,
                'description' => 'Pure Maple Syrup',
                'category' => 'condiment',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 20.0,
                'servingLabel' => '1 tbsp (20g)',
                'per100g' => [
                    'calories' => 260, 'protein_g' => 0.04, 'carbs_g' => 67.0, 'fat_g' => 0.06, 'fiber_g' => 0.0,
                    'micros' => ['Dietary Fiber' => 0.0, 'Sodium' => 12, 'Saturated Fat' => 0.01, 'Potassium' => 212, 'Iron' => 0.11, 'Calcium' => 102, 'Magnesium' => 21, 'Zinc' => 1.47],
                ],
            ],
            [
                'fdc_id' => 173717,
                'description' => 'Balsamic Vinegar',
                'category' => 'condiment',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 16.0,
                'servingLabel' => '1 tbsp (16g)',
                'per100g' => [
                    'calories' => 88, 'protein_g' => 0.49, 'carbs_g' => 17.03, 'fat_g' => 0.0, 'fiber_g' => 0.0,
                    'micros' => ['Dietary Fiber' => 0.0, 'Sodium' => 23, 'Saturated Fat' => 0.0, 'Potassium' => 112, 'Iron' => 0.72, 'Calcium' => 27, 'Magnesium' => 12],
                ],
            ],
            [
                'fdc_id' => 169279,
                'description' => 'Sauerkraut, Canned Solids & Liquids',
                'category' => 'side',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'cup',
                'defaultAmount' => 0.5,
                'servingGrams' => 71.0,
                'servingLabel' => '1/2 cup (71g)',
                'per100g' => [
                    'calories' => 19, 'protein_g' => 0.91, 'carbs_g' => 4.28, 'fat_g' => 0.14, 'fiber_g' => 2.9,
                    'micros' => ['Dietary Fiber' => 2.9, 'Sodium' => 661, 'Saturated Fat' => 0.03, 'Potassium' => 170, 'Iron' => 1.47, 'Calcium' => 30, 'Magnesium' => 13, 'Zinc' => 0.19, 'Vitamin C' => 14.7, 'Folate' => 24, 'Vitamin K' => 13.0, 'Vitamin B6' => 0.13],
                ],
            ],
            [
                'fdc_id' => 170198,
                'description' => 'Hot Chili Pepper Sauce (Tabasco / Cayenne)',
                'category' => 'condiment',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 5.0,
                'servingLabel' => '1 tsp (5g)',
                'per100g' => [
                    'calories' => 11, 'protein_g' => 0.52, 'carbs_g' => 1.81, 'fat_g' => 0.42, 'fiber_g' => 0.6,
                    'micros' => ['Dietary Fiber' => 0.6, 'Sodium' => 2643, 'Saturated Fat' => 0.07, 'Potassium' => 144, 'Iron' => 0.36, 'Calcium' => 8, 'Magnesium' => 9, 'Vitamin C' => 74.8, 'Vitamin A' => 26, 'Vitamin K' => 6.0],
                ],
            ],
            [
                'fdc_id' => 172231,
                'description' => 'Marinara Pasta Sauce (Fat-Free / Traditional)',
                'category' => 'sauce',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'cup',
                'defaultAmount' => 0.5,
                'servingGrams' => 125.0,
                'servingLabel' => '1/2 cup (125g)',
                'per100g' => [
                    'calories' => 45, 'protein_g' => 1.4, 'carbs_g' => 8.2, 'fat_g' => 0.6, 'fiber_g' => 1.7,
                    'micros' => ['Dietary Fiber' => 1.7, 'Sodium' => 390, 'Saturated Fat' => 0.08, 'Potassium' => 320, 'Iron' => 0.85, 'Calcium' => 24, 'Magnesium' => 18, 'Vitamin C' => 7.5, 'Vitamin A' => 28, 'Vitamin K' => 5.2],
                ],
            ],
            [
                'fdc_id' => 171187,
                'description' => 'Sweet Chili Dipping Sauce',
                'category' => 'sauce',
                'data_type' => 'Survey (FNDDS)',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 2.0,
                'servingGrams' => 32.0,
                'servingLabel' => '2 tbsp (32g)',
                'per100g' => [
                    'calories' => 150, 'protein_g' => 0.5, 'carbs_g' => 36.0, 'fat_g' => 0.2, 'fiber_g' => 0.5,
                    'micros' => ['Dietary Fiber' => 0.5, 'Sodium' => 720, 'Saturated Fat' => 0.0, 'Potassium' => 65, 'Vitamin C' => 3.5],
                ],
            ],
            [
                'fdc_id' => 169385,
                'description' => 'Dill Pickles or Dill Relish',
                'category' => 'condiment',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 15.0,
                'servingLabel' => '1 tbsp (15g)',
                'per100g' => [
                    'calories' => 12, 'protein_g' => 0.48, 'carbs_g' => 1.99, 'fat_g' => 0.43, 'fiber_g' => 1.0,
                    'micros' => ['Dietary Fiber' => 1.0, 'Sodium' => 809, 'Saturated Fat' => 0.11, 'Potassium' => 112, 'Iron' => 0.25, 'Calcium' => 54, 'Magnesium' => 7, 'Vitamin K' => 16.8],
                ],
            ],
            [
                'fdc_id' => 171705,
                'description' => 'Fresh Avocado Slices (Side / Topping)',
                'category' => 'side',
                'data_type' => 'Foundation',
                'defaultUnit' => 'serving',
                'defaultAmount' => 1.0,
                'servingGrams' => 50.0,
                'servingLabel' => '1/3 medium (50g)',
                'per100g' => [
                    'calories' => 160, 'protein_g' => 2.0, 'carbs_g' => 8.53, 'fat_g' => 14.66, 'fiber_g' => 6.7,
                    'micros' => ['Dietary Fiber' => 6.7, 'Sodium' => 7, 'Saturated Fat' => 2.13, 'Potassium' => 485, 'Iron' => 0.55, 'Calcium' => 12, 'Magnesium' => 29, 'Zinc' => 0.64, 'Vitamin C' => 10.0, 'Folate' => 81, 'Vitamin K' => 21.0, 'Vitamin E' => 2.07, 'Vitamin B6' => 0.26],
                ],
            ],
            [
                'fdc_id' => 170379,
                'description' => 'Steamed Broccoli Florets (Side)',
                'category' => 'side',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'cup',
                'defaultAmount' => 1.0,
                'servingGrams' => 156.0,
                'servingLabel' => '1 cup (156g)',
                'per100g' => [
                    'calories' => 35, 'protein_g' => 2.38, 'carbs_g' => 7.18, 'fat_g' => 0.41, 'fiber_g' => 3.3,
                    'micros' => ['Dietary Fiber' => 3.3, 'Sodium' => 41, 'Saturated Fat' => 0.08, 'Potassium' => 293, 'Iron' => 0.67, 'Calcium' => 40, 'Magnesium' => 21, 'Zinc' => 0.45, 'Vitamin C' => 64.9, 'Vitamin A' => 77, 'Folate' => 108, 'Vitamin K' => 141.1, 'Vitamin E' => 1.45, 'Vitamin B6' => 0.2],
                ],
            ],
            [
                'fdc_id' => 169704,
                'description' => 'Cooked Brown Rice (Side)',
                'category' => 'side',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'cup',
                'defaultAmount' => 0.5,
                'servingGrams' => 98.0,
                'servingLabel' => '1/2 cup (98g)',
                'per100g' => [
                    'calories' => 123, 'protein_g' => 2.74, 'carbs_g' => 25.58, 'fat_g' => 0.97, 'fiber_g' => 1.6,
                    'micros' => ['Dietary Fiber' => 1.6, 'Sodium' => 4, 'Saturated Fat' => 0.26, 'Potassium' => 86, 'Iron' => 0.56, 'Calcium' => 3, 'Magnesium' => 39, 'Zinc' => 0.71, 'Folate' => 9, 'Vitamin B6' => 0.12],
                ],
            ],
            [
                'fdc_id' => 168917,
                'description' => 'Cooked Quinoa (Side)',
                'category' => 'side',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'cup',
                'defaultAmount' => 0.5,
                'servingGrams' => 93.0,
                'servingLabel' => '1/2 cup (93g)',
                'per100g' => [
                    'calories' => 120, 'protein_g' => 4.4, 'carbs_g' => 21.3, 'fat_g' => 1.92, 'fiber_g' => 2.8,
                    'micros' => ['Dietary Fiber' => 2.8, 'Sodium' => 7, 'Saturated Fat' => 0.23, 'Potassium' => 172, 'Iron' => 1.49, 'Calcium' => 17, 'Magnesium' => 64, 'Zinc' => 1.09, 'Folate' => 42, 'Vitamin B6' => 0.12, 'Vitamin E' => 0.63],
                ],
            ],
            [
                'fdc_id' => 173470,
                'description' => 'Apple Cider Vinegar',
                'category' => 'condiment',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 15.0,
                'servingLabel' => '1 tbsp (15g)',
                'per100g' => [
                    'calories' => 21, 'protein_g' => 0.0, 'carbs_g' => 0.93, 'fat_g' => 0.0, 'fiber_g' => 0.0,
                    'micros' => ['Dietary Fiber' => 0.0, 'Sodium' => 5, 'Potassium' => 73, 'Iron' => 0.2, 'Calcium' => 7, 'Magnesium' => 5],
                ],
            ],
            [
                'fdc_id' => 172185,
                'description' => 'Teriyaki Sauce, Plant-Based',
                'category' => 'sauce',
                'data_type' => 'SR Legacy',
                'defaultUnit' => 'tbsp',
                'defaultAmount' => 1.0,
                'servingGrams' => 18.0,
                'servingLabel' => '1 tbsp (18g)',
                'per100g' => [
                    'calories' => 89, 'protein_g' => 5.93, 'carbs_g' => 15.56, 'fat_g' => 0.0, 'fiber_g' => 0.1,
                    'micros' => ['Dietary Fiber' => 0.1, 'Sodium' => 3833, 'Potassium' => 225, 'Iron' => 1.72, 'Calcium' => 28, 'Magnesium' => 61],
                ],
            ],
            [
                'fdc_id' => 171234,
                'description' => 'Kimchi, Vegan Fermented Napa Cabbage',
                'category' => 'side',
                'data_type' => 'Survey (FNDDS)',
                'defaultUnit' => 'cup',
                'defaultAmount' => 0.5,
                'servingGrams' => 75.0,
                'servingLabel' => '1/2 cup (75g)',
                'per100g' => [
                    'calories' => 15, 'protein_g' => 1.1, 'carbs_g' => 2.4, 'fat_g' => 0.5, 'fiber_g' => 1.6,
                    'micros' => ['Dietary Fiber' => 1.6, 'Sodium' => 498, 'Saturated Fat' => 0.07, 'Potassium' => 151, 'Iron' => 2.5, 'Calcium' => 33, 'Magnesium' => 14, 'Vitamin C' => 18.0, 'Vitamin K' => 43.6, 'Folate' => 52],
                ],
            ],
        ];
    }

    /**
     * Converts a per100g food profile into a standardized search item with unit micros.
     */
    private function formatUsdaSearchItem(array $profile, string $category = 'condiment'): array {
        $per100g = $profile['per100g'] ?? [];
        $unitMicros = [];
        foreach (self::TRACKED_MICROS as $spec) {
            $mName = $spec['name'];
            $val = round((float)($per100g['micros'][$mName] ?? 0.0), 3);
            $unitMicros[] = [
                'nutrient_name' => $mName,
                'name' => $mName,
                'amount' => $val,
                'unit' => $spec['unit'],
                'dvTarget' => $spec['dv'],
                'daily_value_percentage' => $spec['dv'] > 0 ? round(($val / $spec['dv']) * 100, 1) : 0.0,
            ];
        }

        $grams = (float)($profile['servingGrams'] ?? 15.0);
        if ($grams <= 0) {
            $grams = 15.0;
        }
        $scale = $grams / 100.0;

        return [
            'fdc_id' => (int)($profile['fdc_id'] ?? 0),
            'query_key' => $this->normalizeQueryKey((string)($profile['description'] ?? '')),
            'description' => (string)($profile['description'] ?? 'USDA Food Item'),
            'category' => $category,
            'data_type' => (string)($profile['data_type'] ?? 'USDA'),
            'defaultUnit' => (string)($profile['defaultUnit'] ?? 'tbsp'),
            'defaultAmount' => (float)($profile['defaultAmount'] ?? 1.0),
            'servingGrams' => round($grams, 2),
            'servingLabel' => (string)($profile['servingLabel'] ?? '1 tbsp (15g)'),
            'per100g' => [
                'calories' => round((float)($per100g['calories'] ?? 0), 2),
                'protein_g' => round((float)($per100g['protein_g'] ?? 0), 2),
                'carbs_g' => round((float)($per100g['carbs_g'] ?? 0), 2),
                'fat_g' => round((float)($per100g['fat_g'] ?? 0), 2),
                'fiber_g' => round((float)($per100g['fiber_g'] ?? 0), 2),
                'micros' => $per100g['micros'] ?? [],
                'microsList' => $unitMicros,
            ],
            'sampleCalories' => (int)round(((float)($per100g['calories'] ?? 0)) * $scale),
        ];
    }

    /**
     * Searches curated condiments, local USDA cache, and live USDA FoodData Central API
     * for condiments, sauces, dressings, and side items.
     */
    public function searchCondimentsAndFoods(string $query, int $limit = 12): array {
        $trimmed = trim($query);
        $curated = $this->getCuratedCondimentCatalog();

        if ($trimmed === '') {
            return array_map(
                fn($c) => $this->formatUsdaSearchItem($c, $c['category'] ?? 'condiment'),
                array_slice($curated, 0, $limit)
            );
        }

        $qLower = mb_strtolower($trimmed);
        $results = [];
        $seenDescs = [];

        // 1. Check curated quick-condiment catalog first
        foreach ($curated as $item) {
            $descLower = mb_strtolower($item['description']);
            if (str_contains($descLower, $qLower)) {
                $seenDescs[$descLower] = true;
                $results[] = $this->formatUsdaSearchItem($item, $item['category'] ?? 'condiment');
            }
        }

        // 2. Check cached USDA lookups
        $this->ensureCacheTable();
        $cacheStmt = $this->db->prepare("
            SELECT query_key, fdc_id, description, data_type, nutrients_json
            FROM usda_food_cache
            WHERE LOWER(query_key) LIKE ? OR LOWER(description) LIKE ?
            ORDER BY CASE WHEN LOWER(query_key) = ? THEN 0 ELSE 1 END, LENGTH(description) ASC
            LIMIT 8
        ");
        $like = '%' . $qLower . '%';
        $cacheStmt->execute([$like, $like, $qLower]);
        foreach ($cacheStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = json_decode((string)$row['nutrients_json'], true);
            $descLower = mb_strtolower((string)$row['description']);
            if (is_array($decoded) && !isset($seenDescs[$descLower])) {
                $seenDescs[$descLower] = true;
                $results[] = $this->formatUsdaSearchItem([
                    'fdc_id' => (int)$row['fdc_id'],
                    'description' => $row['description'],
                    'data_type' => $row['data_type'],
                    'defaultUnit' => 'tbsp',
                    'defaultAmount' => 1.0,
                    'servingGrams' => 15.0,
                    'servingLabel' => '1 tbsp (15g)',
                    'per100g' => $decoded,
                ], 'condiment');
            }
        }

        // 3. Query live USDA FoodData Central for richer/specific matches
        if (count($results) < $limit) {
            $apiResp = $this->multiPostUsdaSearch(
                ['q' => $trimmed],
                ['Foundation', 'SR Legacy', 'Survey (FNDDS)', 'Branded'],
                15
            );
            $foods = $apiResp['q']['foods'] ?? [];
            $cacheInsert = $this->db->prepare("
                INSERT INTO usda_food_cache (query_key, fdc_id, description, data_type, nutrients_json)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    fdc_id = VALUES(fdc_id),
                    description = VALUES(description),
                    data_type = VALUES(data_type),
                    nutrients_json = VALUES(nutrients_json)
            ");

            foreach ($foods as $food) {
                if (count($results) >= $limit) {
                    break;
                }
                $desc = trim((string)($food['description'] ?? ''));
                if ($desc === '') {
                    continue;
                }
                $descLower = mb_strtolower($desc);
                if (isset($seenDescs[$descLower])) {
                    continue;
                }
                $seenDescs[$descLower] = true;
                $per100g = $this->extractPer100gNutrients($food);

                $servingGrams = 15.0;
                $defaultUnit = 'tbsp';
                $defaultAmount = 1.0;
                $servingLabel = '1 tbsp (15g)';
                $household = trim((string)($food['householdServingFullText'] ?? ''));
                $rawServingSize = (float)($food['servingSize'] ?? 0);
                $rawServingUnit = strtolower(trim((string)($food['servingSizeUnit'] ?? '')));
                if ($rawServingSize > 0 && in_array($rawServingUnit, ['g', 'ml'], true)) {
                    $servingGrams = $rawServingSize;
                    if ($household !== '') {
                        $servingLabel = $household . ' (' . round($servingGrams) . 'g)';
                    } else {
                        $servingLabel = '1 serving (' . round($servingGrams) . 'g)';
                    }
                    $defaultUnit = 'serving';
                }

                $formatted = $this->formatUsdaSearchItem([
                    'fdc_id' => (int)($food['fdcId'] ?? 0),
                    'description' => $desc,
                    'data_type' => (string)($food['dataType'] ?? 'USDA'),
                    'defaultUnit' => $defaultUnit,
                    'defaultAmount' => $defaultAmount,
                    'servingGrams' => $servingGrams,
                    'servingLabel' => $servingLabel,
                    'per100g' => $per100g,
                ], 'condiment');
                $results[] = $formatted;

                try {
                    $qKey = $this->normalizeQueryKey($desc);
                    if ($qKey !== '') {
                        $cacheInsert->execute([
                            $qKey,
                            $formatted['fdc_id'],
                            mb_substr($desc, 0, 255),
                            mb_substr($formatted['data_type'], 0, 64),
                            json_encode($per100g),
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Non-fatal cache write
                }
            }
        }

        // 4. Fallback to Emma nutrition estimate if USDA returned 0 matches (cached for instant reuse)
        if (empty($results) && $this->geminiApiKey !== '') {
            $qKey = $this->normalizeQueryKey($trimmed);
            $emmaProfiles = $this->estimateUnmatchedWithEmma([[
                'key' => $qKey,
                'original' => $trimmed,
                'label' => $trimmed,
            ]]);
            if (!empty($emmaProfiles[$qKey]['per100g'])) {
                $descLabel = ucwords($trimmed) . ' (Emma Nutrition Estimate)';
                $per100 = $emmaProfiles[$qKey]['per100g'];
                $results[] = $this->formatUsdaSearchItem([
                    'fdc_id' => 0,
                    'description' => $descLabel,
                    'data_type' => 'Emma Estimate',
                    'defaultUnit' => 'tbsp',
                    'defaultAmount' => 1.0,
                    'servingGrams' => 15.0,
                    'servingLabel' => '1 tbsp (15g)',
                    'per100g' => $per100,
                ], 'condiment');
                try {
                    if ($qKey !== '') {
                        $cacheStmtIns = $this->db->prepare("
                            INSERT INTO usda_food_cache (query_key, fdc_id, description, data_type, nutrients_json)
                            VALUES (?, 0, ?, 'Emma Estimate', ?)
                            ON DUPLICATE KEY UPDATE
                                description = VALUES(description),
                                data_type = VALUES(data_type),
                                nutrients_json = VALUES(nutrients_json)
                        ");
                        $cacheStmtIns->execute([$qKey, mb_substr($descLabel, 0, 255), json_encode($per100)]);
                    }
                } catch (\Throwable $e) {
                    // Non-fatal
                }
            }
        }

        return array_slice($results, 0, $limit);
    }
}
