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
     * @return array
     */
    public function calculateRecipeNutrition(array $rawIngredients, string $yields = '4 servings'): array {
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

        // Deduplicate queries and fetch per-100g profiles (cache first, parallel FDC search for misses)
        $uniqueQueries = [];
        foreach ($measurable as $item) {
            $key = $this->normalizeQueryKey($item['usda_query']);
            if ($key !== '' && !isset($uniqueQueries[$key])) {
                $uniqueQueries[$key] = $item['usda_query'];
            }
        }

        $batchResult = $this->lookupFoodsBatch($uniqueQueries);
        $foodProfiles = $batchResult['profiles'] ?? [];
        $rateLimited = !empty($batchResult['rateLimited']);

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

            foreach (self::TRACKED_MICROS as $spec) {
                $mName = $spec['name'];
                $mVal = (float)($p['micros'][$mName] ?? 0.0) * $factor;
                $microTotals[$mName] += $mVal;
                if ($mVal > 0.01) {
                    $microTopContributors[$mName][] = [
                        'label' => $item['usda_query'],
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

        // Build top contributor summary for audit transparency in NutritionPanel
        $matchedRows = array_values(array_filter($breakdown, fn($b) => !empty($b['matched'])));
        usort($matchedRows, fn($a, $b) => ($b['calories'] <=> $a['calories']));
        $topSummaryParts = array_map(
            fn($r) => sprintf(
                '%sg %s (%sg C / %sg F / %sg P per srv)',
                $r['grams'],
                $r['usda_query'],
                $r['carbs_g'],
                $r['fat_g'],
                $r['protein_g']
            ),
            array_slice($matchedRows, 0, 5)
        );
        $servingsLabel = (abs($servings - round($servings)) < 0.01)
            ? (string)(int)round($servings)
            : (string)round($servings, 1);

        $mathSummary = sprintf(
            'USDA FoodData Central (%s %s, %d/%d ingredients matched): %s',
            $servingsLabel,
            $servings == 1.0 ? 'serving' : 'servings',
            $matchedCount,
            $totalMeasurable,
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

        return [
            'success' => true,
            'source' => 'usda_fdc',
            'usingDemoKey' => $this->usingDemoKey,
            'servings' => $servings,
            'matchedCount' => $matchedCount,
            'totalIngredients' => $totalMeasurable,
            'macros' => [
                'calories' => $perServingCals,
                'protein_g' => $perServingPro,
                'carbs_g' => $perServingCarb,
                'fat_g' => $perServingFat,
                'fiber_g' => $perServingFib,
                'math_calculations' => $mathSummary,
            ],
            'micros' => $finalMicros,
            'breakdown' => $breakdown,
        ];
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

        $prompt = "Convert each recipe ingredient line below into a clean generic USDA FoodData Central search query and an accurate metric weight in grams for the ENTIRE batch quantity stated in the line.\n\n"
            . "CRITICAL CONVERSION RULES:\n"
            . "1. Exact weight conversions: 1 oz = 28.35g (e.g. 14 oz tofu = 397g; 14-16 oz = 425g), 1 lb = 453.6g, 1 kg = 1000g.\n"
            . "2. Match raw vs cooked vs dry to how the ingredient is measured (e.g. '1 cup cooked chickpeas' -> usda_query 'chickpeas, cooked', grams 164; '1 can (15 oz) chickpeas, drained' -> 'chickpeas, canned, drained solids', grams 240; '8 oz dry rice noodles' -> 'rice noodles, dry', grams 227; '1 cup light coconut milk' -> 'coconut milk, canned, light', grams 240).\n"
            . "3. Preserve fat/firmness/type modifiers in usda_query: 'tofu, extra firm', 'tofu, firm', 'tofu, silken', 'tempeh', 'tahini, sesame butter', 'coconut milk, light', 'nutritional yeast', 'soy sauce'. Strip prep verbs (pressed, diced, minced, smashed, charred).\n"
            . "4. If 'A or B' appears, calculate using A only.\n"
            . "5. Common volume/piece weights: 1 tbsp olive/sesame/other = ~14g, 1 tbsp herbs chopped = ~4g, 1 tbsp lemon/lime juice/vinegar/soy sauce/tamari = 15g, 1 tbsp tomato paste = 16g, 1 tbsp tahini/nut butter = 16g, 1 clove garlic = 3g, 1 medium potato = 170g, 1 lb baby potatoes = 454g, 1 cup raw leafy greens/herbs = 30g, 1 cup brussels sprouts = 88g.\n"
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
                                    'grams' => ['type' => 'NUMBER'],
                                ],
                                'required' => ['original', 'usda_query', 'grams'],
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
            $grams = max(0.0, (float)($it['grams'] ?? 0));
            if ($orig !== '' && $query !== '') {
                $normalized[] = [
                    'original' => $orig,
                    'usda_query' => $query,
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
            'grams' => round($grams, 1),
        ];
    }

    private function normalizeQueryKey(string $query): string {
        $q = mb_strtolower(trim($query));
        $q = preg_replace('/\s+/', ' ', $q);
        return mb_substr((string)$q, 0, 191);
    }

    /**
     * Checks local cache first, then batch-fetches uncached queries from USDA FDC in parallel.
     */
    private function lookupFoodsBatch(array $uniqueQueries): array {
        $this->ensureCacheTable();
        $results = [];
        $misses = [];

        $keys = array_keys($uniqueQueries);
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

        foreach ($uniqueQueries as $key => $queryText) {
            if (!isset($results[$key])) {
                $misses[$key] = $queryText;
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
            if ($candidate && $candidate['score'] >= 12.0) {
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

        // Pass 2: Fallback to Branded only for items without a valid Pass 1 hit
        $pass2Responses = !empty($pass2Queue)
            ? $this->multiPostUsdaSearch($pass2Queue, ['Branded'], 8)
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
            }
        }

        return ['profiles' => $results, 'rateLimited' => $anyRateLimited];
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
            'light', 'lite', 'unsweetened', 'sweetened', 'red', 'green',
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

            // Hard requirement: primary core noun (and when multiple core nouns exist, at least one primary core noun)
            // must match on a word boundary so "parsley, raw" never matches "Beets, raw" or "cumin seed" -> "Flaxseed".
            $coreHits = 0;
            foreach ($coreTokens as $cTok) {
                if ($this->tokenMatchesDesc($cTok, $desc)) {
                    $coreHits++;
                }
            }
            if ($coreHits === 0) {
                continue;
            }
            if (count($coreTokens) === 1 && !$this->tokenMatchesDesc($coreTokens[0], $desc)) {
                continue;
            }
            if (count($coreTokens) >= 2 && !$this->tokenMatchesDesc($coreTokens[count($coreTokens) - 1], $desc) && !$this->tokenMatchesDesc($coreTokens[0], $desc)) {
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
}
