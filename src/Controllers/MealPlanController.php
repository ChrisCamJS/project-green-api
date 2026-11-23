<?php

namespace App\Controllers;

use App\Database;
use App\Services\UsdaNutritionService;
use PDO;

class MealPlanController {

    private const VALID_SLOTS = ['breakfast', 'lunch', 'dinner', 'snack'];
    private bool $schemaChecked = false;

    private function requireAuth(): int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Please log in to manage your meal plan.']);
            exit;
        }
        return (int)$userId;
    }

    public function ensureTableExists(PDO $db): void {
        if ($this->schemaChecked) {
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS meal_plan_entries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                recipe_id INT DEFAULT NULL,
                entry_type VARCHAR(24) NOT NULL DEFAULT 'recipe',
                plan_date DATE NOT NULL,
                meal_slot VARCHAR(32) NOT NULL DEFAULT 'dinner',
                custom_item_json TEXT DEFAULT NULL,
                servings DECIMAL(5, 2) NOT NULL DEFAULT 1.00,
                notes VARCHAR(255) DEFAULT NULL,
                addons_json LONGTEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_date (user_id, plan_date),
                INDEX idx_user_recipe (user_id, recipe_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $cols = [];
        foreach ($db->query("SHOW COLUMNS FROM meal_plan_entries")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $cols[strtolower((string)$col['Field'])] = $col;
        }

        if (isset($cols['recipe_id']) && strtoupper((string)($cols['recipe_id']['Null'] ?? '')) === 'NO') {
            $db->exec("ALTER TABLE meal_plan_entries MODIFY COLUMN recipe_id INT DEFAULT NULL");
        }
        if (!isset($cols['entry_type'])) {
            $db->exec("ALTER TABLE meal_plan_entries ADD COLUMN entry_type VARCHAR(24) NOT NULL DEFAULT 'recipe' AFTER recipe_id");
        }
        if (!isset($cols['custom_item_json'])) {
            $db->exec("ALTER TABLE meal_plan_entries ADD COLUMN custom_item_json TEXT DEFAULT NULL AFTER meal_slot");
        }
        if (!isset($cols['addons_json'])) {
            $db->exec("ALTER TABLE meal_plan_entries ADD COLUMN addons_json LONGTEXT DEFAULT NULL AFTER notes");
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS meal_plan_shares (
                id INT AUTO_INCREMENT PRIMARY KEY,
                share_token VARCHAR(32) NOT NULL UNIQUE,
                user_id INT NOT NULL,
                title VARCHAR(160) NOT NULL,
                description VARCHAR(500) DEFAULT NULL,
                scope VARCHAR(16) NOT NULL DEFAULT 'week',
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                entries_json LONGTEXT NOT NULL,
                view_count INT NOT NULL DEFAULT 0,
                import_count INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_share_token (share_token),
                INDEX idx_share_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->schemaChecked = true;
    }

    private function isValidDate(?string $date): bool {
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return checkdate($m, $d, $y);
    }

    private function normalizeSlot(?string $slot): string {
        $s = strtolower(trim((string)$slot));
        return in_array($s, self::VALID_SLOTS, true) ? $s : 'dinner';
    }

    private function fetchMicrosByRecipeIds(PDO $db, array $recipeIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $recipeIds), fn($id) => $id > 0)));
        if (empty($ids)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("
                SELECT recipe_id, nutrient_name, amount, unit, daily_value_percentage
                FROM micros
                WHERE recipe_id IN ($placeholders)
                ORDER BY id ASC
            ");
            $stmt->execute($ids);
            $grouped = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $rid = (int)$m['recipe_id'];
                if (!isset($grouped[$rid])) {
                    $grouped[$rid] = [];
                }
                $grouped[$rid][] = [
                    'nutrient_name' => $m['nutrient_name'],
                    'amount' => $m['amount'] !== null ? (float)$m['amount'] : 0.0,
                    'unit' => $m['unit'] ?? '',
                    'daily_value_percentage' => $m['daily_value_percentage'] !== null ? (float)$m['daily_value_percentage'] : 0.0,
                ];
            }
            return $grouped;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function fetchIngredientsByRecipeIds(PDO $db, array $recipeIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $recipeIds), fn($id) => $id > 0)));
        if (empty($ids)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("
                SELECT recipe_id, ingredient_name
                FROM ingredients
                WHERE recipe_id IN ($placeholders)
                ORDER BY id ASC
            ");
            $stmt->execute($ids);
            $grouped = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ing) {
                $rid = (int)$ing['recipe_id'];
                $name = trim((string)($ing['ingredient_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                if (!isset($grouped[$rid])) {
                    $grouped[$rid] = [];
                }
                $grouped[$rid][] = $name;
            }
            return $grouped;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function ensureFiberBackfilled(PDO $db): void {
        try {
            $missingCount = (int)$db->query("SELECT COUNT(*) FROM macros WHERE fiber_g IS NULL OR fiber_g = 0")->fetchColumn();
            if ($missingCount <= 0) {
                return;
            }

            // 1. Copy explicit Fiber entries recorded in the micros table
            $db->exec("
                UPDATE macros m
                JOIN (
                    SELECT recipe_id, MAX(amount) AS fiber_amount
                    FROM micros
                    WHERE LOWER(nutrient_name) LIKE '%fiber%' AND amount > 0
                    GROUP BY recipe_id
                ) mf ON mf.recipe_id = m.recipe_id
                SET m.fiber_g = ROUND(mf.fiber_amount, 1)
                WHERE m.fiber_g IS NULL OR m.fiber_g = 0
            ");

            // 2. Backfill remaining WFPB recipes where fiber_g was saved as 0 before Fiber was included in the prompt template
            $db->exec("
                UPDATE macros m
                JOIN recipes r ON r.id = m.recipe_id
                SET m.fiber_g = CASE
                    WHEN m.carbs_g IS NULL OR m.carbs_g <= 0 THEN 0
                    WHEN LOWER(CONCAT_WS(' ', r.title, r.description, (SELECT GROUP_CONCAT(i.ingredient_name SEPARATOR ' ') FROM ingredients i WHERE i.recipe_id = r.id)))
                         REGEXP '(avocado|guacamole)' THEN GREATEST(4.0, ROUND(m.carbs_g * 0.45, 1))
                    WHEN LOWER(CONCAT_WS(' ', r.title, r.description, (SELECT GROUP_CONCAT(i.ingredient_name SEPARATOR ' ') FROM ingredients i WHERE i.recipe_id = r.id)))
                         REGEXP '(bean|lentil|chickpea|garbanzo|split pea|edamame|chili|flax|chia|collard|brussels sprout|artichoke|broccoli)' THEN ROUND(m.carbs_g * 0.24, 1)
                    WHEN LOWER(CONCAT_WS(' ', r.title, r.description, (SELECT GROUP_CONCAT(i.ingredient_name SEPARATOR ' ') FROM ingredients i WHERE i.recipe_id = r.id)))
                         REGEXP '(oat|whole wheat|whole-grain|quinoa|barley|buckwheat|sweet potato|potato|yukon|cauliflower|pumpkin|squash|grits|cornmeal|walnut|almond)' THEN ROUND(m.carbs_g * 0.17, 1)
                    ELSE GREATEST(0.5, ROUND(m.carbs_g * 0.14, 1))
                END
                WHERE (m.fiber_g IS NULL OR m.fiber_g = 0) AND m.carbs_g > 0
            ");
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    private const TRACKED_MICRO_SPECS = [
        'Dietary Fiber' => ['unit' => 'g', 'dv' => 28.0],
        'Sodium' => ['unit' => 'mg', 'dv' => 2300.0],
        'Saturated Fat' => ['unit' => 'g', 'dv' => 20.0],
        'Potassium' => ['unit' => 'mg', 'dv' => 4700.0],
        'Iron' => ['unit' => 'mg', 'dv' => 18.0],
        'Calcium' => ['unit' => 'mg', 'dv' => 1300.0],
        'Magnesium' => ['unit' => 'mg', 'dv' => 420.0],
        'Zinc' => ['unit' => 'mg', 'dv' => 11.0],
        'Vitamin C' => ['unit' => 'mg', 'dv' => 90.0],
        'Vitamin A' => ['unit' => 'mcg', 'dv' => 900.0],
        'Folate' => ['unit' => 'mcg', 'dv' => 400.0],
        'Vitamin K' => ['unit' => 'mcg', 'dv' => 120.0],
        'Vitamin B6' => ['unit' => 'mg', 'dv' => 1.7],
        'Vitamin E' => ['unit' => 'mg', 'dv' => 15.0],
        'Vitamin B12' => ['unit' => 'mcg', 'dv' => 2.4],
    ];

    private function fetchRecipeMetaByIds(PDO $db, array $recipeIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $recipeIds), fn($id) => $id > 0)));
        if (empty($ids)) {
            return [];
        }
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("
                SELECT r.id, r.user_id, r.title, r.image_url, r.image_source, r.is_public,
                       r.is_oil_free, r.is_gluten_free, r.is_sugar_free,
                       m.calories, m.protein_g, m.carbs_g, m.fat_g, m.fiber_g
                FROM recipes r
                LEFT JOIN macros m ON m.recipe_id = r.id
                WHERE r.id IN ($placeholders)
            ");
            $stmt->execute($ids);
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map[(int)$row['id']] = $row;
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function normalizeUsdaNutritionBlock(array $raw): array {
        $title = substr(trim((string)($raw['title'] ?? $raw['description'] ?? 'USDA Food Item')), 0, 180);
        if ($title === '') {
            $title = 'USDA Food Item';
        }
        $fdcId = max(0, (int)($raw['fdcId'] ?? $raw['fdc_id'] ?? 0));
        $category = in_array(($raw['category'] ?? ''), ['condiment', 'sauce', 'side'], true)
            ? $raw['category']
            : 'condiment';
        $amount = isset($raw['amount']) ? max(0.05, min(2000.0, round((float)$raw['amount'], 2))) : 1.0;
        $unit = substr(trim((string)($raw['unit'] ?? 'tbsp')), 0, 40) ?: 'tbsp';
        $grams = isset($raw['grams']) ? max(0.5, min(5000.0, round((float)$raw['grams'], 2))) : 15.0;

        $per100g = is_array($raw['per100g'] ?? null) ? $raw['per100g'] : null;
        if ($per100g) {
            $scale = $grams / 100.0;
            $calories = (int)round(((float)($per100g['calories'] ?? 0)) * $scale);
            $protein = round(((float)($per100g['protein_g'] ?? 0)) * $scale, 1);
            $carbs = round(((float)($per100g['carbs_g'] ?? 0)) * $scale, 1);
            $fat = round(((float)($per100g['fat_g'] ?? 0)) * $scale, 1);
            $fiber = round(((float)($per100g['fiber_g'] ?? 0)) * $scale, 1);

            $micros = [];
            $rawMicrosMap = is_array($per100g['micros'] ?? null) ? $per100g['micros'] : [];
            if (!empty($rawMicrosMap)) {
                foreach ($rawMicrosMap as $mName => $per100Val) {
                    $spec = self::TRACKED_MICRO_SPECS[$mName] ?? null;
                    $scaledAmt = round(((float)$per100Val) * $scale, 3);
                    if ($scaledAmt <= 0) {
                        continue;
                    }
                    $dvPct = ($spec && $spec['dv'] > 0) ? round(($scaledAmt / $spec['dv']) * 100, 1) : 0.0;
                    $micros[] = [
                        'nutrient_name' => $mName,
                        'amount' => $scaledAmt,
                        'unit' => $spec ? $spec['unit'] : 'mg',
                        'daily_value_percentage' => $dvPct,
                    ];
                }
            } elseif (is_array($per100g['microsList'] ?? null)) {
                foreach ($per100g['microsList'] as $mRow) {
                    $mName = (string)($mRow['nutrient_name'] ?? $mRow['name'] ?? '');
                    if ($mName === '') continue;
                    $spec = self::TRACKED_MICRO_SPECS[$mName] ?? null;
                    $scaledAmt = round(((float)($mRow['amount'] ?? 0)) * $scale, 3);
                    if ($scaledAmt <= 0) continue;
                    $dvPct = ($spec && $spec['dv'] > 0) ? round(($scaledAmt / $spec['dv']) * 100, 1) : 0.0;
                    $micros[] = [
                        'nutrient_name' => $mName,
                        'amount' => $scaledAmt,
                        'unit' => $spec ? $spec['unit'] : ($mRow['unit'] ?? 'mg'),
                        'daily_value_percentage' => $dvPct,
                    ];
                }
            }
        } else {
            $calories = max(0, (int)round((float)($raw['calories'] ?? 0)));
            $protein = max(0.0, round((float)($raw['protein_g'] ?? 0), 1));
            $carbs = max(0.0, round((float)($raw['carbs_g'] ?? 0), 1));
            $fat = max(0.0, round((float)($raw['fat_g'] ?? 0), 1));
            $fiber = max(0.0, round((float)($raw['fiber_g'] ?? 0), 1));
            $micros = is_array($raw['micros'] ?? null) ? array_values($raw['micros']) : [];
        }

        return [
            'id' => substr(trim((string)($raw['id'] ?? ('usda-' . uniqid()))), 0, 64),
            'type' => 'usda',
            'category' => $category,
            'fdcId' => $fdcId,
            'title' => $title,
            'dataType' => substr(trim((string)($raw['dataType'] ?? $raw['data_type'] ?? 'USDA FDC')), 0, 64),
            'amount' => $amount,
            'unit' => $unit,
            'grams' => $grams,
            'calories' => $calories,
            'protein_g' => $protein,
            'carbs_g' => $carbs,
            'fat_g' => $fat,
            'fiber_g' => $fiber,
            'micros' => $micros,
            'per100g' => $per100g,
        ];
    }

    private function normalizeAddons($rawAddons, PDO $db, int $userId): array {
        if (!is_array($rawAddons) || empty($rawAddons)) {
            return [];
        }

        $slice = array_slice($rawAddons, 0, 15);
        $recipeIds = [];
        foreach ($slice as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'recipe' && !empty($item['recipeId'])) {
                $recipeIds[] = (int)$item['recipeId'];
            }
        }
        $metaMap = $this->fetchRecipeMetaByIds($db, $recipeIds);
        $microsMap = $this->fetchMicrosByRecipeIds($db, $recipeIds);

        $normalized = [];
        foreach ($slice as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = ($item['type'] ?? '') === 'usda' ? 'usda' : 'recipe';
            $category = in_array(($item['category'] ?? ''), ['condiment', 'sauce', 'side'], true)
                ? $item['category']
                : ($type === 'usda' ? 'condiment' : 'sauce');

            if ($type === 'usda') {
                $norm = $this->normalizeUsdaNutritionBlock($item);
                $norm['category'] = $category;
                $normalized[] = $norm;
                continue;
            }

            $rid = (int)($item['recipeId'] ?? $item['recipe_id'] ?? 0);
            if ($rid <= 0 || !isset($metaMap[$rid])) {
                continue;
            }
            $rMeta = $metaMap[$rid];
            $canUse = ((int)$rMeta['is_public'] === 1 || (int)$rMeta['user_id'] === $userId);
            if (!$canUse) {
                continue;
            }
            $servings = isset($item['servings'])
                ? max(0.25, min(20.0, round((float)$item['servings'], 2)))
                : 1.0;

            $normalized[] = [
                'id' => substr(trim((string)($item['id'] ?? ('rec-' . $rid . '-' . $idx))), 0, 64),
                'type' => 'recipe',
                'category' => $category,
                'recipeId' => $rid,
                'title' => (string)$rMeta['title'],
                'imageUrl' => $rMeta['image_url'],
                'isPublic' => (int)$rMeta['is_public'] === 1,
                'servings' => $servings,
                'calories' => $rMeta['calories'] !== null ? (int)$rMeta['calories'] : 0,
                'protein_g' => $rMeta['protein_g'] !== null ? (float)$rMeta['protein_g'] : 0.0,
                'carbs_g' => $rMeta['carbs_g'] !== null ? (float)$rMeta['carbs_g'] : 0.0,
                'fat_g' => $rMeta['fat_g'] !== null ? (float)$rMeta['fat_g'] : 0.0,
                'fiber_g' => $rMeta['fiber_g'] !== null ? round((float)$rMeta['fiber_g'], 1) : 0.0,
                'micros' => $microsMap[$rid] ?? [],
            ];
        }

        return $normalized;
    }

    private function hydrateEntries(PDO $db, array $rows): array {
        if (empty($rows)) {
            return [];
        }

        $recipeIds = [];
        foreach ($rows as $r) {
            if (!empty($r['recipe_id'])) {
                $recipeIds[] = (int)$r['recipe_id'];
            }
            if (!empty($r['addons_json'])) {
                $decodedAddons = json_decode((string)$r['addons_json'], true);
                if (is_array($decodedAddons)) {
                    foreach ($decodedAddons as $ad) {
                        if (is_array($ad) && ($ad['type'] ?? '') === 'recipe' && !empty($ad['recipeId'])) {
                            $recipeIds[] = (int)$ad['recipeId'];
                        }
                    }
                }
            }
        }

        $recipeIds = array_values(array_unique(array_filter($recipeIds, fn($id) => $id > 0)));
        $metaMap = $this->fetchRecipeMetaByIds($db, $recipeIds);
        $microsByRecipe = $this->fetchMicrosByRecipeIds($db, $recipeIds);
        $ingredientsByRecipe = $this->fetchIngredientsByRecipeIds($db, $recipeIds);

        return array_map(
            fn($row) => $this->formatEntryRow($row, $microsByRecipe, $ingredientsByRecipe, $metaMap),
            $rows
        );
    }

    private function formatEntryRow(
        array $row,
        array $microsByRecipe = [],
        array $ingredientsByRecipe = [],
        array $metaMap = []
    ): array {
        $entryType = ($row['entry_type'] ?? 'recipe') === 'usda' ? 'usda' : 'recipe';
        $rid = !empty($row['recipe_id']) ? (int)$row['recipe_id'] : 0;

        $addons = [];
        if (!empty($row['addons_json'])) {
            $rawAddons = json_decode((string)$row['addons_json'], true);
            if (is_array($rawAddons)) {
                foreach ($rawAddons as $ad) {
                    if (!is_array($ad)) {
                        continue;
                    }
                    if (($ad['type'] ?? '') === 'recipe' && !empty($ad['recipeId'])) {
                        $adRid = (int)$ad['recipeId'];
                        $adMeta = $metaMap[$adRid] ?? null;
                        $addons[] = [
                            'id' => $ad['id'] ?? ('rec-' . $adRid),
                            'type' => 'recipe',
                            'category' => $ad['category'] ?? 'sauce',
                            'recipeId' => $adRid,
                            'title' => $adMeta ? $adMeta['title'] : ($ad['title'] ?? 'Vault Recipe'),
                            'imageUrl' => $adMeta ? $adMeta['image_url'] : ($ad['imageUrl'] ?? null),
                            'isPublic' => $adMeta ? ((int)$adMeta['is_public'] === 1) : (bool)($ad['isPublic'] ?? true),
                            'servings' => (float)($ad['servings'] ?? 1.0),
                            'calories' => $adMeta && $adMeta['calories'] !== null ? (int)$adMeta['calories'] : (int)($ad['calories'] ?? 0),
                            'protein_g' => $adMeta && $adMeta['protein_g'] !== null ? (float)$adMeta['protein_g'] : (float)($ad['protein_g'] ?? 0),
                            'carbs_g' => $adMeta && $adMeta['carbs_g'] !== null ? (float)$adMeta['carbs_g'] : (float)($ad['carbs_g'] ?? 0),
                            'fat_g' => $adMeta && $adMeta['fat_g'] !== null ? (float)$adMeta['fat_g'] : (float)($ad['fat_g'] ?? 0),
                            'fiber_g' => $adMeta && $adMeta['fiber_g'] !== null ? round((float)$adMeta['fiber_g'], 1) : round((float)($ad['fiber_g'] ?? 0), 1),
                            'micros' => $microsByRecipe[$adRid] ?? ($ad['micros'] ?? []),
                        ];
                    } else {
                        $addons[] = $ad;
                    }
                }
            }
        }

        if ($entryType === 'usda' || $rid <= 0) {
            $custom = !empty($row['custom_item_json']) ? json_decode((string)$row['custom_item_json'], true) : [];
            if (!is_array($custom)) {
                $custom = [];
            }
            $amount = (float)($custom['amount'] ?? 1.0);
            $unit = (string)($custom['unit'] ?? 'serving');
            $grams = (float)($custom['grams'] ?? 0);
            return [
                'id' => (int)$row['id'],
                'userId' => (int)$row['user_id'],
                'recipeId' => null,
                'entryType' => 'usda',
                'customItem' => $custom,
                'planDate' => $row['plan_date'],
                'mealSlot' => $row['meal_slot'],
                'servings' => (float)$row['servings'],
                'notes' => $row['notes'] ?? '',
                'recipeNotes' => '',
                'title' => (string)($custom['title'] ?? 'USDA Food Item'),
                'description' => trim("{$amount} {$unit}" . ($grams > 0 ? " ({$grams}g)" : '') . ' • USDA FoodData Central'),
                'imageUrl' => null,
                'imageSource' => 'usda',
                'isPublic' => true,
                'isDraft' => false,
                'isOilFree' => true,
                'isGlutenFree' => true,
                'isSugarFree' => true,
                'prepTime' => 0,
                'cookTime' => 0,
                'yields' => trim("{$amount} {$unit}"),
                'authorId' => null,
                'authorName' => $custom['dataType'] ?? 'USDA FoodData Central',
                'calories' => isset($custom['calories']) ? (int)$custom['calories'] : 0,
                'protein_g' => isset($custom['protein_g']) ? (float)$custom['protein_g'] : 0,
                'carbs_g' => isset($custom['carbs_g']) ? (float)$custom['carbs_g'] : 0,
                'fat_g' => isset($custom['fat_g']) ? (float)$custom['fat_g'] : 0,
                'fiber_g' => isset($custom['fiber_g']) ? round((float)$custom['fiber_g'], 1) : 0,
                'ingredients' => [],
                'micros' => is_array($custom['micros'] ?? null) ? $custom['micros'] : [],
                'addons' => $addons,
            ];
        }

        return [
            'id' => (int)$row['id'],
            'userId' => (int)$row['user_id'],
            'recipeId' => $rid,
            'entryType' => 'recipe',
            'customItem' => null,
            'planDate' => $row['plan_date'],
            'mealSlot' => $row['meal_slot'],
            'servings' => (float)$row['servings'],
            'notes' => $row['notes'] ?? '',
            'recipeNotes' => $row['recipe_notes'] ?? '',
            'title' => $row['title'],
            'description' => $row['description'] ?? '',
            'imageUrl' => $row['image_url'],
            'imageSource' => $row['image_source'],
            'isPublic' => (bool)$row['is_public'],
            'isDraft' => !(bool)$row['is_public'],
            'isOilFree' => (bool)($row['is_oil_free'] ?? 1),
            'isGlutenFree' => (bool)($row['is_gluten_free'] ?? 0),
            'isSugarFree' => (bool)($row['is_sugar_free'] ?? 1),
            'prepTime' => (int)($row['prep_time_mins'] ?? 0),
            'cookTime' => (int)($row['cook_time_mins'] ?? 0),
            'yields' => $row['yields'] ?? '',
            'authorId' => !empty($row['recipe_author_id']) ? (int)$row['recipe_author_id'] : null,
            'authorName' => $row['author_name'] ?? 'Emma (AI)',
            'calories' => $row['calories'] !== null ? (int)$row['calories'] : null,
            'protein_g' => $row['protein_g'] !== null ? (int)$row['protein_g'] : null,
            'carbs_g' => $row['carbs_g'] !== null ? (int)$row['carbs_g'] : null,
            'fat_g' => $row['fat_g'] !== null ? (int)$row['fat_g'] : null,
            'fiber_g' => $row['fiber_g'] !== null ? round((float)$row['fiber_g'], 1) : null,
            'ingredients' => $ingredientsByRecipe[$rid] ?? [],
            'micros' => $microsByRecipe[$rid] ?? [],
            'addons' => $addons,
        ];
    }

    private function fetchSingleEntry(PDO $db, int $entryId, int $userId): ?array {
        $stmt = $db->prepare("
            SELECT mp.id, mp.user_id, mp.recipe_id, mp.entry_type, mp.plan_date, mp.meal_slot,
                   mp.custom_item_json, mp.servings, mp.notes, mp.addons_json,
                   r.title, r.description, r.notes AS recipe_notes, r.image_url, r.image_source, r.is_public,
                   r.is_oil_free, r.is_gluten_free, r.is_sugar_free,
                   r.prep_time_mins, r.cook_time_mins, r.yields, r.user_id AS recipe_author_id,
                   u.username AS author_name,
                   m.calories, m.protein_g, m.carbs_g, m.fat_g, m.fiber_g
            FROM meal_plan_entries mp
            LEFT JOIN recipes r ON r.id = mp.recipe_id
            LEFT JOIN users u ON u.id = r.user_id
            LEFT JOIN macros m ON m.recipe_id = r.id
            WHERE mp.id = ? AND mp.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$entryId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $hydrated = $this->hydrateEntries($db, [$row]);
        return $hydrated[0] ?? null;
    }

    /**
     * GET /meal-plans?start=YYYY-MM-DD&end=YYYY-MM-DD
     * Returns all planned meals for the active user within the requested date window.
     */
    public function getMealPlans() {
        $userId = $this->requireAuth();
        $db = Database::connect();
        $this->ensureTableExists($db);
        $this->ensureFiberBackfilled($db);

        $start = $_GET['start'] ?? null;
        $end = $_GET['end'] ?? null;

        if (!$this->isValidDate($start)) {
            $start = date('Y-m-01', strtotime('-7 days'));
        }
        if (!$this->isValidDate($end)) {
            $end = date('Y-m-t', strtotime('+14 days'));
        }

        $sql = "
            SELECT mp.id, mp.user_id, mp.recipe_id, mp.entry_type, mp.plan_date, mp.meal_slot,
                   mp.custom_item_json, mp.servings, mp.notes, mp.addons_json,
                   r.title, r.description, r.notes AS recipe_notes, r.image_url, r.image_source, r.is_public,
                   r.is_oil_free, r.is_gluten_free, r.is_sugar_free,
                   r.prep_time_mins, r.cook_time_mins, r.yields, r.user_id AS recipe_author_id,
                   u.username AS author_name,
                   m.calories, m.protein_g, m.carbs_g, m.fat_g, m.fiber_g
            FROM meal_plan_entries mp
            LEFT JOIN recipes r ON r.id = mp.recipe_id
            LEFT JOIN users u ON u.id = r.user_id
            LEFT JOIN macros m ON m.recipe_id = r.id
            WHERE mp.user_id = ?
              AND mp.plan_date BETWEEN ? AND ?
              AND (mp.entry_type = 'usda' OR mp.recipe_id IS NULL OR mp.recipe_id = 0 OR r.is_public = 1 OR r.user_id = ?)
            ORDER BY mp.plan_date ASC,
                     FIELD(mp.meal_slot, 'breakfast', 'lunch', 'dinner', 'snack') ASC,
                     mp.id ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$userId, $start, $end, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $entries = $this->hydrateEntries($db, $rows);

        echo json_encode([
            'success' => true,
            'start' => $start,
            'end' => $end,
            'entries' => $entries,
        ]);
    }

    /**
     * GET /meal-plans/recipes
     * Returns every recipe the user can add to a meal plan: all published recipes plus their own private drafts.
     */
    public function getPlannableRecipes() {
        $userId = $this->requireAuth();
        $db = Database::connect();
        $this->ensureFiberBackfilled($db);

        $sql = "
            SELECT r.id, r.user_id, r.title, r.description, r.notes AS recipe_notes, r.image_url, r.image_source,
                   r.is_public, r.prep_time_mins, r.cook_time_mins, r.yields, r.created_at,
                   r.is_oil_free, r.is_gluten_free, r.is_sugar_free,
                   u.username AS author_name,
                   m.calories, m.protein_g, m.carbs_g, m.fat_g, m.fiber_g,
                   CASE WHEN r.user_id = ? THEN 1 ELSE 0 END AS is_own,
                   CASE WHEN rf.recipe_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
            FROM recipes r
            LEFT JOIN users u ON u.id = r.user_id
            LEFT JOIN macros m ON m.recipe_id = r.id
            LEFT JOIN recipe_favorites rf ON rf.recipe_id = r.id AND rf.user_id = ?
            WHERE r.is_public = 1 OR r.user_id = ?
            ORDER BY is_own DESC, is_favorite DESC, r.created_at DESC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$userId, $userId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $recipeIds = array_map(fn($r) => (int)$r['id'], $rows);
        $microsByRecipe = $this->fetchMicrosByRecipeIds($db, $recipeIds);
        $ingredientsByRecipe = $this->fetchIngredientsByRecipeIds($db, $recipeIds);

        $recipes = array_map(function ($r) use ($microsByRecipe, $ingredientsByRecipe) {
            $rid = (int)$r['id'];
            return [
                'id' => $rid,
                'userId' => !empty($r['user_id']) ? (int)$r['user_id'] : null,
                'title' => $r['title'],
                'description' => $r['description'] ?? '',
                'notes' => $r['recipe_notes'] ?? '',
                'imageUrl' => $r['image_url'],
                'imageSource' => $r['image_source'],
                'isPublic' => (bool)$r['is_public'],
                'isDraft' => !(bool)$r['is_public'],
                'isOwn' => (bool)$r['is_own'],
                'isFavorited' => (bool)$r['is_favorite'],
                'prepTime' => (int)($r['prep_time_mins'] ?? 0),
                'cookTime' => (int)($r['cook_time_mins'] ?? 0),
                'yields' => $r['yields'] ?? '',
                'authorName' => $r['author_name'] ?? 'Emma (AI)',
                'isOilFree' => (bool)$r['is_oil_free'],
                'isGlutenFree' => (bool)($r['is_gluten_free'] ?? 0),
                'isSugarFree' => (bool)($r['is_sugar_free'] ?? 1),
                'calories' => $r['calories'] !== null ? (int)$r['calories'] : null,
                'protein_g' => $r['protein_g'] !== null ? (int)$r['protein_g'] : null,
                'carbs_g' => $r['carbs_g'] !== null ? (int)$r['carbs_g'] : null,
                'fat_g' => $r['fat_g'] !== null ? (int)$r['fat_g'] : null,
                'fiber_g' => $r['fiber_g'] !== null ? round((float)$r['fiber_g'], 1) : null,
                'ingredients' => $ingredientsByRecipe[$rid] ?? [],
                'micros' => $microsByRecipe[$rid] ?? [],
            ];
        }, $rows);

        echo json_encode([
            'success' => true,
            'recipes' => $recipes,
        ]);
    }

    /**
     * POST /meal-plans
     * Schedules a published recipe, private draft, or standalone USDA food/condiment on a date & meal slot.
     */
    public function addMealPlanEntry() {
        $userId = $this->requireAuth();
        $db = Database::connect();
        $this->ensureTableExists($db);

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = [];
        }

        $entryType = ($data['entry_type'] ?? 'recipe') === 'usda' ? 'usda' : 'recipe';
        $recipeId = (int)($data['recipe_id'] ?? 0);
        $planDate = trim((string)($data['plan_date'] ?? ''));
        $mealSlot = $this->normalizeSlot($data['meal_slot'] ?? 'dinner');
        $servings = isset($data['servings']) ? max(0.25, min(20.0, round((float)$data['servings'], 2))) : 1.0;
        $notes = substr(trim((string)($data['notes'] ?? '')), 0, 255);
        $addons = $this->normalizeAddons($data['addons'] ?? [], $db, $userId);
        $addonsJson = !empty($addons) ? json_encode($addons) : null;

        if (!$this->isValidDate($planDate)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Please select a valid date (YYYY-MM-DD).',
            ]);
            return;
        }

        $customItemJson = null;
        if ($entryType === 'usda') {
            $rawCustom = is_array($data['custom_item'] ?? null) ? $data['custom_item'] : [];
            if (empty($rawCustom['title']) && empty($rawCustom['description'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Please select a USDA condiment, sauce, or food item.']);
                return;
            }
            $customNormalized = $this->normalizeUsdaNutritionBlock($rawCustom);
            $customItemJson = json_encode($customNormalized);
            $dbRecipeId = null;
        } else {
            if ($recipeId <= 0) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Please select a valid recipe and date (YYYY-MM-DD).',
                ]);
                return;
            }

            $recipeStmt = $db->prepare("SELECT id, user_id, is_public FROM recipes WHERE id = ? LIMIT 1");
            $recipeStmt->execute([$recipeId]);
            $recipeRow = $recipeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$recipeRow) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Recipe not found.']);
                return;
            }

            $isPublic = (int)$recipeRow['is_public'] === 1;
            $isOwn = (int)$recipeRow['user_id'] === $userId;
            if (!$isPublic && !$isOwn) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'You can only add published recipes or your own private drafts to your meal plan.',
                ]);
                return;
            }
            $dbRecipeId = $recipeId;
        }

        $insStmt = $db->prepare("
            INSERT INTO meal_plan_entries (user_id, recipe_id, entry_type, plan_date, meal_slot, custom_item_json, servings, notes, addons_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insStmt->execute([
            $userId,
            $dbRecipeId,
            $entryType,
            $planDate,
            $mealSlot,
            $customItemJson,
            $servings,
            $notes !== '' ? $notes : null,
            $addonsJson,
        ]);

        $newId = (int)$db->lastInsertId();
        $entry = $this->fetchSingleEntry($db, $newId, $userId);

        echo json_encode([
            'success' => true,
            'message' => 'Added to your meal plan!',
            'entry' => $entry,
        ]);
    }

    /**
     * PUT /meal-plans
     * Moves or updates an existing meal plan entry owned by the active user.
     */
    public function updateMealPlanEntry() {
        $userId = $this->requireAuth();
        $db = Database::connect();
        $this->ensureTableExists($db);

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = [];
        }

        $entryId = (int)($data['id'] ?? 0);
        if ($entryId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing meal plan entry ID.']);
            return;
        }

        $existingStmt = $db->prepare("SELECT * FROM meal_plan_entries WHERE id = ? AND user_id = ? LIMIT 1");
        $existingStmt->execute([$entryId, $userId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Meal plan entry not found.']);
            return;
        }

        $planDate = isset($data['plan_date']) ? trim((string)$data['plan_date']) : $existing['plan_date'];
        if (!$this->isValidDate($planDate)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid date format (expected YYYY-MM-DD).']);
            return;
        }

        $mealSlot = isset($data['meal_slot']) ? $this->normalizeSlot($data['meal_slot']) : $existing['meal_slot'];
        $servings = isset($data['servings']) ? max(0.25, min(20.0, round((float)$data['servings'], 2))) : (float)$existing['servings'];
        $notes = array_key_exists('notes', $data)
            ? substr(trim((string)($data['notes'] ?? '')), 0, 255)
            : (string)($existing['notes'] ?? '');

        $entryType = ($existing['entry_type'] ?? 'recipe') === 'usda' ? 'usda' : 'recipe';
        $customItemJson = $existing['custom_item_json'] ?? null;
        $recipeId = $existing['recipe_id'] !== null ? (int)$existing['recipe_id'] : null;

        if ($entryType === 'usda') {
            if (is_array($data['custom_item'] ?? null)) {
                $customItemJson = json_encode($this->normalizeUsdaNutritionBlock($data['custom_item']));
            }
            $recipeId = null;
        } elseif (isset($data['recipe_id']) && (int)$data['recipe_id'] !== (int)($existing['recipe_id'] ?? 0)) {
            $newRid = (int)$data['recipe_id'];
            $rStmt = $db->prepare("SELECT id, user_id, is_public FROM recipes WHERE id = ? LIMIT 1");
            $rStmt->execute([$newRid]);
            $rRow = $rStmt->fetch(PDO::FETCH_ASSOC);
            if (!$rRow || ((int)$rRow['is_public'] !== 1 && (int)$rRow['user_id'] !== $userId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Selected recipe cannot be added to your meal plan.']);
                return;
            }
            $recipeId = $newRid;
        }

        $addonsJson = $existing['addons_json'] ?? null;
        if (array_key_exists('addons', $data)) {
            $normalizedAddons = $this->normalizeAddons($data['addons'], $db, $userId);
            $addonsJson = !empty($normalizedAddons) ? json_encode($normalizedAddons) : null;
        }

        $updStmt = $db->prepare("
            UPDATE meal_plan_entries
            SET recipe_id = ?, plan_date = ?, meal_slot = ?, custom_item_json = ?, servings = ?, notes = ?, addons_json = ?
            WHERE id = ? AND user_id = ?
        ");
        $updStmt->execute([
            $recipeId,
            $planDate,
            $mealSlot,
            $customItemJson,
            $servings,
            $notes !== '' ? $notes : null,
            $addonsJson,
            $entryId,
            $userId,
        ]);

        $entry = $this->fetchSingleEntry($db, $entryId, $userId);

        echo json_encode([
            'success' => true,
            'message' => 'Meal plan updated!',
            'entry' => $entry,
        ]);
    }

    /**
     * DELETE /meal-plans?id=...
     * Removes an entry from the active user's meal plan.
     */
    public function deleteMealPlanEntry() {
        $userId = $this->requireAuth();
        $db = Database::connect();
        $this->ensureTableExists($db);

        $data = json_decode(file_get_contents('php://input'), true);
        $entryId = (int)($_GET['id'] ?? (is_array($data) ? ($data['id'] ?? 0) : 0));

        if ($entryId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing meal plan entry ID.']);
            return;
        }

        $delStmt = $db->prepare("DELETE FROM meal_plan_entries WHERE id = ? AND user_id = ?");
        $delStmt->execute([$entryId, $userId]);

        echo json_encode([
            'success' => true,
            'message' => 'Removed from meal plan.',
        ]);
    }

    /**
     * GET /meal-plans/usda-search?q=...
     * Searches curated condiments + USDA FoodData Central database for sauces, condiments, and sides.
     */
    public function searchUsdaFoods() {
        $this->requireAuth();
        $db = Database::connect();
        $query = trim((string)($_GET['q'] ?? ''));

        $usda = new UsdaNutritionService($db);
        $foods = $usda->searchCondimentsAndFoods($query, 12);

        echo json_encode([
            'success' => true,
            'query' => $query,
            'foods' => $foods,
        ]);
    }

    private function normalizeMonthStart(string $input): ?string {
        $trimmed = trim($input);
        if (preg_match('/^\d{4}-\d{2}$/', $trimmed)) {
            $trimmed .= '-01';
        }
        if (!$this->isValidDate($trimmed)) {
            return null;
        }
        return substr($trimmed, 0, 7) . '-01';
    }

    /**
     * POST /meal-plans/copy-range
     * Copies or repeats a day, week, month, or custom date range onto target date(s).
     */
    public function copyMealPlanRange() {
        $userId = $this->requireAuth();
        $db = Database::connect();
        $this->ensureTableExists($db);

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = [];
        }

        $mode = strtolower(trim((string)($data['mode'] ?? 'day')));
        if (!in_array($mode, ['day', 'week', 'month', 'custom'], true)) {
            $mode = 'day';
        }
        $overwrite = !empty($data['overwrite']);
        $repeatCount = max(1, min(12, (int)($data['repeat_count'] ?? 1)));

        $rawSourceStart = trim((string)($data['source_start'] ?? ''));
        $rawSourceEnd = trim((string)($data['source_end'] ?? ''));
        $rawTargetStart = trim((string)($data['target_start'] ?? ''));
        $rawTargetDates = is_array($data['target_dates'] ?? null) ? $data['target_dates'] : [];

        if ($mode === 'month') {
            $sourceStart = $this->normalizeMonthStart($rawSourceStart);
            $targetBaseStart = $this->normalizeMonthStart($rawTargetStart);
            if (!$sourceStart || !$targetBaseStart) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid source and target months are required.']);
                return;
            }
            $sourceEnd = (new \DateTimeImmutable($sourceStart))->format('Y-m-t');
        } else {
            $sourceStart = $rawSourceStart;
            if (!$this->isValidDate($sourceStart)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid source start date is required (YYYY-MM-DD).']);
                return;
            }
            if ($mode === 'day') {
                $sourceEnd = $sourceStart;
            } elseif ($mode === 'week' && !$this->isValidDate($rawSourceEnd)) {
                $sourceEnd = (new \DateTimeImmutable($sourceStart))->modify('+6 days')->format('Y-m-d');
            } else {
                $sourceEnd = $this->isValidDate($rawSourceEnd) ? $rawSourceEnd : $sourceStart;
            }
        }

        $srcStmt = $db->prepare("
            SELECT recipe_id, entry_type, plan_date, meal_slot, custom_item_json, servings, notes, addons_json
            FROM meal_plan_entries
            WHERE user_id = ? AND plan_date BETWEEN ? AND ?
            ORDER BY plan_date ASC, FIELD(meal_slot, 'breakfast', 'lunch', 'dinner', 'snack') ASC, id ASC
        ");
        $srcStmt->execute([$userId, $sourceStart, $sourceEnd]);
        $sourceRows = $srcStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($sourceRows)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No planned meals found in the selected source period to copy.']);
            return;
        }

        $placements = [];
        $overwriteRanges = [];

        if ($mode === 'month') {
            $tBaseDt = new \DateTimeImmutable($targetBaseStart);
            for ($rep = 0; $rep < $repeatCount; $rep++) {
                $tMonthDt = $tBaseDt->modify("+{$rep} months");
                $tYear = (int)$tMonthDt->format('Y');
                $tMonth = (int)$tMonthDt->format('n');
                $daysInTarget = (int)$tMonthDt->format('t');
                $overwriteRanges[] = [$tMonthDt->format('Y-m-01'), $tMonthDt->format('Y-m-t')];

                foreach ($sourceRows as $src) {
                    $dom = (int)substr((string)$src['plan_date'], 8, 2);
                    $clampedDay = min($dom, $daysInTarget);
                    $targetYmd = sprintf('%04d-%02d-%02d', $tYear, $tMonth, $clampedDay);
                    $placements[] = ['date' => $targetYmd, 'row' => $src];
                }
            }
        } elseif ($mode === 'day' && !empty($rawTargetDates)) {
            foreach ($rawTargetDates as $td) {
                $tdStr = trim((string)$td);
                if (!$this->isValidDate($tdStr)) {
                    continue;
                }
                $overwriteRanges[] = [$tdStr, $tdStr];
                foreach ($sourceRows as $src) {
                    $placements[] = ['date' => $tdStr, 'row' => $src];
                }
            }
        } else {
            if (!$this->isValidDate($rawTargetStart)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Valid target start date is required (YYYY-MM-DD).']);
                return;
            }
            $sStartDt = new \DateTimeImmutable($sourceStart);
            $sEndDt = new \DateTimeImmutable($sourceEnd);
            $spanDays = max(1, (int)$sStartDt->diff($sEndDt)->days + 1);
            $tBaseDt = new \DateTimeImmutable($rawTargetStart);

            for ($rep = 0; $rep < $repeatCount; $rep++) {
                $blockStartDt = $tBaseDt->modify('+' . ($rep * $spanDays) . ' days');
                $blockEndDt = $blockStartDt->modify('+' . ($spanDays - 1) . ' days');
                $overwriteRanges[] = [$blockStartDt->format('Y-m-d'), $blockEndDt->format('Y-m-d')];

                foreach ($sourceRows as $src) {
                    $srcDt = new \DateTimeImmutable((string)$src['plan_date']);
                    $offsetDays = (int)$sStartDt->diff($srcDt)->format('%r%a');
                    if ($offsetDays < 0) {
                        $offsetDays = 0;
                    }
                    $targetYmd = $blockStartDt->modify("+{$offsetDays} days")->format('Y-m-d');
                    $placements[] = ['date' => $targetYmd, 'row' => $src];
                }
            }
        }

        if (empty($placements)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No valid target dates selected.']);
            return;
        }

        try {
            $db->beginTransaction();

            if ($overwrite && !empty($overwriteRanges)) {
                $delRangeStmt = $db->prepare("DELETE FROM meal_plan_entries WHERE user_id = ? AND plan_date BETWEEN ? AND ?");
                foreach ($overwriteRanges as [$rStart, $rEnd]) {
                    $delRangeStmt->execute([$userId, $rStart, $rEnd]);
                }
            }

            $insStmt = $db->prepare("
                INSERT INTO meal_plan_entries (user_id, recipe_id, entry_type, plan_date, meal_slot, custom_item_json, servings, notes, addons_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $copiedCount = 0;
            foreach ($placements as $pl) {
                $row = $pl['row'];
                $insStmt->execute([
                    $userId,
                    $row['recipe_id'] !== null ? (int)$row['recipe_id'] : null,
                    ($row['entry_type'] ?? 'recipe') === 'usda' ? 'usda' : 'recipe',
                    $pl['date'],
                    $row['meal_slot'],
                    $row['custom_item_json'] ?? null,
                    (float)$row['servings'],
                    $row['notes'] ?? null,
                    $row['addons_json'] ?? null,
                ]);
                $copiedCount++;
            }

            $db->commit();

            echo json_encode([
                'success' => true,
                'copiedCount' => $copiedCount,
                'targetStart' => $overwriteRanges[0][0] ?? null,
                'targetEnd' => $overwriteRanges[count($overwriteRanges) - 1][1] ?? null,
                'message' => "Copied {$copiedCount} meal" . ($copiedCount === 1 ? '' : 's') . "!",
            ]);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to copy meal plan: ' . $e->getMessage()]);
        }
    }

    /**
     * POST /meal-plans/share
     * Generates a public shareable link snapshot of a day, week, or month meal plan.
     */
    public function createSharedMealPlan() {
        $userId = $this->requireAuth();
        $db = Database::connect();
        $this->ensureTableExists($db);
        $this->ensureFiberBackfilled($db);

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = [];
        }

        $scope = strtolower(trim((string)($data['scope'] ?? 'week')));
        if (!in_array($scope, ['day', 'week', 'month'], true)) {
            $scope = 'week';
        }

        $startDate = trim((string)($data['start_date'] ?? ''));
        $endDate = trim((string)($data['end_date'] ?? ''));

        if ($scope === 'month' && preg_match('/^\d{4}-\d{2}$/', $startDate)) {
            $startDate .= '-01';
            $endDate = (new \DateTimeImmutable($startDate))->format('Y-m-t');
        }
        if (!$this->isValidDate($startDate)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid start_date is required.']);
            return;
        }
        if (!$this->isValidDate($endDate)) {
            $endDate = $startDate;
        }

        $sql = "
            SELECT mp.id, mp.user_id, mp.recipe_id, mp.entry_type, mp.plan_date, mp.meal_slot,
                   mp.custom_item_json, mp.servings, mp.notes, mp.addons_json,
                   r.title, r.description, r.notes AS recipe_notes, r.image_url, r.image_source, r.is_public,
                   r.is_oil_free, r.is_gluten_free, r.is_sugar_free,
                   r.prep_time_mins, r.cook_time_mins, r.yields, r.user_id AS recipe_author_id,
                   u.username AS author_name,
                   m.calories, m.protein_g, m.carbs_g, m.fat_g, m.fiber_g
            FROM meal_plan_entries mp
            LEFT JOIN recipes r ON r.id = mp.recipe_id
            LEFT JOIN users u ON u.id = r.user_id
            LEFT JOIN macros m ON m.recipe_id = r.id
            WHERE mp.user_id = ?
              AND mp.plan_date BETWEEN ? AND ?
              AND (mp.entry_type = 'usda' OR mp.recipe_id IS NULL OR mp.recipe_id = 0 OR r.is_public = 1 OR r.user_id = ?)
            ORDER BY mp.plan_date ASC,
                     FIELD(mp.meal_slot, 'breakfast', 'lunch', 'dinner', 'snack') ASC,
                     mp.id ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId, $startDate, $endDate, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($rows)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No planned meals found in this period to share. Add some meals first!']);
            return;
        }

        $hydrated = $this->hydrateEntries($db, $rows);
        $startDt = new \DateTimeImmutable($startDate);

        $snapshot = array_map(function ($entry) use ($startDt) {
            $eDt = new \DateTimeImmutable((string)$entry['planDate']);
            $dayOffset = max(0, (int)$startDt->diff($eDt)->format('%r%a'));
            $dom = (int)$eDt->format('j');
            return [
                'dayOffset' => $dayOffset,
                'dayOfMonth' => $dom,
                'planDate' => $entry['planDate'],
                'mealSlot' => $entry['mealSlot'],
                'entryType' => $entry['entryType'],
                'recipeId' => $entry['recipeId'],
                'isPublic' => (bool)$entry['isPublic'],
                'title' => $entry['title'],
                'description' => $entry['description'] ?? '',
                'imageUrl' => $entry['imageUrl'] ?? null,
                'authorName' => $entry['authorName'] ?? 'Emma (AI)',
                'servings' => (float)$entry['servings'],
                'notes' => $entry['notes'] ?? '',
                'calories' => $entry['calories'] ?? 0,
                'protein_g' => $entry['protein_g'] ?? 0,
                'carbs_g' => $entry['carbs_g'] ?? 0,
                'fat_g' => $entry['fat_g'] ?? 0,
                'fiber_g' => $entry['fiber_g'] ?? 0,
                'micros' => $entry['micros'] ?? [],
                'customItem' => $entry['customItem'] ?? null,
                'addons' => $entry['addons'] ?? [],
            ];
        }, $hydrated);

        $userStmt = $db->prepare("SELECT username, display_name FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$userId]);
        $uRow = $userStmt->fetch(PDO::FETCH_ASSOC);
        $displayOwner = trim((string)($uRow['display_name'] ?? $uRow['username'] ?? 'Vault Chef'));

        $defaultTitle = match ($scope) {
            'day' => "{$displayOwner}'s Day Meal Plan",
            'month' => "{$displayOwner}'s Monthly Meal Plan",
            default => "{$displayOwner}'s Weekly Meal Plan",
        };
        $title = substr(trim((string)($data['title'] ?? '')), 0, 160) ?: $defaultTitle;
        $description = substr(trim((string)($data['description'] ?? '')), 0, 500);
        $shareToken = bin2hex(random_bytes(8));

        $insShare = $db->prepare("
            INSERT INTO meal_plan_shares (share_token, user_id, title, description, scope, start_date, end_date, entries_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insShare->execute([
            $shareToken,
            $userId,
            $title,
            $description !== '' ? $description : null,
            $scope,
            $startDate,
            $endDate,
            json_encode($snapshot),
        ]);

        echo json_encode([
            'success' => true,
            'shareToken' => $shareToken,
            'title' => $title,
            'scope' => $scope,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'entryCount' => count($snapshot),
        ]);
    }

    /**
     * GET /meal-plans/share?token=...
     * Public endpoint returning a shared meal plan snapshot for viewing and importing.
     */
    public function getSharedMealPlan() {
        $db = Database::connect();
        $this->ensureTableExists($db);

        $token = trim((string)($_GET['token'] ?? ''));
        if ($token === '' || !preg_match('/^[a-zA-Z0-9_-]{8,32}$/', $token)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid shared meal plan link.']);
            return;
        }

        $stmt = $db->prepare("
            SELECT s.*, u.username, u.display_name, u.profile_image
            FROM meal_plan_shares s
            LEFT JOIN users u ON u.id = s.user_id
            WHERE s.share_token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Shared meal plan not found or no longer available.']);
            return;
        }

        try {
            $db->prepare("UPDATE meal_plan_shares SET view_count = view_count + 1 WHERE id = ?")->execute([(int)$row['id']]);
        } catch (\Throwable $e) {
            // Non-fatal
        }

        $entries = json_decode((string)$row['entries_json'], true);
        if (!is_array($entries)) {
            $entries = [];
        }

        echo json_encode([
            'success' => true,
            'share' => [
                'token' => $row['share_token'],
                'title' => $row['title'],
                'description' => $row['description'] ?? '',
                'scope' => $row['scope'],
                'startDate' => $row['start_date'],
                'endDate' => $row['end_date'],
                'viewCount' => (int)$row['view_count'] + 1,
                'importCount' => (int)$row['import_count'],
                'createdAt' => $row['created_at'],
                'author' => [
                    'id' => (int)$row['user_id'],
                    'username' => $row['username'] ?? 'member',
                    'displayName' => $row['display_name'] ?: ($row['username'] ?? 'Vault Member'),
                    'profileImage' => $row['profile_image'] ?? null,
                ],
                'entries' => $entries,
            ],
        ]);
    }

    /**
     * POST /meal-plans/import-share
     * Copies a shared meal plan snapshot into the logged-in user's personal calendar.
     */
    public function importSharedMealPlan() {
        $userId = $this->requireAuth();
        $db = Database::connect();
        $this->ensureTableExists($db);

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = [];
        }

        $token = trim((string)($data['token'] ?? ''));
        $rawTargetStart = trim((string)($data['target_start_date'] ?? ''));
        $overwrite = !empty($data['overwrite']);

        if ($token === '' || !preg_match('/^[a-zA-Z0-9_-]{8,32}$/', $token)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid share token.']);
            return;
        }

        $stmt = $db->prepare("SELECT * FROM meal_plan_shares WHERE share_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $shareRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shareRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Shared meal plan not found.']);
            return;
        }

        $scope = $shareRow['scope'] ?? 'week';
        if ($scope === 'month') {
            $targetStart = $this->normalizeMonthStart($rawTargetStart ?: date('Y-m-01'));
        } else {
            $targetStart = $this->isValidDate($rawTargetStart) ? $rawTargetStart : date('Y-m-d');
        }
        if (!$targetStart) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Please choose a valid target start date.']);
            return;
        }

        $snapshotEntries = json_decode((string)$shareRow['entries_json'], true);
        if (!is_array($snapshotEntries) || empty($snapshotEntries)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'This shared meal plan contains no entries.']);
            return;
        }

        // Collect all referenced recipe IDs to see which are accessible to the importing user
        $allRecipeIds = [];
        foreach ($snapshotEntries as $item) {
            if (!empty($item['recipeId'])) {
                $allRecipeIds[] = (int)$item['recipeId'];
            }
            if (!empty($item['addons']) && is_array($item['addons'])) {
                foreach ($item['addons'] as $ad) {
                    if (is_array($ad) && ($ad['type'] ?? '') === 'recipe' && !empty($ad['recipeId'])) {
                        $allRecipeIds[] = (int)$ad['recipeId'];
                    }
                }
            }
        }
        $metaMap = $this->fetchRecipeMetaByIds($db, $allRecipeIds);

        $isRecipeAccessible = function (int $rid) use ($metaMap, $userId): bool {
            if ($rid <= 0 || !isset($metaMap[$rid])) {
                return false;
            }
            $m = $metaMap[$rid];
            return ((int)$m['is_public'] === 1 || (int)$m['user_id'] === $userId);
        };

        $tStartDt = new \DateTimeImmutable($targetStart);
        $targetYear = (int)$tStartDt->format('Y');
        $targetMonth = (int)$tStartDt->format('n');
        $daysInTargetMonth = (int)$tStartDt->format('t');

        $preparedRows = [];
        $minDate = $targetStart;
        $maxDate = $targetStart;

        foreach ($snapshotEntries as $item) {
            $dayOffset = max(0, (int)($item['dayOffset'] ?? 0));
            if ($scope === 'month') {
                $dom = max(1, (int)($item['dayOfMonth'] ?? ($dayOffset + 1)));
                $clampedDay = min($dom, $daysInTargetMonth);
                $planDate = sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, $clampedDay);
            } else {
                $planDate = $tStartDt->modify("+{$dayOffset} days")->format('Y-m-d');
            }
            if ($planDate < $minDate) $minDate = $planDate;
            if ($planDate > $maxDate) $maxDate = $planDate;

            $slot = $this->normalizeSlot($item['mealSlot'] ?? 'dinner');
            $servings = isset($item['servings']) ? max(0.25, min(20.0, round((float)$item['servings'], 2))) : 1.0;
            $notes = substr(trim((string)($item['notes'] ?? '')), 0, 255);

            // Normalize addons, preserving private addon nutrition as snapshot if inaccessible
            $cleanAddons = [];
            if (!empty($item['addons']) && is_array($item['addons'])) {
                foreach (array_slice($item['addons'], 0, 15) as $adIdx => $ad) {
                    if (!is_array($ad)) continue;
                    if (($ad['type'] ?? '') === 'recipe') {
                        $adRid = (int)($ad['recipeId'] ?? 0);
                        if ($isRecipeAccessible($adRid)) {
                            $cleanAddons[] = [
                                'id' => $ad['id'] ?? ('rec-' . $adRid . '-' . $adIdx),
                                'type' => 'recipe',
                                'category' => $ad['category'] ?? 'sauce',
                                'recipeId' => $adRid,
                                'title' => (string)($ad['title'] ?? 'Sauce/Side Recipe'),
                                'servings' => max(0.25, min(20.0, (float)($ad['servings'] ?? 1.0))),
                            ];
                        } else {
                            // Snapshot nutrition from private recipe addon as custom item
                            $adServ = max(0.25, (float)($ad['servings'] ?? 1.0));
                            $cleanAddons[] = [
                                'id' => 'shared-' . $adIdx . '-' . uniqid(),
                                'type' => 'usda',
                                'category' => $ad['category'] ?? 'sauce',
                                'fdcId' => 0,
                                'title' => (string)($ad['title'] ?? 'Shared Side/Sauce'),
                                'dataType' => 'Shared Snapshot',
                                'amount' => $adServ,
                                'unit' => 'serving',
                                'grams' => 100,
                                'calories' => (int)round(((float)($ad['calories'] ?? 0)) * $adServ),
                                'protein_g' => round(((float)($ad['protein_g'] ?? 0)) * $adServ, 1),
                                'carbs_g' => round(((float)($ad['carbs_g'] ?? 0)) * $adServ, 1),
                                'fat_g' => round(((float)($ad['fat_g'] ?? 0)) * $adServ, 1),
                                'fiber_g' => round(((float)($ad['fiber_g'] ?? 0)) * $adServ, 1),
                                'micros' => is_array($ad['micros'] ?? null) ? $ad['micros'] : [],
                            ];
                        }
                    } elseif (($ad['type'] ?? '') === 'usda') {
                        $cleanAddons[] = $ad;
                    }
                }
            }
            $addonsJson = !empty($cleanAddons) ? json_encode($cleanAddons) : null;

            $origType = ($item['entryType'] ?? 'recipe') === 'usda' ? 'usda' : 'recipe';
            $origRid = (int)($item['recipeId'] ?? 0);

            if ($origType === 'recipe' && $isRecipeAccessible($origRid)) {
                $preparedRows[] = [
                    'recipe_id' => $origRid,
                    'entry_type' => 'recipe',
                    'plan_date' => $planDate,
                    'meal_slot' => $slot,
                    'custom_item_json' => null,
                    'servings' => $servings,
                    'notes' => $notes !== '' ? $notes : null,
                    'addons_json' => $addonsJson,
                ];
            } elseif ($origType === 'usda' && is_array($item['customItem'] ?? null)) {
                $preparedRows[] = [
                    'recipe_id' => null,
                    'entry_type' => 'usda',
                    'plan_date' => $planDate,
                    'meal_slot' => $slot,
                    'custom_item_json' => json_encode($item['customItem']),
                    'servings' => $servings,
                    'notes' => $notes !== '' ? $notes : null,
                    'addons_json' => $addonsJson,
                ];
            } else {
                // Convert private recipe from author into a custom nutrition snapshot
                $snapCustom = [
                    'id' => 'shared-' . uniqid(),
                    'type' => 'usda',
                    'category' => 'side',
                    'fdcId' => 0,
                    'title' => (string)($item['title'] ?? 'Shared Meal'),
                    'dataType' => 'Shared Recipe Snapshot',
                    'amount' => 1,
                    'unit' => 'serving',
                    'grams' => 100,
                    'calories' => (int)($item['calories'] ?? 0),
                    'protein_g' => (float)($item['protein_g'] ?? 0),
                    'carbs_g' => (float)($item['carbs_g'] ?? 0),
                    'fat_g' => (float)($item['fat_g'] ?? 0),
                    'fiber_g' => (float)($item['fiber_g'] ?? 0),
                    'micros' => is_array($item['micros'] ?? null) ? $item['micros'] : [],
                ];
                $preparedRows[] = [
                    'recipe_id' => null,
                    'entry_type' => 'usda',
                    'plan_date' => $planDate,
                    'meal_slot' => $slot,
                    'custom_item_json' => json_encode($snapCustom),
                    'servings' => $servings,
                    'notes' => $notes !== '' ? $notes : null,
                    'addons_json' => $addonsJson,
                ];
            }
        }

        if ($scope === 'month') {
            $overwriteStart = $tStartDt->format('Y-m-01');
            $overwriteEnd = $tStartDt->format('Y-m-t');
        } else {
            $overwriteStart = $minDate;
            $overwriteEnd = $maxDate;
        }

        try {
            $db->beginTransaction();

            if ($overwrite) {
                $delStmt = $db->prepare("DELETE FROM meal_plan_entries WHERE user_id = ? AND plan_date BETWEEN ? AND ?");
                $delStmt->execute([$userId, $overwriteStart, $overwriteEnd]);
            }

            $insStmt = $db->prepare("
                INSERT INTO meal_plan_entries (user_id, recipe_id, entry_type, plan_date, meal_slot, custom_item_json, servings, notes, addons_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($preparedRows as $pRow) {
                $insStmt->execute([
                    $userId,
                    $pRow['recipe_id'],
                    $pRow['entry_type'],
                    $pRow['plan_date'],
                    $pRow['meal_slot'],
                    $pRow['custom_item_json'],
                    $pRow['servings'],
                    $pRow['notes'],
                    $pRow['addons_json'],
                ]);
            }

            $db->prepare("UPDATE meal_plan_shares SET import_count = import_count + 1 WHERE id = ?")
                ->execute([(int)$shareRow['id']]);

            $db->commit();

            $count = count($preparedRows);
            echo json_encode([
                'success' => true,
                'importedCount' => $count,
                'targetStartDate' => $targetStart,
                'message' => "Copied {$count} meal" . ($count === 1 ? '' : 's') . " into your Meal Planner!",
            ]);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to import shared meal plan: ' . $e->getMessage()]);
        }
    }
}
