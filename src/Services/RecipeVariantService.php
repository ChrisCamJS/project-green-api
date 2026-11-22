<?php

namespace App\Services;

use App\Database;
use PDO;

/**
 * Handles Recipe Variant ("Forking") schema assurance, anti-clout-stealing validation,
 * Gemini structured JSON diff generation, and AI variant image generation.
 */
class RecipeVariantService {
    private const GEMINI_BASE = 'https://generativelanguage.googleapis.com';
    private const CHAT_MODEL = 'gemini-3.5-flash-lite';
    private const IMAGE_MODEL = 'gemini-3.1-flash-lite-image';

    private static bool $schemaChecked = false;
    private PDO $db;
    private string $geminiApiKey;
    private ?string $caBundle;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::connect();
        $env = Database::loadEnv();
        $this->geminiApiKey = trim((string)($env['GEMINI_API_KEY'] ?? ''));
        $this->caBundle = $this->resolveCaBundlePath();
    }

    /**
     * Ensures variant columns exist on `recipes` and `users.generation_tokens` is DECIMAL(10,2).
     */
    public static function ensureSchema(PDO $db): void {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $cols = $db->query("SHOW COLUMNS FROM recipes")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('parent_recipe_id', $cols, true)) {
                $db->exec("ALTER TABLE recipes ADD COLUMN parent_recipe_id INT NULL DEFAULT NULL AFTER user_id");
                $db->exec("ALTER TABLE recipes ADD INDEX idx_recipes_parent_id (parent_recipe_id)");
            }
            if (!in_array('root_recipe_id', $cols, true)) {
                $db->exec("ALTER TABLE recipes ADD COLUMN root_recipe_id INT NULL DEFAULT NULL AFTER parent_recipe_id");
                $db->exec("ALTER TABLE recipes ADD INDEX idx_recipes_root_id (root_recipe_id)");
            }
            if (!in_array('is_masterpiece', $cols, true)) {
                $db->exec("ALTER TABLE recipes ADD COLUMN is_masterpiece TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public");
            }
            if (!in_array('variant_summary', $cols, true)) {
                $db->exec("ALTER TABLE recipes ADD COLUMN variant_summary VARCHAR(255) NULL DEFAULT NULL AFTER description");
            }
        } catch (\Throwable $e) {
            error_log('RecipeVariantService schema check warning: ' . $e->getMessage());
        }
    }

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
     * Quick pre-flight check to reject empty or obviously trivial modification strings before calling Gemini.
     */
    public function validateMeaningfulRequestText(string $changesRequested): ?string {
        $clean = trim(preg_replace('/\s+/', ' ', $changesRequested));
        if (mb_strlen($clean) < 8) {
            return "Please describe what you're changing in a bit more detail (e.g., ingredient swaps, spice levels, or cooking method changes).";
        }

        $words = array_values(array_filter(
            preg_split('/[^a-z0-9]+/i', strtolower($clean)),
            fn($w) => strlen($w) >= 2
        ));
        if (count($words) < 2) {
            return "Please specify at least one concrete ingredient, proportion, or method change for your variant.";
        }

        $trivialPatterns = [
            '/^(none|nothing|no\s+changes?(?:\s+at\s+all)?|same(?:\s+recipe)?|exact\s+same(?:\s+recipe)?|keep\s+it(?:\s+the)?\s+same|just\s+testing|test(?:\s+recipe)?|copy(?:\s+recipe)?|idk|n\/a|na)$/i',
            '/^(change|rename|update|edit)\s+(?:only\s+)?(?:the\s+)?(?:recipe\s+)?(?:title|name)(?:\s+only)?$/i',
            '/^rename\s+to\s+.+$/i',
        ];
        foreach ($trivialPatterns as $pattern) {
            if (preg_match($pattern, $clean)) {
                return "Cheeky! A variant needs a real culinary twist—tell Emma what ingredients, flavours, or methods you're actually changing.";
            }
        }

        return null;
    }

    /**
     * Calls Gemini with structured JSON output to fork the parent recipe according to the user's changes.
     */
    public function generateVariantDiff(
        array $parentRecipe,
        string $changesRequested,
        string $customTitle = '',
        bool $includeMicros = true,
        string $userAllergies = ''
    ): array {
        if ($this->geminiApiKey === '') {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'AI service is not configured on the server.',
            ];
        }

        $parentTitle = (string)($parentRecipe['title'] ?? 'Original Recipe');
        $parentDesc = (string)($parentRecipe['description'] ?? '');
        $parentYields = (string)($parentRecipe['yields'] ?? '4 servings');
        $parentPrep = (int)($parentRecipe['prep_time_mins'] ?? 15);
        $parentCook = (int)($parentRecipe['cook_time_mins'] ?? 20);
        $parentOilFree = !empty($parentRecipe['is_oil_free']) ? 'Yes' : 'No';
        $parentGlutenFree = !empty($parentRecipe['is_gluten_free']) ? 'Yes' : 'No';
        $parentSugarFree = !empty($parentRecipe['is_sugar_free']) ? 'Yes' : 'No';

        $parentIngredientsLines = [];
        foreach (($parentRecipe['ingredients'] ?? []) as $ing) {
            if (is_array($ing)) {
                $qty = (float)($ing['quantity'] ?? 0);
                $unit = trim((string)($ing['unit'] ?? ''));
                $name = trim((string)($ing['ingredient_name'] ?? ''));
                if ($qty > 0 && strtolower($unit) !== 'serving' && !preg_match('/^\d/', $name)) {
                    $parentIngredientsLines[] = trim($qty . ($unit !== '' ? ' ' . $unit : '') . ' ' . $name);
                } else {
                    $parentIngredientsLines[] = $name;
                }
            } else {
                $parentIngredientsLines[] = trim((string)$ing);
            }
        }
        $parentIngredientsLines = array_values(array_filter($parentIngredientsLines));

        $parentInstructionsLines = [];
        foreach (($parentRecipe['instructions'] ?? []) as $idx => $step) {
            $text = is_array($step) ? trim((string)($step['instruction_text'] ?? '')) : trim((string)$step);
            if ($text !== '') {
                $parentInstructionsLines[] = ($idx + 1) . '. ' . $text;
            }
        }

        $allergyClause = trim($userAllergies) !== ''
            ? "\nCRITICAL ALLERGY RESTRICTION: The user has saved allergies: {$userAllergies}. Strictly exclude these allergens and any derivatives."
            : '';

        $titleInstruction = trim($customTitle) !== ''
            ? "The user suggested the variant title: \"{$customTitle}\". Use this title (cleaned of any outer brackets) unless it completely misrepresents the dish."
            : "Create an appetizing, descriptive variant title based on \"{$parentTitle}\" that highlights the twist (never wrap in square brackets).";

        $microsInstruction = $includeMicros
            ? "Because include_micros is TRUE (Masterpiece Variant), write a witty 1-2 paragraph nutritional `deep_dive` (never mention any username) AND populate `micros` with 13 micronutrients per serving: `Dietary Fiber` (g, 28g DV), `Sodium` (mg, 2300mg DV), and `Saturated Fat` (g, 20g DV) MUST be the first 3 items, followed by 10 key vitamins/minerals."
            : "Because include_micros is FALSE, set `deep_dive` to an empty string \"\" and `micros` to an empty array [].";

        $prompt = "You are Emma Advanced, a brilliant British Whole-Food Plant-Based culinary scientist for The Veggie Vault.\n"
            . "A user is creating a **Recipe Variant** (fork/remix) of an existing community recipe.\n\n"
            . "ORIGINAL PARENT RECIPE:\n"
            . "- Title: {$parentTitle}\n"
            . "- Description: {$parentDesc}\n"
            . "- Yields: {$parentYields}\n"
            . "- Prep Time: {$parentPrep} mins | Cook Time: {$parentCook} mins\n"
            . "- Dietary Flags: Oil-Free={$parentOilFree}, Gluten-Free={$parentGlutenFree}, Refined Sugar-Free={$parentSugarFree}\n"
            . "- Original Ingredients:\n  * " . implode("\n  * ", $parentIngredientsLines) . "\n"
            . "- Original Instructions:\n  " . implode("\n  ", $parentInstructionsLines) . "\n\n"
            . "USER'S REQUESTED MODIFICATIONS FOR THIS VARIANT:\n"
            . "\"{$changesRequested}\"\n\n"
            . "YOUR RULES:\n"
            . "1. ANTI-CLOUT-STEALING GUARDRAIL: Evaluate whether the user's request introduces a genuine culinary change (e.g., swapping/adding/removing ingredients, changing spice/flavor profile, scaling proportions meaningfully, adapting for oil-free/gluten-free/allergies, or changing cooking method such as baking vs air-frying). If the request is gibberish, empty, asks for no real change, or only renames the title without changing the food, set `is_meaningful_change` to FALSE and write a cheeky, polite British explanation in `rejection_reason`.\n"
            . "2. PRESERVE CORE SPIRIT: Keep the foundational structure of the parent recipe intact while weaving the user's requested changes seamlessly into `ingredients`, `instructions`, `prep_time_mins`, `cook_time_mins`, `yields`, and dietary flags (`is_oil_free`, `is_gluten_free`, `is_sugar_free`). Keep the recipe 100% plant-based (vegan).\n"
            . "3. {$titleInstruction}\n"
            . "4. Write a concise `variant_summary` (1 sentence, max 220 characters) summarizing specifically what changed compared to the original recipe (e.g. \"Swapped soy sauce for coconut aminos, added roasted pineapple, and made it 100% gluten-free.\").\n"
            . "5. Write a purely culinary `description` (2-3 sentences, no greetings or usernames).\n"
            . "6. Calculate accurate per-serving `macros` (`calories`, `protein_g`, `carbs_g`, `fat_g`, `fiber_g`).\n"
            . "7. {$microsInstruction}\n"
            . "8. Provide a vivid `image_prompt` describing the finished plated variant dish for food photography."
            . $allergyClause;

        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => [
                'temperature' => 0.35,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'is_meaningful_change' => ['type' => 'BOOLEAN'],
                        'rejection_reason' => ['type' => 'STRING'],
                        'title' => ['type' => 'STRING'],
                        'variant_summary' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                        'yields' => ['type' => 'STRING'],
                        'prep_time_mins' => ['type' => 'INTEGER'],
                        'cook_time_mins' => ['type' => 'INTEGER'],
                        'is_oil_free' => ['type' => 'BOOLEAN'],
                        'is_gluten_free' => ['type' => 'BOOLEAN'],
                        'is_sugar_free' => ['type' => 'BOOLEAN'],
                        'ingredients' => [
                            'type' => 'ARRAY',
                            'items' => ['type' => 'STRING'],
                        ],
                        'instructions' => [
                            'type' => 'ARRAY',
                            'items' => ['type' => 'STRING'],
                        ],
                        'macros' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'calories' => ['type' => 'NUMBER'],
                                'protein_g' => ['type' => 'NUMBER'],
                                'carbs_g' => ['type' => 'NUMBER'],
                                'fat_g' => ['type' => 'NUMBER'],
                                'fiber_g' => ['type' => 'NUMBER'],
                            ],
                            'required' => ['calories', 'protein_g', 'carbs_g', 'fat_g', 'fiber_g'],
                        ],
                        'deep_dive' => ['type' => 'STRING'],
                        'micros' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'name' => ['type' => 'STRING'],
                                    'amount' => ['type' => 'NUMBER'],
                                    'unit' => ['type' => 'STRING'],
                                    'dv' => ['type' => 'NUMBER'],
                                ],
                                'required' => ['name', 'amount', 'unit', 'dv'],
                            ],
                        ],
                        'image_prompt' => ['type' => 'STRING'],
                    ],
                    'required' => [
                        'is_meaningful_change', 'rejection_reason', 'title', 'variant_summary',
                        'description', 'yields', 'prep_time_mins', 'cook_time_mins',
                        'is_oil_free', 'is_gluten_free', 'is_sugar_free',
                        'ingredients', 'instructions', 'macros', 'deep_dive', 'micros', 'image_prompt',
                    ],
                ],
            ],
        ];

        $url = self::GEMINI_BASE . '/v1beta/models/' . self::CHAT_MODEL . ':generateContent';
        $res = $this->postGeminiJson($url, $payload, 60);

        if (!$res['ok']) {
            $errMsg = $res['data']['error']['message'] ?? 'Emma encountered an error while crafting your variant.';
            return [
                'success' => false,
                'status' => $res['status'] >= 400 ? $res['status'] : 502,
                'message' => $errMsg,
            ];
        }

        $jsonText = (string)($res['data']['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $parsed = json_decode($jsonText, true);
        if (!is_array($parsed)) {
            return [
                'success' => false,
                'status' => 502,
                'message' => 'Emma returned an unreadable variant format. Please try again.',
            ];
        }

        if (empty($parsed['is_meaningful_change'])) {
            $reason = trim((string)($parsed['rejection_reason'] ?? ''));
            return [
                'success' => false,
                'status' => 422,
                'trivialChange' => true,
                'message' => $reason !== ''
                    ? $reason
                    : "Oi! That change is a bit too subtle to count as a new variant. Try swapping an ingredient, adjusting the flavour profile, or changing the cooking method!",
            ];
        }

        $newIngredients = array_values(array_filter(array_map('trim', $parsed['ingredients'] ?? [])));
        $newInstructions = array_values(array_filter(array_map(function ($step) {
            return trim(preg_replace('/^\d+\.\s*/', '', trim((string)$step)));
        }, $parsed['instructions'] ?? [])));

        if (empty($newIngredients) || empty($newInstructions)) {
            return [
                'success' => false,
                'status' => 502,
                'message' => 'Generated variant was missing ingredients or instructions. Please try again.',
            ];
        }

        // PHP-level anti-clout-stealing verification: ensure ingredients or instructions actually changed
        $normParentIng = strtolower(preg_replace('/\s+/', ' ', implode('|', $parentIngredientsLines)));
        $normNewIng = strtolower(preg_replace('/\s+/', ' ', implode('|', $newIngredients)));
        $normParentInst = strtolower(preg_replace('/\s+/', ' ', implode('|', array_map(
            fn($s) => preg_replace('/^\d+\.\s*/', '', $s),
            $parentInstructionsLines
        ))));
        $normNewInst = strtolower(preg_replace('/\s+/', ' ', implode('|', $newInstructions)));

        if ($normParentIng === $normNewIng && $normParentInst === $normNewInst) {
            return [
                'success' => false,
                'status' => 422,
                'trivialChange' => true,
                'message' => "Hold on, love! The resulting ingredients and instructions are identical to the original recipe. Describe a concrete ingredient or method change to fork a variant.",
            ];
        }

        $cleanTitle = trim((string)($customTitle !== '' ? $customTitle : ($parsed['title'] ?? '')));
        $cleanTitle = preg_replace('/^\[(.+)\]$/u', '$1', $cleanTitle);
        $cleanTitle = trim((string)$cleanTitle) ?: ($parentTitle . ' (Variant)');

        $variantSummary = trim((string)($parsed['variant_summary'] ?? ''));
        if ($variantSummary === '') {
            $variantSummary = mb_substr(trim($changesRequested), 0, 240);
        } else {
            $variantSummary = mb_substr($variantSummary, 0, 250);
        }

        return [
            'success' => true,
            'variant' => [
                'title' => mb_substr($cleanTitle, 0, 250),
                'variant_summary' => $variantSummary,
                'description' => trim((string)($parsed['description'] ?? '')),
                'yields' => mb_substr(trim((string)($parsed['yields'] ?? $parentYields)) ?: '4 servings', 0, 50),
                'prep_time_mins' => max(0, (int)($parsed['prep_time_mins'] ?? $parentPrep)),
                'cook_time_mins' => max(0, (int)($parsed['cook_time_mins'] ?? $parentCook)),
                'is_oil_free' => !empty($parsed['is_oil_free']) ? 1 : 0,
                'is_gluten_free' => !empty($parsed['is_gluten_free']) ? 1 : 0,
                'is_sugar_free' => !empty($parsed['is_sugar_free']) ? 1 : 0,
                'ingredients' => $newIngredients,
                'instructions' => $newInstructions,
                'macros' => is_array($parsed['macros'] ?? null) ? $parsed['macros'] : [],
                'deep_dive' => $includeMicros ? trim((string)($parsed['deep_dive'] ?? '')) : '',
                'micros' => $includeMicros && is_array($parsed['micros'] ?? null) ? $parsed['micros'] : [],
                'image_prompt' => trim((string)($parsed['image_prompt'] ?? $cleanTitle)),
            ],
        ];
    }

    /**
     * Generates an AI dish photo via Gemini Image generation for 1.0-token Visual Variants.
     *
     * @return array{ok: bool, bytes?: string, ext?: string, message?: string}
     */
    public function generateVariantAiImage(string $title, string $imagePrompt, array $styleOptions = []): array {
        if ($this->geminiApiKey === '') {
            return ['ok' => false, 'message' => 'AI image service is not configured.'];
        }

        $surface = trim((string)($styleOptions['surfaceStyle'] ?? '')) ?: 'a rustic butcher block counter';
        $lighting = trim((string)($styleOptions['lightingStyle'] ?? '')) ?: 'soft natural window light';
        $angle = trim((string)($styleOptions['cameraAngle'] ?? '')) ?: '45-degree dining angle';
        $kitchenColor = trim((string)($styleOptions['kitchenColorScheme'] ?? ''));
        $customDetails = trim((string)($styleOptions['customDetails'] ?? ''));

        $fullPrompt = "Professional editorial food photography of a whole-food, plant-based dish: {$title}. "
            . ($imagePrompt !== '' ? "{$imagePrompt}. " : '')
            . "Served on {$surface}. Lighting: {$lighting}. Camera angle: {$angle}. "
            . ($kitchenColor !== '' ? "Kitchen color palette: {$kitchenColor}. " : '')
            . ($customDetails !== '' ? "Additional styling: {$customDetails}. " : '')
            . "Ultra-appetizing, vibrant fresh plant ingredients, shallow depth of field, high resolution, no text or watermarks.";

        $payload = [
            'contents' => [
                ['parts' => [['text' => $fullPrompt]]],
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                'imageConfig' => [
                    'aspectRatio' => '16:9',
                    'imageSize' => '1K',
                ],
            ],
        ];

        $url = self::GEMINI_BASE . '/v1beta/models/' . self::IMAGE_MODEL . ':generateContent';
        $res = $this->postGeminiJson($url, $payload, 90);
        if (!$res['ok']) {
            return [
                'ok' => false,
                'message' => $res['data']['error']['message'] ?? "Emma's camera jammed while generating the variant image.",
            ];
        }

        $parts = $res['data']['candidates'][0]['content']['parts'] ?? [];
        $base64 = null;
        foreach ($parts as $part) {
            if (!empty($part['inlineData']['data'])) {
                $base64 = $part['inlineData']['data'];
                break;
            }
        }

        if (!$base64) {
            return ['ok' => false, 'message' => 'No image data returned from AI image generator.'];
        }

        $decoded = base64_decode($base64, true);
        $imgInfo = ($decoded !== false && !empty($decoded)) ? @getimagesizefromstring($decoded) : false;
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        $ext = ($imgInfo !== false && isset($mimeToExt[$imgInfo['mime'] ?? ''])) ? $mimeToExt[$imgInfo['mime']] : 'jpg';

        return [
            'ok' => true,
            'bytes' => (string)$decoded,
            'ext' => $ext,
        ];
    }

    private function postGeminiJson(string $url, array $payload, int $timeout = 60): array {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->geminiApiKey,
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];
        if ($this->caBundle !== null) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'status' => 502, 'data' => ['error' => ['message' => 'Failed to reach AI service.']]];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'status' => 502, 'data' => ['error' => ['message' => 'Invalid response from AI service.']]];
        }

        return [
            'ok' => ($httpCode >= 200 && $httpCode < 300),
            'status' => $httpCode > 0 ? $httpCode : 502,
            'data' => $decoded,
        ];
    }
}
