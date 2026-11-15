<?php

namespace App\Controllers;

use PDO;
use Exception;
use App\Database;

class RecipeController {
    private $db;

    public function __construct() {
        $database = new Database();
        
        // Note: 'getConnection()' is the standard name. If your Database.php uses 
        // something like 'connect()' instead, just swap the word below!
        $this->db = $database->connect(); 
    }

    /**
     * Auto-backfill legacy rows if the live recipes table has is_gluten_free defaulting to 0 everywhere.
     */
    private function ensureGlutenFreeBackfilled(\PDO $db): void {
        try {
            $counts = $db->query("SELECT COUNT(*) AS total, COALESCE(SUM(is_gluten_free), 0) AS gf_count FROM recipes")->fetch(\PDO::FETCH_ASSOC);
            if (!$counts || (int)$counts['total'] === 0 || (int)$counts['gf_count'] > 0) {
                return;
            }
            $db->exec("
                UPDATE recipes r
                SET r.is_gluten_free = CASE
                    WHEN LOWER(CONCAT_WS(' ', r.title, r.description, r.notes)) REGEXP '(^|[^a-z])(gluten[ -]?free|certified gf|golden gf)([^a-z]|$)'
                         AND LOWER(CONCAT_WS(' ', r.title, r.description, r.notes)) NOT REGEXP 'not[ ]+gluten[ -]?free' THEN 1
                    WHEN LOWER(r.title) REGEXP '(^|[^a-z])(wheat|bread|breadstick|breadsticks|sandwich|sub|wellington|strudel|pretzel|pretzels|pierogi|pierogies|galette|empanada|empanadas|lasagna|mac &|macaroni|spaghetti|fettuccine|linguine|penne|pasta|noodles|noodle|ramen|udon|couscous|barley|rye|spelt|farro|orzo|seitan|pancake|pancakes|waffle|waffles|muffin|muffins|cobbler|flautas|burrito|burritos|toast|hoagie|baguette|ciabatta|sourdough|pita)([^a-z]|$)' THEN 0
                    WHEN EXISTS (
                        SELECT 1 FROM ingredients i
                        WHERE i.recipe_id = r.id
                          AND LOWER(i.ingredient_name) REGEXP '(whole[- ]?wheat|wheat flour|all-purpose flour|bread flour|pastry flour|white flour|spelt|barley|rye|couscous|farro|orzo|seitan|vital wheat gluten|puff pastry|phyllo|filo|breadcrumbs|panko|soy sauce|gochujang|lasagna|linguine|spaghetti|fettuccine|penne|macaroni|ramen|udon|flour tortilla|hoagie|baguette|ciabatta|sourdough|crusty italian|pita)'
                          AND LOWER(i.ingredient_name) NOT REGEXP '(gluten[ -]?free|tamari|coconut aminos|brown rice|rice noodle|rice flour|oat flour|almond flour|chickpea flour|corn tortilla|buckwheat)'
                    ) THEN 0
                    ELSE 1
                END
            ");
        } catch (\Throwable $e) {
            // Non-fatal: continue returning recipes cleanly even if column isn't writable
        }
    }

    /**
     * Fetches the high-level list of all recipes for the Home Grid.
     * Includes core macros for the quick-view badges!
     */
public function getAllRecipes() {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
            $db = Database::connect();
            $this->ensureGlutenFreeBackfilled($db);
            
            // Check if the user is an admin (assuming you start the session at the top of the method)
            $isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;

            // Base query — prioritize recipes created by users that the logged-in user follows
            $sql = "SELECT r.id, r.user_id, r.title, r.description, r.image_url, r.yields, 
                        r.prep_time_mins, r.cook_time_mins, r.is_wfpb, r.is_oil_free,
                        r.is_gluten_free, r.is_sugar_free,
                        r.is_public, r.image_source, r.created_at, r.average_rating, r.rating_count,
                        u.username AS author_name,
                        u.display_name AS author_display_name,
                        u.profile_image AS author_avatar,
                        CASE WHEN rf.recipe_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite,
                        CASE WHEN uf.followed_id IS NOT NULL THEN 1 ELSE 0 END AS is_followed_author,
                        (SELECT GROUP_CONCAT(i.ingredient_name SEPARATOR '||') FROM ingredients i WHERE i.recipe_id = r.id) AS ingredient_names
                    FROM recipes r
                    LEFT JOIN users u ON r.user_id = u.id
                    LEFT JOIN recipe_favorites rf ON rf.recipe_id = r.id AND rf.user_id = :fav_user_id
                    LEFT JOIN user_follows uf ON uf.followed_id = r.user_id AND uf.follower_id = :follow_user_id";

            // Only hide non-public recipes if the user IS NOT an admin
            if (!$isAdmin) {
                $sql .= " WHERE r.is_public = 1";
            }

            $sql .= " ORDER BY is_followed_author DESC, r.created_at DESC";
                    
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':fav_user_id' => $currentUserId,
                ':follow_user_id' => $currentUserId
            ]);
            $recipes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $formatted = array_map(function($r) {
                $ingredients = !empty($r['ingredient_names'])
                    ? array_values(array_filter(array_map('trim', explode('||', $r['ingredient_names']))))
                    : [];
                return [
                    'id' => $r['id'],
                    'user_id' => $r['user_id'] ? (int)$r['user_id'] : null,
                    'authorId' => $r['user_id'] ? (int)$r['user_id'] : null,
                    'title' => $r['title'],
                    'authorName' => $r['author_name'] ?? 'Emma (AI)', // Falls back to me if no user is found!
                    'authorDisplayName' => $r['author_display_name'] ?? null,
                    'authorAvatar' => $r['author_avatar'] ?? null,
                    'isFollowedAuthor' => (bool)$r['is_followed_author'],
                    'description' => $r['description'],
                    'ingredients' => $ingredients,
                    'prepTime' => $r['prep_time_mins'],
                    'cookTime' => $r['cook_time_mins'],
                    'imageUrl' => $r['image_url'],
                    'yields' => $r['yields'],
                    'isWfpb' => (bool)$r['is_wfpb'], 
                    'is_oil_free' => (bool)$r['is_oil_free'],
                    'isOilFree' => (bool)$r['is_oil_free'],
                    'is_gluten_free' => (bool)($r['is_gluten_free'] ?? 0),
                    'isGlutenFree' => (bool)($r['is_gluten_free'] ?? 0),
                    'is_sugar_free' => (bool)($r['is_sugar_free'] ?? 1),
                    'isSugarFree' => (bool)($r['is_sugar_free'] ?? 1),
                    'isDraft' => !(bool)$r['is_public'], 
                    'isPublic' => (bool)$r['is_public'],
                    'imageSource' => $r['image_source'],
                    'createdAt' => $r['created_at'],
                    'averageRating' => (float)($r['average_rating'] ?? 0),
                    'ratingCount' => (int)($r['rating_count'] ?? 0),
                    'is_favorite' => (bool)$r['is_favorite'],
                    'isFavorited' => (bool)$r['is_favorite']
                ];
            }, $recipes);

            echo json_encode($formatted);
            
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
    }     /* Saves a beautifully generated recipe straight from Emmas Engine.
     * Base64 image interceptor to reduce the file size
     */

    /**
     * Auto-backfill fiber_g in macros when legacy rows have NULL/0 fiber despite carbohydrate & plant ingredients.
     */
    private function ensureFiberBackfilled(\PDO $db): void {
        try {
            $missingCount = (int)$db->query("SELECT COUNT(*) FROM macros WHERE fiber_g IS NULL OR fiber_g = 0")->fetchColumn();
            if ($missingCount <= 0) {
                return;
            }

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

    /**
     * Fetch a single recipe with all its glorious relational data.
     * Upgraded to fetch the author's username!
     */
    public function getRecipeById() {
        // 1. Grab the ID directly from the URL query string
        $id = $_GET['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing ID. I cannot fetch a ghost.']);
            return;
        }

        try {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
            $this->ensureFiberBackfilled($this->db);

            // 2. Fetch the overarching recipe details AND the author's username & profile metadata
            $sql = "SELECT r.*, u.username AS author_name,
                           u.display_name AS author_display_name,
                           u.profile_image AS author_avatar,
                           u.location AS author_location
                    FROM recipes r 
                    LEFT JOIN users u ON r.user_id = u.id 
                    WHERE r.id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$id]);
            $recipe = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$recipe) {
                http_response_code(404);
                echo json_encode(['error' => 'Recipe not found. Check the vault again.']);
                return;
            }

            $authorId = $recipe['user_id'] ? (int)$recipe['user_id'] : null;
            $isFollowedAuthor = false;
            if ($currentUserId > 0 && $authorId && $authorId !== $currentUserId) {
                $followStmt = $this->db->prepare("SELECT 1 FROM user_follows WHERE follower_id = ? AND followed_id = ? LIMIT 1");
                $followStmt->execute([$currentUserId, $authorId]);
                $isFollowedAuthor = (bool)$followStmt->fetchColumn();
            }

            // Map the author metadata for React
            $recipe['authorId'] = $authorId;
            $recipe['authorName'] = $recipe['author_name'] ?? 'Emma (AI)';
            $recipe['authorDisplayName'] = $recipe['author_display_name'] ?? null;
            $recipe['authorAvatar'] = $recipe['author_avatar'] ?? null;
            $recipe['authorLocation'] = $recipe['author_location'] ?? null;
            $recipe['isFollowedAuthor'] = $isFollowedAuthor;
            $recipe['isOilFree'] = (bool)($recipe['is_oil_free'] ?? 0);
            $recipe['isGlutenFree'] = (bool)($recipe['is_gluten_free'] ?? 0);
            $recipe['isSugarFree'] = (bool)($recipe['is_sugar_free'] ?? 1);
            // Ensure camelCase imageUrl is present
            $recipe['imageUrl'] = $recipe['image_url'];

            // 3. Fetch the ingredients
            $stmt = $this->db->prepare("SELECT * FROM ingredients WHERE recipe_id = ?");
            $stmt->execute([$id]);
            $recipe['ingredients'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // 4. Fetch the instructions (ordered properly!)
            $stmt = $this->db->prepare("SELECT * FROM instructions WHERE recipe_id = ? ORDER BY step_number ASC");
            $stmt->execute([$id]);
            $recipe['instructions'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // 5. Fetch the macros
            $stmt = $this->db->prepare("SELECT * FROM macros WHERE recipe_id = ?");
            $stmt->execute([$id]);
            $recipe['macros'] = $stmt->fetch(\PDO::FETCH_ASSOC);

            // 6. Fetch the comprehensive micros
            $stmt = $this->db->prepare("SELECT * FROM micros WHERE recipe_id = ?");
            $stmt->execute([$id]);
            $recipe['micros'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // 7. Echo the pristine, perfectly structured JSON back to React!
            echo json_encode($recipe);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch recipe: ' . $e->getMessage()]);
        }
    }
    public function saveGeneratedRecipe() {
        $db = Database::connect();
        $data = json_decode(file_get_contents("php://input"), true);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $userId = $_SESSION['user_id'] ?? null;

        // 1. Unpack the data from React
        $title = $data['title'] ?? 'Emma\'s AI Creation';
        $username = $data['username'] ?? 'Emma';
        $description = $data['description'] ?? 'A glorious AI-generated WFPB meal.';
        $ingredients = $data['ingredients'] ?? [];
        $instructions = $data['instructions'] ?? [];
        $prepTime = $data['prepTime'] ?? 0;
        $cookTime = $data['cookTime'] ?? 0;
        $rawYields = $data['yields'] ?? '4 servings';
        $yields = substr($rawYields, 0, 50);
        $notes = $data['notes'] ?? '';
        $isOilFree = isset($data['isOilFree']) ? (int)(bool)$data['isOilFree'] : 1;
        $isGlutenFree = isset($data['isGlutenFree']) ? (int)(bool)$data['isGlutenFree'] : 0;
        $isSugarFree = isset($data['isSugarFree']) ? (int)(bool)$data['isSugarFree'] : 1;

        $isPublic = 0; 
        $imageSource = 'none';
        
        $rawImageUrl = $data['imageUrl'] ?? ''; 
        $finalImageUrl = '';

        // 2. Handle the Image
        if (!empty($rawImageUrl) && strpos($rawImageUrl, 'data:image') === 0) {
            list($type, $imageData) = explode(';', $rawImageUrl);
            list(, $imageData)      = explode(',', $imageData);
            $decodedData = base64_decode($imageData);
            
            $ext = 'jpg';
            if (str_contains($type, 'png')) $ext = 'png';
            if (str_contains($type, 'webp')) $ext = 'webp';

            $filename = time() . '_ai_recipe_img.' . $ext;
            $uploadDir = __DIR__ . '/../../public/images/';
            
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            file_put_contents($uploadDir . $filename, $decodedData);
            $finalImageUrl = '/images/' . $filename;
            
            $imageSource = 'ai'; 
            $hasMasterpieceNutrition = (!empty($data['micros']) && is_array($data['micros'])) || str_contains($notes, 'Advanced Nutritional Analysis');
            $isPublic = $hasMasterpieceNutrition ? 1 : 0; // Only publish if both image and full nutrition are included
        } else {
            $finalImageUrl = $rawImageUrl ?: '/images/default-veggie-vault-placeholder.jpg';
        }

        // 3. The SQL Transaction (All or Nothing!)
        // 3. The SQL Transaction (All or Nothing!)
        try {
            $db->beginTransaction();

            // --- STEP A: Insert the Master Recipe ---
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $userId = $_SESSION['user_id'] ?? null;

            $sqlRecipe = "INSERT INTO recipes 
                (user_id, title, description, notes, image_url, yields, prep_time_mins, cook_time_mins, is_wfpb, is_oil_free, is_gluten_free, is_sugar_free, is_public, image_source) 
                VALUES 
                (:user_id, :title, :description, :notes, :image_url, :yields, :prep_time_mins, :cook_time_mins, 1, :is_oil_free, :is_gluten_free, :is_sugar_free, :is_public, :image_source)";
            
            $stmtRecipe = $db->prepare($sqlRecipe);
            $stmtRecipe->execute([
                ':user_id' => $userId,
                ':title' => $title,
                ':description' => $description,
                ':notes' => $notes,
                ':image_url' => $finalImageUrl,
                ':yields' => $yields,
                ':prep_time_mins' => $prepTime,
                ':cook_time_mins' => $cookTime,
                ':is_oil_free' => $isOilFree,
                ':is_gluten_free' => $isGlutenFree,
                ':is_sugar_free' => $isSugarFree,
                ':is_public' => $isPublic,
                ':image_source' => $imageSource
            ]);
            
            $recipeId = $db->lastInsertId();

            // Auto-vault this masterpiece into the user's favorites
            if ($userId && $recipeId) {
                $favStmt = $db->prepare("INSERT IGNORE INTO recipe_favorites (user_id, recipe_id) VALUES (?, ?)");
                $favStmt->execute([$userId, $recipeId]);
            }

            // STEP B: Insert Instructions
            if (!empty($instructions)) {
                $sqlInst = "INSERT INTO instructions (recipe_id, step_number, instruction_text) VALUES (:recipe_id, :step_number, :instruction_text)";
                $stmtInst = $db->prepare($sqlInst);
                foreach ($instructions as $index => $stepText) {
                    $stmtInst->execute([
                        ':recipe_id' => $recipeId,
                        ':step_number' => $index + 1,
                        ':instruction_text' => $stepText
                    ]);
                }
            }

            // STEP C: Insert Ingredients
            if (!empty($ingredients)) {
                $sqlIng = "INSERT INTO ingredients (recipe_id, quantity, unit, ingredient_name) VALUES (:recipe_id, :quantity, :unit, :ingredient_name)";
                $stmtIng = $db->prepare($sqlIng);
                foreach ($ingredients as $ingText) {
                    // Check if React sent an object or a plain string
                    $ingName = is_array($ingText) ? ($ingText['ingredient_name'] ?? json_encode($ingText)) : $ingText;
                    
                    $stmtIng->execute([
                        ':recipe_id' => $recipeId,
                        ':quantity' => 0.00, 
                        ':unit' => '',
                        ':ingredient_name' => $ingName 
                    ]);
                }
            }

            // STEP D: Insert Macros
            $calories = $data['calories'] ?? 0;
            $protein_g = $data['protein_g'] ?? 0;
            $carbs_g = $data['carbs_g'] ?? 0;
            $fat_g = $data['fat_g'] ?? 0;
            $fiber_g = $data['fiber_g'] ?? 0;

            // Only insert if we actually captured some nutritional data
            if ($calories > 0 || $protein_g > 0) {
                $sqlMacros = "INSERT INTO macros (recipe_id, calories, protein_g, carbs_g, fat_g, fiber_g, math_calculations)
                        VALUES (:recipe_id, :calories, :protein_g, :carbs_g, :fat_g, :fiber_g, :math_calculations)";
                $stmtMacros = $db->prepare($sqlMacros);

                $stmtMacros->execute([
                    ':recipe_id' => $recipeId,
                    ':calories' => (float)$calories,
                    ':protein_g' => (float)$protein_g,
                    ':carbs_g' => (float)$carbs_g,
                    ':fat_g' => (float)$fat_g,
                    ':fiber_g' => (float)$fiber_g,
                    ':math_calculations' => ''
                ]);
            }

            // STEP E: Insert Micros (If they clicked Deep Dive before saving)
            $micros = $data['micros'] ?? [];
            if (!empty($micros) && is_array($micros)) {
                $sqlMicros = "INSERT INTO micros (recipe_id, nutrient_name, amount, unit, daily_value_percentage, math_calculations)
                              VALUES (:recipe_id, :nutrient_name, :amount, :unit, :daily_value_percentage, :math_calculations)";
                $stmtMicros = $db->prepare($sqlMicros);
                foreach ($micros as $micro) {
                    $stmtMicros->execute([
                        ':recipe_id' => $recipeId,
                        ':nutrient_name' => $micro['name'] ?? $micro['nutrient_name'] ?? 'Unknown',
                        ':amount' => (float)($micro['amount'] ?? 0),
                        ':unit' => $micro['unit'] ?? 'mg',
                        ':daily_value_percentage' => (float)($micro['dv'] ?? $micro['daily_value_percentage'] ?? 0),
                        ':math_calculations' => ''
                    ]);
                }
            }

            // Everything worked! Commit to the database.
            $db->commit();

            $msg = $isPublic ? "Brilliant! Full recipe vaulted and published to the feed." : "Smashing! Text draft locked in your private vault.";
            echo json_encode(["success" => true, "recipeId" => (int)$recipeId, "message" => $msg]);

        } catch (\Exception $e) {
            // Something broke. Cancel the entire transaction.
            $db->rollBack();
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
    }
    /**
     * Verifies an active administrator session.
     */
    private function requireAdmin(): int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Cheeky! Admin access strictly required."]);
            exit;
        }
        return (int)($_SESSION['user_id'] ?? 0);
    }

    /**
     * Normalizes an input array or newline-delimited string into trimmed non-empty lines.
     */
    private function normalizeTextLines($raw): array {
        if (is_string($raw)) {
            $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        } elseif (is_array($raw)) {
            $lines = [];
            foreach ($raw as $item) {
                if (is_array($item)) {
                    $lines[] = trim((string)($item['instruction_text'] ?? $item['ingredient_name'] ?? $item['text'] ?? ''));
                } else {
                    $lines[] = trim((string)$item);
                }
            }
        } else {
            return [];
        }

        return array_values(array_filter(array_map('trim', $lines), fn($line) => $line !== ''));
    }

    /**
     * Parses nutrition text lines into relational macros and micros arrays.
     */
    private function parseNutritionLines($nutritionInput): array {
        $lines = $this->normalizeTextLines($nutritionInput);
        $macros = [
            'calories' => 0.0,
            'protein_g' => 0.0,
            'carbs_g' => 0.0,
            'fat_g' => 0.0,
            'fiber_g' => 0.0,
        ];
        $hasMacros = false;
        $micros = [];

        foreach ($lines as $rawLine) {
            $line = preg_replace('/^[\-\*\x{2022}]\s+/u', '', trim($rawLine));
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(calories|protein|carbs?|carbohydrates?|fat|fiber)\s*(?:\([^)]*\))?\s*[:\-]\s*([0-9]+(?:\.[0-9]+)?)/i', $line, $m)) {
                $key = strtolower($m[1]);
                $val = (float)$m[2];
                $hasMacros = true;
                if ($key === 'calories') {
                    $macros['calories'] = $val;
                } elseif ($key === 'protein') {
                    $macros['protein_g'] = $val;
                } elseif (str_starts_with($key, 'carb')) {
                    $macros['carbs_g'] = $val;
                } elseif ($key === 'fat') {
                    $macros['fat_g'] = $val;
                } elseif ($key === 'fiber') {
                    $macros['fiber_g'] = $val;
                }
                continue;
            }

            if (preg_match('/^([^:]+?)\s*:\s*([0-9]+(?:\.[0-9]+)?)\s*([a-zA-Z\x{00B5}\x{03BC}]+)?(?:\s*\(\s*([0-9]+(?:\.[0-9]+)?)\s*%\s*(?:DV)?\s*\))?/iu', $line, $m)) {
                $name = substr(trim($m[1]), 0, 100);
                if ($name === '') {
                    continue;
                }
                $micros[] = [
                    'nutrient_name' => $name,
                    'amount' => (float)$m[2],
                    'unit' => substr(trim($m[3] ?? '') ?: 'mg', 0, 20),
                    'daily_value_percentage' => min(999.99, (float)($m[4] ?? 0)),
                ];
            }
        }

        return [
            'hasMacros' => $hasMacros,
            'macros' => $macros,
            'micros' => $micros,
        ];
    }

    /**
     * Replaces relational ingredients, instructions, macros, and micros for a recipe ID.
     */
    private function replaceRecipeRelations(PDO $db, int $recipeId, array $data): void {
        if (array_key_exists('ingredients', $data)) {
            $delIng = $db->prepare("DELETE FROM ingredients WHERE recipe_id = ?");
            $delIng->execute([$recipeId]);

            $lines = $this->normalizeTextLines($data['ingredients']);
            if (!empty($lines)) {
                $insIng = $db->prepare(
                    "INSERT INTO ingredients (recipe_id, quantity, unit, ingredient_name, notes)
                     VALUES (:recipe_id, :quantity, :unit, :ingredient_name, :notes)"
                );
                foreach ($lines as $line) {
                    $insIng->execute([
                        ':recipe_id' => $recipeId,
                        ':quantity' => 0.00,
                        ':unit' => '',
                        ':ingredient_name' => substr($line, 0, 255),
                        ':notes' => null,
                    ]);
                }
            }
        }

        if (array_key_exists('instructions', $data)) {
            $delInst = $db->prepare("DELETE FROM instructions WHERE recipe_id = ?");
            $delInst->execute([$recipeId]);

            $lines = $this->normalizeTextLines($data['instructions']);
            if (!empty($lines)) {
                $insInst = $db->prepare(
                    "INSERT INTO instructions (recipe_id, step_number, instruction_text)
                     VALUES (:recipe_id, :step_number, :instruction_text)"
                );
                foreach ($lines as $index => $line) {
                    $cleanStep = preg_replace('/^(?:step\s*\d+\s*[:.)\-]\s*|\d+\s*[.)]\s+)/i', '', $line);
                    $insInst->execute([
                        ':recipe_id' => $recipeId,
                        ':step_number' => $index + 1,
                        ':instruction_text' => $cleanStep !== '' ? $cleanStep : $line,
                    ]);
                }
            }
        }

        if (array_key_exists('nutritionInfo', $data)) {
            $parsed = $this->parseNutritionLines($data['nutritionInfo']);

            $mathStmt = $db->prepare("SELECT math_calculations FROM macros WHERE recipe_id = ? LIMIT 1");
            $mathStmt->execute([$recipeId]);
            $existingMath = (string)($mathStmt->fetchColumn() ?: '');

            $delMacros = $db->prepare("DELETE FROM macros WHERE recipe_id = ?");
            $delMacros->execute([$recipeId]);

            if ($parsed['hasMacros']) {
                $insMacros = $db->prepare(
                    "INSERT INTO macros (recipe_id, calories, protein_g, carbs_g, fat_g, fiber_g, math_calculations)
                     VALUES (:recipe_id, :calories, :protein_g, :carbs_g, :fat_g, :fiber_g, :math_calculations)"
                );
                $insMacros->execute([
                    ':recipe_id' => $recipeId,
                    ':calories' => $parsed['macros']['calories'],
                    ':protein_g' => $parsed['macros']['protein_g'],
                    ':carbs_g' => $parsed['macros']['carbs_g'],
                    ':fat_g' => $parsed['macros']['fat_g'],
                    ':fiber_g' => $parsed['macros']['fiber_g'],
                    ':math_calculations' => $existingMath,
                ]);
            }

            $delMicros = $db->prepare("DELETE FROM micros WHERE recipe_id = ?");
            $delMicros->execute([$recipeId]);

            if (!empty($parsed['micros'])) {
                $insMicros = $db->prepare(
                    "INSERT INTO micros (recipe_id, nutrient_name, amount, unit, daily_value_percentage, math_calculations)
                     VALUES (:recipe_id, :nutrient_name, :amount, :unit, :daily_value_percentage, :math_calculations)"
                );
                foreach ($parsed['micros'] as $micro) {
                    $insMicros->execute([
                        ':recipe_id' => $recipeId,
                        ':nutrient_name' => $micro['nutrient_name'],
                        ':amount' => $micro['amount'],
                        ':unit' => $micro['unit'],
                        ':daily_value_percentage' => $micro['daily_value_percentage'],
                        ':math_calculations' => '',
                    ]);
                }
            }
        }
    }

    /**
     * Admin endpoint (POST /recipes): creates a recipe and its relational tables.
     */
    public function createRecipe() {
        $adminUserId = $this->requireAdmin();
        $db = $this->db;
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $data = json_decode(file_get_contents("php://input"), true);
        if (!is_array($data)) {
            $data = [];
        }

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Recipe title is required."]);
            return;
        }

        $imageUrl = trim((string)($data['imageUrl'] ?? $data['image_url'] ?? ''));
        $imageSource = ($imageUrl !== '' && !str_contains($imageUrl, 'default-veggie-vault-placeholder'))
            ? 'user'
            : 'none';
        $isPublic = array_key_exists('isPublic', $data)
            ? (int)(bool)$data['isPublic']
            : (array_key_exists('isDraft', $data) ? ($data['isDraft'] ? 0 : 1) : 1);

        try {
            $db->beginTransaction();

            $stmt = $db->prepare(
                "INSERT INTO recipes (user_id, title, description, notes, image_url, yields, prep_time_mins, cook_time_mins, is_wfpb, is_oil_free, is_gluten_free, is_sugar_free, is_public, image_source)
                 VALUES (:user_id, :title, :description, :notes, :image_url, :yields, :prep_time_mins, :cook_time_mins, 1, :is_oil_free, :is_gluten_free, :is_sugar_free, :is_public, :image_source)"
            );
            $stmt->execute([
                ':user_id' => $adminUserId > 0 ? $adminUserId : null,
                ':title' => $title,
                ':description' => trim((string)($data['description'] ?? '')),
                ':notes' => trim((string)($data['notes'] ?? '')),
                ':image_url' => $imageUrl,
                ':yields' => substr(trim((string)($data['yields'] ?? '')), 0, 255),
                ':prep_time_mins' => max(0, (int)($data['prepTime'] ?? 0)),
                ':cook_time_mins' => max(0, (int)($data['cookTime'] ?? 0)),
                ':is_oil_free' => array_key_exists('isOilFree', $data) ? (int)(bool)$data['isOilFree'] : 1,
                ':is_gluten_free' => array_key_exists('isGlutenFree', $data) ? (int)(bool)$data['isGlutenFree'] : 0,
                ':is_sugar_free' => array_key_exists('isSugarFree', $data) ? (int)(bool)$data['isSugarFree'] : 1,
                ':is_public' => $isPublic,
                ':image_source' => $imageSource,
            ]);

            $recipeId = (int)$db->lastInsertId();
            $this->replaceRecipeRelations($db, $recipeId, $data);

            $db->commit();
            echo json_encode([
                "success" => true,
                "id" => $recipeId,
                "message" => "Recipe vaulted successfully!",
            ]);
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to create recipe: " . $e->getMessage()]);
        }
    }

    /**
     * Admin endpoint (POST /upload): stores uploaded recipe images under /public/images/.
     */
    public function uploadImages() {
        $this->requireAdmin();

        if (empty($_FILES['images'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "No images were uploaded."]);
            return;
        }

        $files = $_FILES['images'];
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
        $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
        $sizes = is_array($files['size']) ? $files['size'] : [$files['size']];

        $uploadDir = __DIR__ . '/../../public/images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $uploadedUrls = [];

        foreach ($names as $idx => $origName) {
            if (($errors[$idx] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            if (($sizes[$idx] ?? 0) > 10 * 1024 * 1024) {
                continue;
            }
            $tmp = $tmpNames[$idx] ?? '';
            if ($tmp === '' || @getimagesize($tmp) === false) {
                continue;
            }

            $ext = strtolower(pathinfo((string)$origName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts, true)) {
                $ext = 'jpg';
            }

            $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (@move_uploaded_file($tmp, $uploadDir . $filename)) {
                $uploadedUrls[] = '/images/' . $filename;
            }
        }

        if (empty($uploadedUrls)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "No valid images could be saved."]);
            return;
        }

        echo json_encode([
            "success" => true,
            "urls" => $uploadedUrls,
        ]);
    }

    /*
     * Update an existing recipe and its relational ingredients, instructions, macros, and micros.
     */
    public function updateRecipe() {
        $this->requireAdmin();

        $db = $this->db;
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $data = json_decode(file_get_contents("php://input"), true);
        if (!is_array($data)) {
            $data = [];
        }

        $rawId = $_GET['id'] ?? ($data['id'] ?? null);
        if ($rawId === null || !is_numeric($rawId) || (int)$rawId <= 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid or Missing ID"]);
            return;
        }

        $id = (int)$rawId;

        $existingStmt = $db->prepare("SELECT * FROM recipes WHERE id = ? LIMIT 1");
        $existingStmt->execute([$id]);
        $existing = $existingStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$existing) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Recipe not found."]);
            return;
        }

        $title = trim((string)($data['title'] ?? $existing['title'] ?? ''));
        if ($title === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Recipe title cannot be empty."]);
            return;
        }

        if (array_key_exists('isPublic', $data)) {
            $isPublic = (int)(bool)$data['isPublic'];
        } elseif (array_key_exists('is_public', $data)) {
            $isPublic = (int)(bool)$data['is_public'];
        } elseif (array_key_exists('isDraft', $data)) {
            $isPublic = $data['isDraft'] ? 0 : 1;
        } else {
            $isPublic = (int)$existing['is_public'];
        }

        $imageUrl = array_key_exists('imageUrl', $data)
            ? trim((string)$data['imageUrl'])
            : (string)($existing['image_url'] ?? '');

        $imageSource = (string)($data['imageSource'] ?? $data['image_source'] ?? $existing['image_source'] ?? 'none');
        if (!in_array($imageSource, ['ai', 'user', 'none'], true)) {
            $imageSource = 'none';
        }
        if ($imageUrl === '' || str_contains($imageUrl, 'default-veggie-vault-placeholder')) {
            $imageSource = 'none';
        } elseif ($imageSource === 'none') {
            $imageSource = 'user';
        }

        $prepTime = array_key_exists('prepTime', $data)
            ? max(0, (int)$data['prepTime'])
            : (int)($existing['prep_time_mins'] ?? 0);
        $cookTime = array_key_exists('cookTime', $data)
            ? max(0, (int)$data['cookTime'])
            : (int)($existing['cook_time_mins'] ?? 0);

        $isOilFree = array_key_exists('isOilFree', $data)
            ? (int)(bool)$data['isOilFree']
            : (int)($existing['is_oil_free'] ?? 1);
        $isGlutenFree = array_key_exists('isGlutenFree', $data)
            ? (int)(bool)$data['isGlutenFree']
            : (int)($existing['is_gluten_free'] ?? 0);
        $isSugarFree = array_key_exists('isSugarFree', $data)
            ? (int)(bool)$data['isSugarFree']
            : (int)($existing['is_sugar_free'] ?? 1);

        try {
            $db->beginTransaction();

            $sql = "UPDATE recipes SET
                    title = :title,
                    description = :description,
                    yields = :yields,
                    prep_time_mins = :prep_time_mins,
                    cook_time_mins = :cook_time_mins,
                    notes = :notes,
                    image_url = :image_url,
                    is_public = :is_public,
                    image_source = :image_source,
                    is_oil_free = :is_oil_free,
                    is_gluten_free = :is_gluten_free,
                    is_sugar_free = :is_sugar_free
                    WHERE id = :id";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':title' => $title,
                ':description' => array_key_exists('description', $data) ? (string)$data['description'] : (string)($existing['description'] ?? ''),
                ':yields' => substr(array_key_exists('yields', $data) ? trim((string)$data['yields']) : (string)($existing['yields'] ?? ''), 0, 255),
                ':prep_time_mins' => $prepTime,
                ':cook_time_mins' => $cookTime,
                ':notes' => array_key_exists('notes', $data) ? (string)$data['notes'] : (string)($existing['notes'] ?? ''),
                ':image_url' => $imageUrl,
                ':is_public' => $isPublic,
                ':image_source' => $imageSource,
                ':is_oil_free' => $isOilFree,
                ':is_gluten_free' => $isGlutenFree,
                ':is_sugar_free' => $isSugarFree,
                ':id' => $id,
            ]);

            $this->replaceRecipeRelations($db, $id, $data);

            $db->commit();
            echo json_encode(["success" => true, "message" => "Recipe successfully updated!"]);
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to update the recipe: " . $e->getMessage()]);
        }
    }
    public function deleteRecipe() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing ID.']);
            return;
        }

        try {
            $stmt = $this->db->prepare("DELETE FROM recipes WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => 'Recipe Deleted.']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete recipe: ' . $e->getMessage()]);
        }
    }

    /**
     * Ensures the request comes from an authenticated session.
     * Returns the active user data array, or terminates with a 401.
     */
    private function requireAuth() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;

        if (!$userId) {
            http_response_code(401);
            echo json_encode([
                "success" => false, 
                "message" => "Access denied. Please log into the Vault first."
            ]);
            exit;
        }

        return [
            'id' => $userId,
            'username' => $_SESSION['username'] ?? 'Vault Member'
        ];
    }

    /**
     * Loads a recipe and verifies the active user owns it (or is an admin).
     */
    private function requireOwnedRecipe(int $recipeId, int $userId): array {
        $stmt = $this->db->prepare("SELECT id, user_id, notes, image_url, image_source, is_public FROM recipes WHERE id = ?");
        $stmt->execute([$recipeId]);
        $recipe = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$recipe) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Recipe not found in the vault."]);
            exit;
        }

        if ((int)$recipe['user_id'] !== $userId && empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Hands off! You can only upgrade your own draft recipes."]);
            exit;
        }

        return $recipe;
    }

    /**
     * Checks whether a recipe has a real (non-placeholder) dish image.
     */
    private function hasRealImage(array $recipe): bool {
        return !empty($recipe['image_url'])
            && ($recipe['image_source'] ?? 'none') !== 'none'
            && !str_contains($recipe['image_url'], 'default-veggie-vault-placeholder');
    }

    /**
     * Checks whether a recipe has full Masterpiece nutritional details (micros or Deep Dive analysis).
     */
    private function hasMasterpieceNutrition(int $recipeId, array $recipe): bool {
        $microCountStmt = $this->db->prepare("SELECT COUNT(*) FROM micros WHERE recipe_id = ?");
        $microCountStmt->execute([$recipeId]);
        $hasMicros = (int)$microCountStmt->fetchColumn() > 0;
        $hasDeepDive = str_contains($recipe['notes'] ?? '', 'Advanced Nutritional Analysis');
        return $hasMicros || $hasDeepDive;
    }

    /**
     * Attaches a user-uploaded or AI-generated image to a saved draft/recipe.
     * Automatically publishes as a Masterpiece if nutrition micros are already present.
     */
    public function publishWithPhoto() {
        $currentUser = $this->requireAuth();
        $userId = (int)$currentUser['id'];

        $data = json_decode(file_get_contents("php://input"), true) ?: [];
        $recipeId = (int)($data['recipe_id'] ?? 0);
        $rawImage = $data['image'] ?? '';
        $source = ($data['source'] ?? 'user') === 'ai' ? 'ai' : 'user';
        $forcePublish = array_key_exists('publish', $data) && $data['publish'] !== null ? (bool)$data['publish'] : null;

        if ($recipeId <= 0 || empty($rawImage)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Recipe ID and image data are required."]);
            return;
        }

        $recipe = $this->requireOwnedRecipe($recipeId, $userId);

        $ext = 'jpg';
        $base64Body = $rawImage;
        if (strpos($rawImage, 'data:image') === 0) {
            $parts = explode(';', $rawImage, 2);
            $type = $parts[0] ?? '';
            $dataParts = explode(',', $parts[1] ?? '', 2);
            $base64Body = $dataParts[1] ?? '';

            if (str_contains($type, 'png')) $ext = 'png';
            elseif (str_contains($type, 'webp')) $ext = 'webp';
        }

        $decodedData = base64_decode($base64Body, true);
        if ($decodedData === false || empty($decodedData)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid image data provided."]);
            return;
        }

        $prefix = $source === 'ai' ? 'ai_recipe_' : 'user_recipe_';
        $filename = time() . '_' . $prefix . $recipeId . '.' . $ext;
        $uploadDir = __DIR__ . '/../../public/images/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if (file_put_contents($uploadDir . $filename, $decodedData) === false) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to save image file to the vault."]);
            return;
        }

        $finalImageUrl = '/images/' . $filename;

        $hasNutrition = $this->hasMasterpieceNutrition($recipeId, $recipe);
        $newIsPublic = ($forcePublish === false) ? 0 : ($hasNutrition ? 1 : 0);

        $updateStmt = $this->db->prepare("UPDATE recipes SET image_url = :img, image_source = :src, is_public = :pub WHERE id = :id");
        $updateStmt->execute([
            ':img' => $finalImageUrl,
            ':src' => $source,
            ':pub' => $newIsPublic,
            ':id' => $recipeId
        ]);

        echo json_encode([
            "success" => true,
            "imageUrl" => $finalImageUrl,
            "imageSource" => $source,
            "isPublic" => (bool)$newIsPublic,
            "message" => $newIsPublic
                ? "Image attached and recipe published as a Masterpiece!"
                : "Dish image saved! Generate Masterpiece nutrition to publish."
        ]);
    }

    /**
     * Adds Emma's Deep Dive nutritional analysis and micronutrients to a saved draft/recipe.
     * Automatically publishes as a Masterpiece if a real dish image is already attached.
     */
    public function upgradeRecipeNutrition() {
        $currentUser = $this->requireAuth();
        $userId = (int)$currentUser['id'];

        $data = json_decode(file_get_contents("php://input"), true) ?: [];
        $recipeId = (int)($data['recipe_id'] ?? 0);
        $deepDive = trim($data['deep_dive'] ?? '');
        $micros = $data['micros'] ?? [];
        $forcePublish = array_key_exists('publish', $data) && $data['publish'] !== null ? (bool)$data['publish'] : null;

        if ($recipeId <= 0 || ($deepDive === '' && empty($micros))) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Recipe ID and nutritional analysis data are required."]);
            return;
        }

        $recipe = $this->requireOwnedRecipe($recipeId, $userId);

        try {
            $this->db->beginTransaction();

            $existingNotes = trim($recipe['notes'] ?? '');
            if ($deepDive !== '' && !str_contains($existingNotes, 'Advanced Nutritional Analysis')) {
                $updatedNotes = $existingNotes !== ''
                    ? $existingNotes . "\n\n### Advanced Nutritional Analysis\n" . $deepDive
                    : "### Advanced Nutritional Analysis\n" . $deepDive;
            } else {
                $updatedNotes = $existingNotes;
            }

            $hasImage = $this->hasRealImage($recipe);
            $hasNutrition = (is_array($micros) && !empty($micros))
                || str_contains($updatedNotes, 'Advanced Nutritional Analysis')
                || $this->hasMasterpieceNutrition($recipeId, $recipe);

            $newIsPublic = ($forcePublish === false)
                ? 0
                : (($hasImage && $hasNutrition) ? 1 : 0);

            $updateStmt = $this->db->prepare("UPDATE recipes SET notes = :notes, is_public = :pub WHERE id = :id");
            $updateStmt->execute([
                ':notes' => $updatedNotes,
                ':pub' => $newIsPublic,
                ':id' => $recipeId
            ]);

            if (is_array($micros) && !empty($micros)) {
                $delStmt = $this->db->prepare("DELETE FROM micros WHERE recipe_id = ?");
                $delStmt->execute([$recipeId]);

                $insStmt = $this->db->prepare(
                    "INSERT INTO micros (recipe_id, nutrient_name, amount, unit, daily_value_percentage, math_calculations)
                     VALUES (:recipe_id, :nutrient_name, :amount, :unit, :daily_value_percentage, :math_calculations)"
                );

                foreach ($micros as $micro) {
                    $insStmt->execute([
                        ':recipe_id' => $recipeId,
                        ':nutrient_name' => $micro['name'] ?? $micro['nutrient_name'] ?? 'Unknown',
                        ':amount' => (float)($micro['amount'] ?? 0),
                        ':unit' => $micro['unit'] ?? 'mg',
                        ':daily_value_percentage' => (float)($micro['dv'] ?? $micro['daily_value_percentage'] ?? 0),
                        ':math_calculations' => ''
                    ]);
                }
            }

            $this->db->commit();

            $microStmt = $this->db->prepare("SELECT * FROM micros WHERE recipe_id = ?");
            $microStmt->execute([$recipeId]);
            $savedMicros = $microStmt->fetchAll(\PDO::FETCH_ASSOC);

            echo json_encode([
                "success" => true,
                "notes" => $updatedNotes,
                "micros" => $savedMicros,
                "isPublic" => (bool)$newIsPublic,
                "message" => $newIsPublic
                    ? "Masterpiece nutrition added and recipe published!"
                    : "Masterpiece nutritional analysis saved! Add a dish photo to publish."
            ]);
        } catch (\Exception $e) {
            $this->db->rollBack();
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
    }

    /**
     * Publishes a saved private draft directly to the public Masterpiece feed.
     */
    public function publishDraft() {
        $currentUser = $this->requireAuth();
        $userId = (int)$currentUser['id'];

        $data = json_decode(file_get_contents("php://input"), true) ?: [];
        $recipeId = (int)($data['recipe_id'] ?? 0);

        if ($recipeId <= 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Recipe ID is required."]);
            return;
        }

        $recipe = $this->requireOwnedRecipe($recipeId, $userId);

        if (!$this->hasRealImage($recipe) || !$this->hasMasterpieceNutrition($recipeId, $recipe)) {
            http_response_code(400);
            echo json_encode([
                "success" => false,
                "message" => "Both a dish photo (AI-generated or uploaded) and full Masterpiece nutritional details are required before publishing."
            ]);
            return;
        }

        $stmt = $this->db->prepare("UPDATE recipes SET is_public = 1 WHERE id = ?");
        $stmt->execute([$recipeId]);

        echo json_encode([
            "success" => true,
            "isPublic" => true,
            "message" => "Your recipe is now a published Masterpiece!"
        ]);
    }

    public function toggleDraft() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Cheeky! Admin access strictly required."]);
        return;
    }

    $raw_input = file_get_contents("php://input");
    $data = json_decode($raw_input, true);

    $recipeId = $data['id'] ?? null;
    $isPublic = isset($data['is_public']) ? (int)$data['is_public'] : null;

    if (!$recipeId || $isPublic === null) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Missing recipe ID or visibility status, mate."]);
        return;
    }

    $db = \App\Database::connect();
    $stmt = $db->prepare("UPDATE recipes SET is_public = :is_public WHERE id = :id");
    $success = $stmt->execute([':is_public' => $isPublic, ':id' => $recipeId]);

    if ($success) {
        $statusText = $isPublic ? "published" : "hidden";
        echo json_encode(["success" => true, "message" => "Recipe successfully {$statusText}."]);
    } else {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "The database threw a wobbly. Try again."]);
    }
}

public function getUserFavorites() {
        $currentUser = $this->requireAuth();
        $userId = $currentUser['id'];

        try {
            $db = $this->db;
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Fetch only recipes present in recipe_favorites for this user
            $sql = "SELECT r.id, r.user_id, r.title, r.description, r.image_url, r.yields, 
                           r.prep_time_mins, r.cook_time_mins, r.is_wfpb, r.is_oil_free,
                           r.is_gluten_free, r.is_sugar_free,
                           r.is_public, r.image_source, r.created_at, r.average_rating, r.rating_count,
                           rf.created_at as favorited_at,
                           u.username AS author_name,
                           u.display_name AS author_display_name,
                           u.profile_image AS author_avatar,
                           CASE WHEN uf.followed_id IS NOT NULL THEN 1 ELSE 0 END AS is_followed_author
                    FROM recipe_favorites rf
                    JOIN recipes r ON rf.recipe_id = r.id
                    LEFT JOIN users u ON r.user_id = u.id
                    LEFT JOIN user_follows uf ON uf.followed_id = r.user_id AND uf.follower_id = ?
                    WHERE rf.user_id = ?
                    ORDER BY rf.created_at DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute([$userId, $userId]);
            $recipes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!is_array($recipes)) {
                $recipes = [];
            }

            $formatted = array_map(function($r) use ($userId) {
                return [
                    'id' => (int)$r['id'],
                    'user_id' => $r['user_id'] ? (int)$r['user_id'] : null,
                    'authorId' => $r['user_id'] ? (int)$r['user_id'] : null,
                    'title' => $r['title'],
                    'authorName' => $r['author_name'] ?? 'Emma (AI)',
                    'authorDisplayName' => $r['author_display_name'] ?? null,
                    'authorAvatar' => $r['author_avatar'] ?? null,
                    'isFollowedAuthor' => (bool)$r['is_followed_author'],
                    'description' => $r['description'],
                    'yields' => $r['yields'],

                    'image_url' => $r['image_url'],
                    'imageUrl' => $r['image_url'],
                    'image_source' => $r['image_source'],
                    'imageSource' => $r['image_source'],

                    'prep_time_mins' => (int)$r['prep_time_mins'],
                    'prepTime' => (int)$r['prep_time_mins'],
                    'cook_time_mins' => (int)$r['cook_time_mins'],
                    'cookTime' => (int)$r['cook_time_mins'],

                    'is_wfpb' => (bool)$r['is_wfpb'],
                    'isWfpb' => (bool)$r['is_wfpb'],
                    'is_oil_free' => (bool)$r['is_oil_free'],
                    'isOilFree' => (bool)$r['is_oil_free'],
                    'is_gluten_free' => (bool)($r['is_gluten_free'] ?? 0),
                    'isGlutenFree' => (bool)($r['is_gluten_free'] ?? 0),
                    'is_sugar_free' => (bool)($r['is_sugar_free'] ?? 1),
                    'isSugarFree' => (bool)($r['is_sugar_free'] ?? 1),

                    'is_public' => (bool)$r['is_public'],
                    'isPublic' => (bool)$r['is_public'],
                    'is_draft' => !(bool)$r['is_public'],
                    'isDraft' => !(bool)$r['is_public'],

                    'created_at' => $r['created_at'],
                    'createdAt' => $r['created_at'],

                    'average_rating' => (float)($r['average_rating'] ?? 0),
                    'averageRating' => (float)($r['average_rating'] ?? 0),
                    'rating_count' => (int)($r['rating_count'] ?? 0),
                    'ratingCount' => (int)($r['rating_count'] ?? 0),

                    'isOwner' => ((int)$r['user_id'] === (int)$userId),
                    'is_favorite' => true,
                    'isFavorited' => true
                ];
            }, $recipes);

            echo json_encode($formatted);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Failed to load favorites: " . $e->getMessage()]);
        }
    }
    public function toggleFavorite() {
        // Enforce a strict JSON response so React doesn't throw a wobbly
        header('Content-Type: application/json');

        try {
            // Secure the perimeter and grab the active user's ID
            $currentUser = $this->requireAuth();
            $userId = $currentUser['id'];

            // Capture the incoming JSON payload from the fetch request
            $rawInput = file_get_contents("php://input");
            $requestData = json_decode($rawInput, true);
            $recipeId = $requestData['recipe_id'] ?? null;
            
            if (!$recipeId) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Missing recipe ID. I cannot favorite thin air, darling."]);
                return;
            }

            // Utilize the class database connection
            $db = $this->db;

            // Check if this recipe is already lounging in their vault
            $checkStmt = $db->prepare("SELECT id FROM recipe_favorites WHERE user_id = ? AND recipe_id = ?");
            $checkStmt->execute([$userId, $recipeId]);
            $existingFavorite = $checkStmt->fetch(\PDO::FETCH_ASSOC);

            if ($existingFavorite) {
                // It exists, so we chuck it out (DELETE)
                $deleteStmt = $db->prepare("DELETE FROM recipe_favorites WHERE user_id = ? AND recipe_id = ?");
                $deleteStmt->execute([$userId, $recipeId]);
                $isFavorite = false;
                $message = "Recipe binned from your vault.";
            } else {
                // It does not exist, so we lock it in (INSERT)
                $insertStmt = $db->prepare("INSERT INTO recipe_favorites (user_id, recipe_id) VALUES (?, ?)");
                $insertStmt->execute([$userId, $recipeId]);
                $isFavorite = true;
                $message = "Smashing! Recipe vaulted successfully.";
            }

            http_response_code(200);
            echo json_encode([
                "status" => "success", 
                "message" => $message,
                "is_favorite" => $isFavorite 
            ]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode([
                "status" => "error", 
                "message" => "Database error: " . $e->getMessage()
            ]);
        }
    }

    /**
     * Accepts a star rating, logs it by IP, and recalculates the recipe's average.
     */
    public function rateRecipe() {
        $db = Database::connect();
        $data = json_decode(file_get_contents("php://input"), true);
        
        $recipeId = $data['recipe_id'] ?? null;
        $rating = $data['rating'] ?? null;
        
        // Grab the user's IP to prevent cheeky ballot-stuffing
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!$recipeId || !$rating || $rating < 1 || $rating > 5) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid rating data. Keep it between 1 and 5 stars!"]);
            return;
        }

        try {
            $db->beginTransaction();

            // 1. Insert the rating, or update it if this IP has already voted on this dish
            $sql = "INSERT INTO recipe_ratings (recipe_id, ip_address, rating) 
                    VALUES (:recipe_id, :ip_address, :rating)
                    ON DUPLICATE KEY UPDATE rating = VALUES(rating)";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':recipe_id' => $recipeId,
                ':ip_address' => $ipAddress,
                ':rating' => (int)$rating
            ]);

            // 2. Fetch the newly recalculated average and total vote count
            $calcSql = "SELECT AVG(rating) as avg_rating, COUNT(id) as rating_count 
                        FROM recipe_ratings WHERE recipe_id = :recipe_id";
            $calcStmt = $db->prepare($calcSql);
            $calcStmt->execute([':recipe_id' => $recipeId]);
            $stats = $calcStmt->fetch(\PDO::FETCH_ASSOC);

            $newAverage = round($stats['avg_rating'], 2);
            $newCount = $stats['rating_count'];

            // 3. Update the caching columns on the main recipes table
            $updateSql = "UPDATE recipes SET average_rating = :avg, rating_count = :count WHERE id = :id";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([
                ':avg' => $newAverage,
                ':count' => $newCount,
                ':id' => $recipeId
            ]);

            $db->commit();
            
            echo json_encode([
                "success" => true, 
                "message" => "Rating secured!", 
                "averageRating" => $newAverage,
                "ratingCount" => $newCount
            ]);

        } catch (\Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
    }

    /**
     * Fetch all comments for a specific recipe.
     * We pull them all at once; React will handle the nested reply tree visually!
     */
    public function getRecipeComments() {
        $recipeId = $_GET['recipe_id'] ?? null;
        
        if (!$recipeId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing recipe ID.']);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT id, parent_id, user_id, author_name, body, image_url, likes, dislikes, created_at 
            FROM recipe_comments 
            WHERE recipe_id = ? 
            ORDER BY created_at ASC
        ");
        $stmt->execute([$recipeId]);
        
        echo json_encode($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Add a new comment or reply (Authenticated users only).
     */
    public function addRecipeComment() {
        // Enforce login and pull verified user details
        $currentUser = $this->requireAuth();

        $db = Database::connect();
        $data = json_decode(file_get_contents("php://input"), true);
        
        $recipeId = $data['recipe_id'] ?? null;
        $parentId = $data['parent_id'] ?? null;
        $body = htmlspecialchars(trim($data['body'] ?? ''));
        $rawImage = $data['image'] ?? null; 
        
        // Take identity from session, not client input
        $authorName = $currentUser['username'];
        $userId = $currentUser['id'];

        if (!$recipeId || empty($body)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Cannot post an empty comment."]);
            return;
        }

        $finalImageUrl = null;

        // Compress and save image attachment if present
        if (!empty($rawImage) && strpos($rawImage, 'data:image') === 0) {
            list($type, $imageData) = explode(';', $rawImage);
            list(, $imageData)      = explode(',', $imageData);
            $decodedData = base64_decode($imageData);
            
            $sourceImage = imagecreatefromstring($decodedData);
            if ($sourceImage !== false) {
                $width = imagesx($sourceImage);
                $height = imagesy($sourceImage);
                
                $newWidth = 300;
                $newHeight = (int)floor($height * ($newWidth / $width));
                
                $virtualImage = imagecreatetruecolor($newWidth, $newHeight);
                
                if (str_contains($type, 'png') || str_contains($type, 'webp')) {
                    imagealphablending($virtualImage, false);
                    imagesavealpha($virtualImage, true);
                    $transparent = imagecolorallocatealpha($virtualImage, 255, 255, 255, 127);
                    imagefilledrectangle($virtualImage, 0, 0, $newWidth, $newHeight, $transparent);
                }

                imagecopyresampled($virtualImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                
                $filename = time() . '_' . $userId . '_comment.webp';
                $uploadDir = __DIR__ . '/../../public/images/comments/';
                
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                imagewebp($virtualImage, $uploadDir . $filename, 80);
                $finalImageUrl = '/images/comments/' . $filename;
                
                imagedestroy($sourceImage);
                imagedestroy($virtualImage);
            }
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO recipe_comments (recipe_id, parent_id, user_id, author_name, body, image_url) 
                VALUES (:recipe_id, :parent_id, :user_id, :author_name, :body, :image_url)
            ");
            $stmt->execute([
                ':recipe_id'   => $recipeId,
                ':parent_id'   => $parentId,
                ':user_id'     => $userId,
                ':author_name' => $authorName,
                ':body'        => $body,
                ':image_url'   => $finalImageUrl
            ]);
            
            echo json_encode(["success" => true, "message" => "Comment vaulted!"]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to save comment: " . $e->getMessage()]);
        }
    }

    /**
     * Handle likes and dislikes on comments (Authenticated users only).
     */
    public function voteOnComment() {
        $currentUser = $this->requireAuth();
        $userId = $currentUser['id'];

        $db = Database::connect();
        $data = json_decode(file_get_contents("php://input"), true);
        
        $commentId = $data['comment_id'] ?? null;
        $voteType = $data['vote_type'] ?? null; 
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!$commentId || !in_array($voteType, ['like', 'dislike'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid vote data."]);
            return;
        }

        try {
            $db->beginTransaction();

            // Track existing vote by user_id instead of just IP
            $checkStmt = $db->prepare("SELECT id, vote_type FROM comment_votes WHERE comment_id = ? AND user_id = ?");
            $checkStmt->execute([$commentId, $userId]);
            $existingVote = $checkStmt->fetch(\PDO::FETCH_ASSOC);

            if ($existingVote) {
                if ($existingVote['vote_type'] === $voteType) {
                    echo json_encode(["success" => true, "message" => "Vote already recorded."]);
                    $db->rollBack();
                    return;
                }
                
                // Switch vote direction
                $updateStmt = $db->prepare("UPDATE comment_votes SET vote_type = ? WHERE id = ?");
                $updateStmt->execute([$voteType, $existingVote['id']]);
                
                $adjustSql = $voteType === 'like' 
                    ? "UPDATE recipe_comments SET likes = likes + 1, dislikes = GREATEST(0, dislikes - 1) WHERE id = ?"
                    : "UPDATE recipe_comments SET dislikes = dislikes + 1, likes = GREATEST(0, likes - 1) WHERE id = ?";
                $db->prepare($adjustSql)->execute([$commentId]);

            } else {
                // New vote entry
                $insertStmt = $db->prepare("INSERT INTO comment_votes (comment_id, user_id, ip_address, vote_type) VALUES (?, ?, ?, ?)");
                $insertStmt->execute([$commentId, $userId, $ipAddress, $voteType]);
                
                $incrementSql = $voteType === 'like' 
                    ? "UPDATE recipe_comments SET likes = likes + 1 WHERE id = ?"
                    : "UPDATE recipe_comments SET dislikes = dislikes + 1 WHERE id = ?";
                $db->prepare($incrementSql)->execute([$commentId]);
            }

            $db->commit();
            echo json_encode(["success" => true, "message" => "Vote counted."]);

        } catch (\Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
    }
}