<?php

namespace App\Controllers;

use PDO;
use Exception;
use App\Database;
use App\Services\ImageModerationService;
use App\Services\ProfanityFilterService;
use App\Services\RecipeVariantService;
use App\Services\StoryService;
use App\Services\UsdaNutritionService;

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
            RecipeVariantService::ensureSchema($db);
            StoryService::ensureSchema($db);
            
            // Check if the user is an admin (assuming you start the session at the top of the method)
            $isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;

            // Base query — prioritize recipes created by users that the logged-in user follows
            $sql = "SELECT r.id, r.user_id, r.parent_recipe_id, r.root_recipe_id, r.is_masterpiece, r.variant_summary,
                        r.title, r.description, r.image_url, r.yields, 
                        r.prep_time_mins, r.cook_time_mins, r.is_wfpb, r.is_oil_free,
                        r.is_gluten_free, r.is_sugar_free,
                        r.is_public, r.image_source, r.created_at, r.average_rating, r.rating_count,
                        u.username AS author_name,
                        u.display_name AS author_display_name,
                        u.profile_image AS author_avatar,
                        pr.title AS parent_recipe_title,
                        pr.user_id AS parent_author_id,
                        pu.username AS parent_author_name,
                        CASE WHEN rf.recipe_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite,
                        CASE WHEN uf.followed_id IS NOT NULL THEN 1 ELSE 0 END AS is_followed_author,
                        (SELECT GROUP_CONCAT(i.ingredient_name SEPARATOR '||') FROM ingredients i WHERE i.recipe_id = r.id) AS ingredient_names,
                        (SELECT COUNT(*) FROM recipes rv WHERE (rv.parent_recipe_id = r.id OR rv.root_recipe_id = r.id) AND rv.id != r.id AND rv.is_public = 1) AS variant_count,
                        (SELECT COUNT(*) FROM recipe_stories rs WHERE rs.recipe_id = r.id AND rs.status = 'published') AS story_count
                    FROM recipes r
                    LEFT JOIN users u ON r.user_id = u.id
                    LEFT JOIN recipes pr ON r.parent_recipe_id = pr.id
                    LEFT JOIN users pu ON pr.user_id = pu.id
                    LEFT JOIN recipe_favorites rf ON rf.recipe_id = r.id AND rf.user_id = :fav_user_id
                    LEFT JOIN user_follows uf ON uf.followed_id = r.user_id AND uf.follower_id = :follow_user_id";

            // Only hide non-public recipes if the user IS NOT an admin
            if (!$isAdmin) {
                $sql .= " WHERE r.is_public = 1";
            }

            $sql .= " ORDER BY r.created_at DESC, r.id DESC";
                    
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
                $parentRecipeId = !empty($r['parent_recipe_id']) ? (int)$r['parent_recipe_id'] : null;
                $rootRecipeId = !empty($r['root_recipe_id']) ? (int)$r['root_recipe_id'] : null;
                return [
                    'id' => $r['id'],
                    'user_id' => $r['user_id'] ? (int)$r['user_id'] : null,
                    'authorId' => $r['user_id'] ? (int)$r['user_id'] : null,
                    'parent_recipe_id' => $parentRecipeId,
                    'parentRecipeId' => $parentRecipeId,
                    'root_recipe_id' => $rootRecipeId,
                    'rootRecipeId' => $rootRecipeId,
                    'is_masterpiece' => (bool)($r['is_masterpiece'] ?? 0),
                    'isMasterpiece' => (bool)($r['is_masterpiece'] ?? 0),
                    'isVariant' => $parentRecipeId !== null,
                    'variant_summary' => $r['variant_summary'] ?? null,
                    'variantSummary' => $r['variant_summary'] ?? null,
                    'parentRecipeTitle' => $r['parent_recipe_title'] ?? null,
                    'parentAuthorId' => !empty($r['parent_author_id']) ? (int)$r['parent_author_id'] : null,
                    'parentAuthorName' => $parentRecipeId !== null ? ($r['parent_author_name'] ?? 'Emma (AI)') : null,
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
                    'variant_count' => (int)($r['variant_count'] ?? 0),
                    'variantCount' => (int)($r['variant_count'] ?? 0),
                    'story_count' => (int)($r['story_count'] ?? 0),
                    'storyCount' => (int)($r['story_count'] ?? 0),
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
            RecipeVariantService::ensureSchema($this->db);
            StoryService::ensureSchema($this->db);

            // 2. Fetch the overarching recipe details, author metadata, and parent/root variant lineage
            $sql = "SELECT r.*, u.username AS author_name,
                           u.display_name AS author_display_name,
                           u.profile_image AS author_avatar,
                           u.location AS author_location,
                           pr.title AS parent_recipe_title,
                           pr.user_id AS parent_author_id,
                           pr.image_url AS parent_image_url,
                           pu.username AS parent_author_name,
                           pu.display_name AS parent_author_display_name,
                           rr.title AS root_recipe_title,
                           rr.user_id AS root_author_id,
                           ru.username AS root_author_name,
                           ru.display_name AS root_author_display_name,
                           (SELECT COUNT(*) FROM recipes rv WHERE (rv.parent_recipe_id = r.id OR rv.root_recipe_id = r.id) AND rv.id != r.id AND rv.is_public = 1) AS variant_count,
                           (SELECT COUNT(*) FROM recipe_stories rs WHERE rs.recipe_id = r.id AND rs.status = 'published') AS story_count
                    FROM recipes r 
                    LEFT JOIN users u ON r.user_id = u.id
                    LEFT JOIN recipes pr ON r.parent_recipe_id = pr.id
                    LEFT JOIN users pu ON pr.user_id = pu.id
                    LEFT JOIN recipes rr ON r.root_recipe_id = rr.id
                    LEFT JOIN users ru ON rr.user_id = ru.id
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

            $parentRecipeId = !empty($recipe['parent_recipe_id']) ? (int)$recipe['parent_recipe_id'] : null;
            $rootRecipeId = !empty($recipe['root_recipe_id']) ? (int)$recipe['root_recipe_id'] : null;

            // Map the author & variant lineage metadata for React
            $recipe['authorId'] = $authorId;
            $recipe['authorName'] = $recipe['author_name'] ?? 'Emma (AI)';
            $recipe['authorDisplayName'] = $recipe['author_display_name'] ?? null;
            $recipe['authorAvatar'] = $recipe['author_avatar'] ?? null;
            $recipe['authorLocation'] = $recipe['author_location'] ?? null;
            $recipe['isFollowedAuthor'] = $isFollowedAuthor;
            $recipe['isOilFree'] = (bool)($recipe['is_oil_free'] ?? 0);
            $recipe['isGlutenFree'] = (bool)($recipe['is_gluten_free'] ?? 0);
            $recipe['isSugarFree'] = (bool)($recipe['is_sugar_free'] ?? 1);
            $recipe['parentRecipeId'] = $parentRecipeId;
            $recipe['rootRecipeId'] = $rootRecipeId;
            $recipe['isVariant'] = $parentRecipeId !== null;
            $recipe['variantSummary'] = $recipe['variant_summary'] ?? null;
            $recipe['parentRecipeTitle'] = $recipe['parent_recipe_title'] ?? null;
            $recipe['parentAuthorId'] = !empty($recipe['parent_author_id']) ? (int)$recipe['parent_author_id'] : null;
            $recipe['parentAuthorName'] = $parentRecipeId !== null ? ($recipe['parent_author_name'] ?? 'Emma (AI)') : null;
            $recipe['variant_count'] = (int)($recipe['variant_count'] ?? 0);
            $recipe['variantCount'] = (int)($recipe['variant_count'] ?? 0);
            $recipe['story_count'] = (int)($recipe['story_count'] ?? 0);
            $recipe['storyCount'] = (int)($recipe['story_count'] ?? 0);
            $recipe['parentRecipe'] = $parentRecipeId !== null ? [
                'id' => $parentRecipeId,
                'title' => $recipe['parent_recipe_title'] ?? 'Original Recipe',
                'authorId' => !empty($recipe['parent_author_id']) ? (int)$recipe['parent_author_id'] : null,
                'authorName' => $recipe['parent_author_name'] ?? 'Emma (AI)',
                'authorDisplayName' => $recipe['parent_author_display_name'] ?? null,
                'imageUrl' => $recipe['parent_image_url'] ?? null,
            ] : null;
            $recipe['rootRecipe'] = $rootRecipeId !== null ? [
                'id' => $rootRecipeId,
                'title' => $recipe['root_recipe_title'] ?? ($recipe['parent_recipe_title'] ?? 'Original Recipe'),
                'authorId' => !empty($recipe['root_author_id']) ? (int)$recipe['root_author_id'] : null,
                'authorName' => $recipe['root_author_name'] ?? ($recipe['parent_author_name'] ?? 'Emma (AI)'),
                'authorDisplayName' => $recipe['root_author_display_name'] ?? null,
            ] : null;
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

            $isMasterpieceFlag = (bool)($recipe['is_masterpiece'] ?? 0)
                || !empty($recipe['micros'])
                || str_contains((string)($recipe['notes'] ?? ''), 'Advanced Nutritional Analysis');
            $recipe['is_masterpiece'] = $isMasterpieceFlag ? 1 : 0;
            $recipe['isMasterpiece'] = $isMasterpieceFlag;

            // 6b. Fetch gallery images (preserving original Emma/primary photo + user additions)
            $recipe['images'] = $this->getRecipeGalleryImages(
                (int)$id,
                (string)($recipe['image_url'] ?? ''),
                (string)($recipe['image_source'] ?? 'none')
            );

            // 6c. Fetch public community variants of this recipe
            $varStmt = $this->db->prepare("
                SELECT v.id, v.user_id, v.parent_recipe_id, v.root_recipe_id, v.is_masterpiece, v.variant_summary,
                       v.title, v.description, v.image_url, v.yields, v.prep_time_mins, v.cook_time_mins,
                       v.is_wfpb, v.is_oil_free, v.is_gluten_free, v.is_sugar_free,
                       v.average_rating, v.rating_count, v.created_at,
                       vu.username AS author_name, vu.display_name AS author_display_name, vu.profile_image AS author_avatar,
                       (SELECT COUNT(*) FROM recipes rv WHERE (rv.parent_recipe_id = v.id OR rv.root_recipe_id = v.id) AND rv.id != v.id AND rv.is_public = 1) AS variant_count,
                       (SELECT COUNT(*) FROM recipe_stories rs WHERE rs.recipe_id = v.id AND rs.status = 'published') AS story_count
                FROM recipes v
                LEFT JOIN users vu ON v.user_id = vu.id
                WHERE (v.parent_recipe_id = :pid OR v.root_recipe_id = :rid)
                  AND v.id != :self_id
                  AND v.is_public = 1
                ORDER BY v.is_masterpiece DESC, v.average_rating DESC, v.created_at DESC
                LIMIT 12
            ");
            $varStmt->execute([
                ':pid' => (int)$id,
                ':rid' => (int)$id,
                ':self_id' => (int)$id,
            ]);
            $rawVariants = $varStmt->fetchAll(\PDO::FETCH_ASSOC);
            $recipe['variants'] = array_map(function ($v) use ($recipe) {
                return [
                    'id' => (int)$v['id'],
                    'user_id' => $v['user_id'] ? (int)$v['user_id'] : null,
                    'authorId' => $v['user_id'] ? (int)$v['user_id'] : null,
                    'authorName' => $v['author_name'] ?? 'Emma (AI)',
                    'authorDisplayName' => $v['author_display_name'] ?? null,
                    'authorAvatar' => $v['author_avatar'] ?? null,
                    'parent_recipe_id' => (int)$v['parent_recipe_id'],
                    'parentRecipeId' => (int)$v['parent_recipe_id'],
                    'root_recipe_id' => $v['root_recipe_id'] ? (int)$v['root_recipe_id'] : null,
                    'rootRecipeId' => $v['root_recipe_id'] ? (int)$v['root_recipe_id'] : null,
                    'parentRecipeTitle' => $recipe['title'],
                    'parentAuthorId' => $recipe['authorId'],
                    'parentAuthorName' => $recipe['authorName'],
                    'is_masterpiece' => (bool)$v['is_masterpiece'],
                    'isMasterpiece' => (bool)$v['is_masterpiece'],
                    'isVariant' => true,
                    'variant_summary' => $v['variant_summary'] ?? null,
                    'variantSummary' => $v['variant_summary'] ?? null,
                    'title' => $v['title'],
                    'description' => $v['description'],
                    'imageUrl' => $v['image_url'],
                    'image_url' => $v['image_url'],
                    'yields' => $v['yields'],
                    'prepTime' => (int)$v['prep_time_mins'],
                    'cookTime' => (int)$v['cook_time_mins'],
                    'isWfpb' => (bool)$v['is_wfpb'],
                    'isOilFree' => (bool)$v['is_oil_free'],
                    'isGlutenFree' => (bool)$v['is_gluten_free'],
                    'isSugarFree' => (bool)$v['is_sugar_free'],
                    'averageRating' => (float)($v['average_rating'] ?? 0),
                    'ratingCount' => (int)($v['rating_count'] ?? 0),
                    'variant_count' => (int)($v['variant_count'] ?? 0),
                    'variantCount' => (int)($v['variant_count'] ?? 0),
                    'story_count' => (int)($v['story_count'] ?? 0),
                    'storyCount' => (int)($v['story_count'] ?? 0),
                    'createdAt' => $v['created_at'],
                ];
            }, $rawVariants);

            // 7. Echo the pristine, perfectly structured JSON back to React!
            echo json_encode($recipe);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch recipe: ' . $e->getMessage()]);
        }
    }

    /**
     * GET /recipes/lineage?id=<recipeId>
     * Returns a recipe's full ancestor lineage back to the original root recipe,
     * all associated public variants (direct children and full family tree branches),
     * and all published stories written for this recipe.
     */
    public function getRecipeLineage() {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Valid recipe ID is required.']);
            return;
        }

        try {
            RecipeVariantService::ensureSchema($this->db);
            StoryService::ensureSchema($this->db);

            $baseSql = "
                SELECT r.id, r.user_id, r.parent_recipe_id, r.root_recipe_id, r.is_masterpiece, r.variant_summary,
                       r.title, r.description, r.image_url, r.yields, r.prep_time_mins, r.cook_time_mins,
                       r.is_wfpb, r.is_oil_free, r.is_gluten_free, r.is_sugar_free, r.is_public,
                       r.average_rating, r.rating_count, r.created_at,
                       u.username AS author_name, u.display_name AS author_display_name, u.profile_image AS author_avatar,
                       pr.title AS parent_recipe_title, pr.user_id AS parent_author_id, pu.username AS parent_author_name,
                       (SELECT COUNT(*) FROM recipes rv WHERE (rv.parent_recipe_id = r.id OR rv.root_recipe_id = r.id) AND rv.id != r.id AND rv.is_public = 1) AS variant_count,
                       (SELECT COUNT(*) FROM recipe_stories rs WHERE rs.recipe_id = r.id AND rs.status = 'published') AS story_count
                FROM recipes r
                LEFT JOIN users u ON r.user_id = u.id
                LEFT JOIN recipes pr ON r.parent_recipe_id = pr.id
                LEFT JOIN users pu ON pr.user_id = pu.id
                WHERE r.id = ?
                LIMIT 1
            ";
            $stmt = $this->db->prepare($baseSql);
            $stmt->execute([$id]);
            $target = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$target) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Recipe not found.']);
                return;
            }

            $formatNode = static function (array $row): array {
                $pid = !empty($row['parent_recipe_id']) ? (int)$row['parent_recipe_id'] : null;
                $rid = !empty($row['root_recipe_id']) ? (int)$row['root_recipe_id'] : null;
                return [
                    'id' => (int)$row['id'],
                    'user_id' => !empty($row['user_id']) ? (int)$row['user_id'] : null,
                    'authorId' => !empty($row['user_id']) ? (int)$row['user_id'] : null,
                    'authorName' => $row['author_name'] ?? 'Emma (AI)',
                    'authorDisplayName' => $row['author_display_name'] ?? null,
                    'authorAvatar' => $row['author_avatar'] ?? null,
                    'parentRecipeId' => $pid,
                    'parent_recipe_id' => $pid,
                    'rootRecipeId' => $rid,
                    'root_recipe_id' => $rid,
                    'parentRecipeTitle' => $row['parent_recipe_title'] ?? null,
                    'parentAuthorId' => !empty($row['parent_author_id']) ? (int)$row['parent_author_id'] : null,
                    'parentAuthorName' => $pid !== null ? ($row['parent_author_name'] ?? 'Emma (AI)') : null,
                    'isMasterpiece' => (bool)($row['is_masterpiece'] ?? 0),
                    'is_masterpiece' => (bool)($row['is_masterpiece'] ?? 0),
                    'isVariant' => $pid !== null,
                    'variantSummary' => $row['variant_summary'] ?? null,
                    'variant_summary' => $row['variant_summary'] ?? null,
                    'title' => $row['title'] ?? 'Recipe',
                    'description' => $row['description'] ?? '',
                    'imageUrl' => $row['image_url'] ?? '',
                    'image_url' => $row['image_url'] ?? '',
                    'yields' => $row['yields'] ?? '',
                    'prepTime' => (int)($row['prep_time_mins'] ?? 0),
                    'cookTime' => (int)($row['cook_time_mins'] ?? 0),
                    'isWfpb' => (bool)($row['is_wfpb'] ?? 1),
                    'isOilFree' => (bool)($row['is_oil_free'] ?? 1),
                    'isGlutenFree' => (bool)($row['is_gluten_free'] ?? 0),
                    'isSugarFree' => (bool)($row['is_sugar_free'] ?? 1),
                    'isPublic' => (bool)($row['is_public'] ?? 1),
                    'averageRating' => (float)($row['average_rating'] ?? 0),
                    'ratingCount' => (int)($row['rating_count'] ?? 0),
                    'variantCount' => (int)($row['variant_count'] ?? 0),
                    'variant_count' => (int)($row['variant_count'] ?? 0),
                    'storyCount' => (int)($row['story_count'] ?? 0),
                    'story_count' => (int)($row['story_count'] ?? 0),
                    'createdAt' => $row['created_at'] ?? null,
                ];
            };

            $formattedTarget = $formatNode($target);

            // Walk upward through parent_recipe_id to build the full ancestor chain back to Generation 0
            $ancestors = [];
            $visitedIds = [(int)$target['id'] => true];
            $currParentId = !empty($target['parent_recipe_id']) ? (int)$target['parent_recipe_id'] : 0;
            $hopStmt = $this->db->prepare($baseSql);
            $hops = 0;

            while ($currParentId > 0 && !isset($visitedIds[$currParentId]) && $hops < 20) {
                $visitedIds[$currParentId] = true;
                $hopStmt->execute([$currParentId]);
                $parentRow = $hopStmt->fetch(\PDO::FETCH_ASSOC);
                if (!$parentRow) {
                    break;
                }
                $ancestors[] = $formatNode($parentRow);
                $currParentId = !empty($parentRow['parent_recipe_id']) ? (int)$parentRow['parent_recipe_id'] : 0;
                $hops++;
            }

            // Reverse so index 0 is the original Root Recipe (Generation 0)
            $ancestors = array_reverse($ancestors);
            foreach ($ancestors as $idx => &$ancNode) {
                $ancNode['generation'] = $idx;
            }
            unset($ancNode);
            $formattedTarget['generation'] = count($ancestors);

            $treeRootId = !empty($target['root_recipe_id'])
                ? (int)$target['root_recipe_id']
                : (!empty($ancestors) ? (int)$ancestors[0]['id'] : (int)$target['id']);

            // Fetch all public variants associated with this recipe OR its broader family tree
            $variantsSql = "
                SELECT v.id, v.user_id, v.parent_recipe_id, v.root_recipe_id, v.is_masterpiece, v.variant_summary,
                       v.title, v.description, v.image_url, v.yields, v.prep_time_mins, v.cook_time_mins,
                       v.is_wfpb, v.is_oil_free, v.is_gluten_free, v.is_sugar_free, v.is_public,
                       v.average_rating, v.rating_count, v.created_at,
                       vu.username AS author_name, vu.display_name AS author_display_name, vu.profile_image AS author_avatar,
                       pv.title AS parent_recipe_title, pv.user_id AS parent_author_id, pvu.username AS parent_author_name,
                       (SELECT COUNT(*) FROM recipes rv WHERE (rv.parent_recipe_id = v.id OR rv.root_recipe_id = v.id) AND rv.id != v.id AND rv.is_public = 1) AS variant_count,
                       (SELECT COUNT(*) FROM recipe_stories rs WHERE rs.recipe_id = v.id AND rs.status = 'published') AS story_count
                FROM recipes v
                LEFT JOIN users vu ON v.user_id = vu.id
                LEFT JOIN recipes pv ON v.parent_recipe_id = pv.id
                LEFT JOIN users pvu ON pv.user_id = pvu.id
                WHERE (
                    v.parent_recipe_id = :target_id_1
                    OR v.root_recipe_id = :target_id_2
                    OR v.root_recipe_id = :tree_root_1
                    OR v.parent_recipe_id = :tree_root_2
                )
                  AND v.id != :self_id
                  AND v.is_public = 1
                ORDER BY
                  CASE WHEN v.parent_recipe_id = :order_target_id THEN 0 ELSE 1 END ASC,
                  v.created_at DESC,
                  v.id DESC
            ";
            $vStmt = $this->db->prepare($variantsSql);
            $vStmt->execute([
                ':target_id_1' => $id,
                ':target_id_2' => $id,
                ':tree_root_1' => $treeRootId,
                ':tree_root_2' => $treeRootId,
                ':self_id' => $id,
                ':order_target_id' => $id,
            ]);
            $rawVariants = $vStmt->fetchAll(\PDO::FETCH_ASSOC);

            $variants = array_map(function (array $row) use ($formatNode, $id): array {
                $node = $formatNode($row);
                $node['isDirectChild'] = ((int)($row['parent_recipe_id'] ?? 0) === $id);
                return $node;
            }, $rawVariants);

            // Fetch all published stories associated with this recipe
            $storiesSql = "
                SELECT s.id, s.recipe_id, s.user_id, s.title, s.slug, s.excerpt,
                       s.cover_image, s.read_time_minutes, s.status, s.created_at, s.updated_at,
                       u.username AS author_name, u.display_name AS author_display_name, u.profile_image AS author_avatar,
                       r.title AS recipe_title, r.image_url AS recipe_image_url,
                       (SELECT COUNT(*) FROM story_likes sl WHERE sl.story_id = s.id) AS like_count
                FROM recipe_stories s
                LEFT JOIN users u ON s.user_id = u.id
                LEFT JOIN recipes r ON s.recipe_id = r.id
                WHERE s.recipe_id = :recipe_id
                  AND s.status = 'published'
                ORDER BY s.created_at DESC, s.id DESC
            ";
            $sStmt = $this->db->prepare($storiesSql);
            $sStmt->execute([':recipe_id' => $id]);
            $rawStories = $sStmt->fetchAll(\PDO::FETCH_ASSOC);

            $stories = array_map(static function (array $s): array {
                return [
                    'id' => (int)$s['id'],
                    'recipeId' => (int)$s['recipe_id'],
                    'recipe_id' => (int)$s['recipe_id'],
                    'recipeTitle' => $s['recipe_title'] ?? 'Recipe',
                    'recipeImageUrl' => $s['recipe_image_url'] ?? '',
                    'authorId' => !empty($s['user_id']) ? (int)$s['user_id'] : null,
                    'authorName' => $s['author_name'] ?? 'Chef',
                    'authorDisplayName' => $s['author_display_name'] ?? null,
                    'authorAvatar' => $s['author_avatar'] ?? null,
                    'title' => $s['title'],
                    'slug' => $s['slug'],
                    'excerpt' => $s['excerpt'],
                    'coverImage' => $s['cover_image'] ?: ($s['recipe_image_url'] ?? null),
                    'readTimeMinutes' => (int)($s['read_time_minutes'] ?? 1),
                    'likeCount' => (int)($s['like_count'] ?? 0),
                    'status' => $s['status'] ?? 'published',
                    'createdAt' => $s['created_at'],
                    'updatedAt' => $s['updated_at'],
                ];
            }, $rawStories);

            echo json_encode([
                'success' => true,
                'recipe' => $formattedTarget,
                'treeRootId' => $treeRootId,
                'ancestors' => $ancestors,
                'variants' => $variants,
                'stories' => $stories,
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load recipe lineage: ' . $e->getMessage(),
            ]);
        }
    }
    public function saveGeneratedRecipe() {
        $db = Database::connect();
        RecipeVariantService::ensureSchema($db);
        $data = json_decode(file_get_contents("php://input"), true);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $userId = $_SESSION['user_id'] ?? null;

        // 1. Unpack the data from React
        $rawTitle = trim((string)($data['title'] ?? ''));
        $rawTitle = preg_replace('/^\[(.+)\]$/u', '$1', $rawTitle);
        $title = trim((string)$rawTitle) !== '' ? trim((string)$rawTitle) : 'Emma\'s AI Creation';
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
        $isMasterpieceFlag = !empty($data['isMasterpiece']);

        $isPublic = 0; 
        $imageSource = 'none';
        $hasRealImage = false;
        
        $rawImageUrl = trim((string)($data['imageUrl'] ?? '')); 
        $finalImageUrl = '';

        // 2. Handle the Image
        if (!empty($rawImageUrl) && strpos($rawImageUrl, 'data:image') === 0) {
            $parts = explode(';', $rawImageUrl, 2);
            $dataParts = explode(',', $parts[1] ?? '', 2);
            $decodedData = base64_decode($dataParts[1] ?? '', true);
            $imgInfo = ($decodedData !== false && strlen($decodedData) <= 10 * 1024 * 1024)
                ? @getimagesizefromstring($decodedData)
                : false;

            $mimeToExt = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];

            if ($imgInfo === false || !isset($mimeToExt[$imgInfo['mime'] ?? ''])) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Invalid image data provided."]);
                return;
            }

            (new ImageModerationService())->assertImageSafe($decodedData, $imgInfo['mime'], 'recipe');

            $ext = $mimeToExt[$imgInfo['mime']];
            $filename = time() . '_' . bin2hex(random_bytes(4)) . '_ai_recipe_img.' . $ext;
            $uploadDir = __DIR__ . '/../../public/images/';

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            file_put_contents($uploadDir . $filename, $decodedData);
            $finalImageUrl = '/images/' . $filename;
            $imageSource = 'ai';
            $hasRealImage = true;
        } elseif ($rawImageUrl !== '' && !str_contains($rawImageUrl, 'default-veggie-vault-placeholder')) {
            $finalImageUrl = $rawImageUrl;
            $imageSource = 'ai';
            $hasRealImage = true;
        } else {
            $finalImageUrl = '/images/default-veggie-vault-placeholder.jpg';
        }

        // Compute deterministic USDA FoodData Central macros/micros BEFORE opening SQL transaction
        $usdaResult = null;
        if (!empty($ingredients) && is_array($ingredients)) {
            try {
                $usdaService = new UsdaNutritionService($db);
                $usdaCandidate = $usdaService->calculateRecipeNutrition($ingredients, $yields, $title);
                if (!empty($usdaCandidate['success'])) {
                    $usdaResult = $usdaCandidate;
                }
            } catch (\Throwable $e) {
                error_log('USDA calculation skipped on saveGeneratedRecipe: ' . $e->getMessage());
            }
        }

        $clientMicros = $data['micros'] ?? [];
        $hasMasterpieceNutrition = (!empty($clientMicros) && is_array($clientMicros))
            || ($usdaResult && !empty($usdaResult['micros']))
            || str_contains($notes, 'Advanced Nutritional Analysis')
            || $isMasterpieceFlag;

        $isPublic = ($hasRealImage && $hasMasterpieceNutrition) ? 1 : 0;
        $isMasterpieceCol = ($isPublic || $isMasterpieceFlag) ? 1 : 0;

        $wantsFullMicros = $hasMasterpieceNutrition || $isPublic;
        if ($wantsFullMicros && $usdaResult && !empty($usdaResult['deep_dive'])) {
            $notes = $this->upsertDeepDiveInNotes($notes, (string)$usdaResult['deep_dive']);
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
                (user_id, title, description, notes, image_url, yields, prep_time_mins, cook_time_mins, is_wfpb, is_oil_free, is_gluten_free, is_sugar_free, is_public, is_masterpiece, image_source) 
                VALUES 
                (:user_id, :title, :description, :notes, :image_url, :yields, :prep_time_mins, :cook_time_mins, 1, :is_oil_free, :is_gluten_free, :is_sugar_free, :is_public, :is_masterpiece, :image_source)";
            
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
                ':is_masterpiece' => $isMasterpieceCol,
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

            // STEP D: Insert Macros (prefer deterministic USDA calculation, fallback to parsed estimates)
            if ($usdaResult && !empty($usdaResult['macros'])) {
                $usdaMacros = $usdaResult['macros'];
                $calories = (float)$usdaMacros['calories'];
                $protein_g = (float)$usdaMacros['protein_g'];
                $carbs_g = (float)$usdaMacros['carbs_g'];
                $fat_g = (float)$usdaMacros['fat_g'];
                $fiber_g = (float)$usdaMacros['fiber_g'];
                $mathCalc = (string)($usdaMacros['math_calculations'] ?? '');
            } else {
                $calories = $data['calories'] ?? 0;
                $protein_g = $data['protein_g'] ?? 0;
                $carbs_g = $data['carbs_g'] ?? 0;
                $fat_g = $data['fat_g'] ?? 0;
                $fiber_g = $data['fiber_g'] ?? 0;
                $mathCalc = '';
            }

            // Only insert if we actually captured some nutritional data
            if ($calories > 0 || $protein_g > 0 || $fat_g > 0 || $carbs_g > 0) {
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
                    ':math_calculations' => $mathCalc
                ]);
            }

            // STEP E: Insert Micros (use USDA micros when Masterpiece analysis or micros requested)
            $micros = ($wantsFullMicros && $usdaResult && !empty($usdaResult['micros']))
                ? $usdaResult['micros']
                : $clientMicros;

            if (!empty($micros) && is_array($micros)) {
                $sqlMicros = "INSERT INTO micros (recipe_id, nutrient_name, amount, unit, daily_value_percentage, math_calculations)
                              VALUES (:recipe_id, :nutrient_name, :amount, :unit, :daily_value_percentage, :math_calculations)";
                $stmtMicros = $db->prepare($sqlMicros);
                foreach ($micros as $micro) {
                    $dvVal = min(999.99, max(0.0, (float)($micro['dv'] ?? $micro['daily_value_percentage'] ?? 0)));
                    $stmtMicros->execute([
                        ':recipe_id' => $recipeId,
                        ':nutrient_name' => substr((string)($micro['name'] ?? $micro['nutrient_name'] ?? 'Unknown'), 0, 100),
                        ':amount' => (float)($micro['amount'] ?? 0),
                        ':unit' => substr((string)($micro['unit'] ?? 'mg'), 0, 20),
                        ':daily_value_percentage' => $dvVal,
                        ':math_calculations' => (string)($micro['math_calculations'] ?? '')
                    ]);
                }
            }

            // Everything worked! Commit to the database.
            $db->commit();

            $msg = $isPublic ? "Brilliant! Full recipe vaulted and published to the feed." : "Smashing! Text draft locked in your private vault.";
            echo json_encode([
                "success" => true,
                "recipeId" => (int)$recipeId,
                "isPublic" => (bool)$isPublic,
                "message" => $msg,
            ]);

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
        $moderationService = new ImageModerationService();
        $stagedFiles = [];

        foreach ($names as $idx => $origName) {
            if (($errors[$idx] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            if (($sizes[$idx] ?? 0) > 10 * 1024 * 1024) {
                continue;
            }
            $tmp = $tmpNames[$idx] ?? '';
            $imgInfo = ($tmp !== '') ? @getimagesize($tmp) : false;
            $mimeToExt = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            if ($imgInfo === false || !isset($mimeToExt[$imgInfo['mime'] ?? ''])) {
                continue;
            }

            $rawBytes = @file_get_contents($tmp);
            if ($rawBytes === false || $rawBytes === '') {
                continue;
            }

            // Moderate every upload in memory before moving any file onto disk
            $moderationService->assertImageSafe($rawBytes, $imgInfo['mime'], 'recipe');

            $stagedFiles[] = [
                'tmp' => $tmp,
                'ext' => $mimeToExt[$imgInfo['mime']],
            ];
        }

        $uploadedUrls = [];
        foreach ($stagedFiles as $file) {
            $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $file['ext'];
            if (@move_uploaded_file($file['tmp'], $uploadDir . $filename)) {
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
        $stmt = $this->db->prepare("SELECT id, user_id, yields, notes, image_url, image_source, is_public FROM recipes WHERE id = ?");
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

        $base64Body = $rawImage;
        if (strpos($rawImage, 'data:image') === 0) {
            $parts = explode(';', $rawImage, 2);
            $dataParts = explode(',', $parts[1] ?? '', 2);
            $base64Body = $dataParts[1] ?? '';
        }

        $decodedData = base64_decode($base64Body, true);
        $imgInfo = ($decodedData !== false && !empty($decodedData) && strlen($decodedData) <= 10 * 1024 * 1024)
            ? @getimagesizefromstring($decodedData)
            : false;
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if ($imgInfo === false || !isset($mimeToExt[$imgInfo['mime'] ?? ''])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid image data provided."]);
            return;
        }

        (new ImageModerationService())->assertImageSafe($decodedData, $imgInfo['mime'], 'recipe');

        $ext = $mimeToExt[$imgInfo['mime']];
        $prefix = $source === 'ai' ? 'ai_recipe_' : 'user_recipe_';
        $filename = time() . '_' . bin2hex(random_bytes(4)) . '_' . $prefix . $recipeId . '.' . $ext;
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
     * Replaces macros and/or micros rows for a recipe inside an active transaction.
     */
    private function replaceNutritionRowsInDb(int $recipeId, ?array $macros, ?array $micros): void {
        if ($macros !== null) {
            $delMacros = $this->db->prepare("DELETE FROM macros WHERE recipe_id = ?");
            $delMacros->execute([$recipeId]);

            $insMacros = $this->db->prepare(
                "INSERT INTO macros (recipe_id, calories, protein_g, carbs_g, fat_g, fiber_g, math_calculations)
                 VALUES (:recipe_id, :calories, :protein_g, :carbs_g, :fat_g, :fiber_g, :math_calculations)"
            );
            $insMacros->execute([
                ':recipe_id' => $recipeId,
                ':calories' => (float)($macros['calories'] ?? 0),
                ':protein_g' => (float)($macros['protein_g'] ?? 0),
                ':carbs_g' => (float)($macros['carbs_g'] ?? 0),
                ':fat_g' => (float)($macros['fat_g'] ?? 0),
                ':fiber_g' => (float)($macros['fiber_g'] ?? 0),
                ':math_calculations' => (string)($macros['math_calculations'] ?? ''),
            ]);
        }

        if ($micros !== null && is_array($micros) && !empty($micros)) {
            $delMicros = $this->db->prepare("DELETE FROM micros WHERE recipe_id = ?");
            $delMicros->execute([$recipeId]);

            $insMicros = $this->db->prepare(
                "INSERT INTO micros (recipe_id, nutrient_name, amount, unit, daily_value_percentage, math_calculations)
                 VALUES (:recipe_id, :nutrient_name, :amount, :unit, :daily_value_percentage, :math_calculations)"
            );
            foreach ($micros as $micro) {
                $dvVal = min(999.99, max(0.0, (float)($micro['dv'] ?? $micro['daily_value_percentage'] ?? 0)));
                $insMicros->execute([
                    ':recipe_id' => $recipeId,
                    ':nutrient_name' => substr((string)($micro['name'] ?? $micro['nutrient_name'] ?? 'Unknown'), 0, 100),
                    ':amount' => (float)($micro['amount'] ?? 0),
                    ':unit' => substr((string)($micro['unit'] ?? 'mg'), 0, 20),
                    ':daily_value_percentage' => $dvVal,
                    ':math_calculations' => (string)($micro['math_calculations'] ?? ''),
                ]);
            }
        }
    }

    /**
     * Replaces any prior Advanced Nutritional Analysis / Emma's Deep Dive section
     * in recipe notes while preserving preceding Chef's Notes.
     */
    private function upsertDeepDiveInNotes(string $existingNotes, string $newDeepDive): string {
        $trimmedNew = trim($newDeepDive);
        if ($trimmedNew === '') {
            return trim($existingNotes);
        }

        $baseNotes = preg_replace(
            '/(?:\r?\n)*#{1,3}\s*(?:Advanced\s+Nutritional\s+Analysis|Emma\'?s\s+Deep\s+Dive(?:\s+Analysis)?)[\s\S]*$/i',
            '',
            trim($existingNotes)
        );
        $baseNotes = trim((string)$baseNotes);

        return $baseNotes !== ''
            ? $baseNotes . "\n\n### Advanced Nutritional Analysis\n" . $trimmedNew
            : "### Advanced Nutritional Analysis\n" . $trimmedNew;
    }

    /**
     * POST /recipes/calculate-nutrition
     * Runs deterministic USDA FoodData Central calculation for a saved recipe (or ingredient preview).
     */
    public function calculateNutrition() {
        $currentUser = $this->requireAuth();
        $userId = (int)$currentUser['id'];

        $data = json_decode(file_get_contents("php://input"), true) ?: [];
        $recipeId = (int)($data['recipe_id'] ?? 0);
        $ingredients = isset($data['ingredients']) && is_array($data['ingredients']) ? $data['ingredients'] : [];
        $yields = trim((string)($data['yields'] ?? ''));
        $recipe = null;

        if ($recipeId > 0) {
            $recipe = $this->requireOwnedRecipe($recipeId, $userId);
            if ($yields === '') {
                $yields = (string)($recipe['yields'] ?? '4 servings');
            }
            if (empty($ingredients)) {
                $ingStmt = $this->db->prepare("SELECT quantity, unit, ingredient_name FROM ingredients WHERE recipe_id = ?");
                $ingStmt->execute([$recipeId]);
                $ingredients = $ingStmt->fetchAll(\PDO::FETCH_ASSOC);
            }
        } elseif ($yields === '') {
            $yields = '4 servings';
        }

        if (empty($ingredients)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Ingredients are required to calculate nutrition."]);
            return;
        }

        $recipeTitle = $recipe
            ? (string)($recipe['title'] ?? '')
            : trim((string)($data['title'] ?? ''));

        $usdaService = new UsdaNutritionService($this->db);
        $calc = $usdaService->calculateRecipeNutrition($ingredients, $yields, $recipeTitle);

        if (empty($calc['success'])) {
            $statusCode = !empty($calc['rateLimited']) ? 503 : 422;
            http_response_code($statusCode);
            echo json_encode([
                "success" => false,
                "message" => $calc['message'] ?? 'Unable to calculate nutrition from USDA FoodData Central.',
                "rateLimited" => !empty($calc['rateLimited']),
                "breakdown" => $calc['breakdown'] ?? [],
            ]);
            return;
        }

        if ($recipeId > 0) {
            try {
                $this->db->beginTransaction();
                $this->replaceNutritionRowsInDb($recipeId, $calc['macros'], $calc['micros']);
                $updatedNotes = $this->upsertDeepDiveInNotes(
                    (string)($recipe['notes'] ?? ''),
                    (string)($calc['deep_dive'] ?? '')
                );
                $updNotesStmt = $this->db->prepare("UPDATE recipes SET notes = :notes WHERE id = :id");
                $updNotesStmt->execute([
                    ':notes' => $updatedNotes,
                    ':id' => $recipeId,
                ]);
                $this->db->commit();

                $macroStmt = $this->db->prepare("SELECT * FROM macros WHERE recipe_id = ?");
                $macroStmt->execute([$recipeId]);
                $savedMacros = $macroStmt->fetch(\PDO::FETCH_ASSOC) ?: $calc['macros'];

                $microStmt = $this->db->prepare("SELECT * FROM micros WHERE recipe_id = ?");
                $microStmt->execute([$recipeId]);
                $savedMicros = $microStmt->fetchAll(\PDO::FETCH_ASSOC);

                echo json_encode([
                    "success" => true,
                    "source" => "usda_fdc",
                    "usingDemoKey" => !empty($calc['usingDemoKey']),
                    "servings" => $calc['servings'],
                    "matchedCount" => $calc['matchedCount'],
                    "totalIngredients" => $calc['totalIngredients'],
                    "notes" => $updatedNotes,
                    "deep_dive" => (string)($calc['deep_dive'] ?? ''),
                    "macros" => $savedMacros,
                    "micros" => $savedMicros,
                    "breakdown" => $calc['breakdown'],
                    "message" => sprintf(
                        "USDA nutrition updated (%d/%d ingredients matched).",
                        (int)$calc['matchedCount'],
                        (int)$calc['totalIngredients']
                    ),
                ]);
                return;
            } catch (\Exception $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                http_response_code(500);
                echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
                return;
            }
        }

        echo json_encode($calc);
    }

    /**
     * Adds nutritional analysis and micronutrients to a saved draft/recipe.
     * Prefers deterministic USDA FoodData Central calculation and falls back to client Deep Dive only when USDA is unavailable.
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

        if ($recipeId <= 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Recipe ID is required."]);
            return;
        }

        $recipe = $this->requireOwnedRecipe($recipeId, $userId);

        // Attempt deterministic USDA FoodData Central calculation BEFORE transaction
        $usdaResult = null;
        try {
            $ingStmt = $this->db->prepare("SELECT quantity, unit, ingredient_name FROM ingredients WHERE recipe_id = ?");
            $ingStmt->execute([$recipeId]);
            $storedIngredients = $ingStmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!empty($storedIngredients)) {
                $usdaService = new UsdaNutritionService($this->db);
                $candidate = $usdaService->calculateRecipeNutrition(
                    $storedIngredients,
                    (string)($recipe['yields'] ?? '4 servings'),
                    (string)($recipe['title'] ?? '')
                );
                if (!empty($candidate['success'])) {
                    $usdaResult = $candidate;
                }
            }
        } catch (\Throwable $e) {
            error_log('USDA calculation skipped on upgradeRecipeNutrition: ' . $e->getMessage());
        }

        $effectiveMicros = ($usdaResult && !empty($usdaResult['micros'])) ? $usdaResult['micros'] : $micros;
        $effectiveMacros = ($usdaResult && !empty($usdaResult['macros'])) ? $usdaResult['macros'] : null;
        $effectiveDeepDive = ($usdaResult && !empty($usdaResult['deep_dive']))
            ? trim((string)$usdaResult['deep_dive'])
            : $deepDive;

        if ($usdaResult === null && $effectiveDeepDive === '' && (empty($effectiveMicros) || !is_array($effectiveMicros))) {
            http_response_code(422);
            echo json_encode([
                "success" => false,
                "usdaUnavailable" => true,
                "message" => "USDA FoodData Central could not calculate nutrition for this recipe."
            ]);
            return;
        }

        try {
            $this->db->beginTransaction();

            $existingNotes = trim((string)($recipe['notes'] ?? ''));
            $updatedNotes = $this->upsertDeepDiveInNotes($existingNotes, $effectiveDeepDive);

            $hasImage = $this->hasRealImage($recipe);
            $hasNutrition = (is_array($effectiveMicros) && !empty($effectiveMicros))
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

            $this->replaceNutritionRowsInDb(
                $recipeId,
                $effectiveMacros,
                (is_array($effectiveMicros) && !empty($effectiveMicros)) ? $effectiveMicros : null
            );

            $this->db->commit();

            $macroStmt = $this->db->prepare("SELECT * FROM macros WHERE recipe_id = ?");
            $macroStmt->execute([$recipeId]);
            $savedMacros = $macroStmt->fetch(\PDO::FETCH_ASSOC);

            $microStmt = $this->db->prepare("SELECT * FROM micros WHERE recipe_id = ?");
            $microStmt->execute([$recipeId]);
            $savedMicros = $microStmt->fetchAll(\PDO::FETCH_ASSOC);

            echo json_encode([
                "success" => true,
                "notes" => $updatedNotes,
                "deep_dive" => $effectiveDeepDive,
                "macros" => $savedMacros ?: null,
                "micros" => $savedMicros,
                "usdaCalculated" => $usdaResult !== null,
                "isPublic" => (bool)$newIsPublic,
                "message" => $newIsPublic
                    ? "Masterpiece nutrition added and recipe published!"
                    : "Masterpiece nutritional analysis saved! Add a dish photo to publish."
            ]);
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
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
            RecipeVariantService::ensureSchema($db);
            StoryService::ensureSchema($db);
            $sql = "SELECT r.id, r.user_id, r.parent_recipe_id, r.root_recipe_id, r.is_masterpiece, r.variant_summary,
                           r.title, r.description, r.image_url, r.yields, 
                           r.prep_time_mins, r.cook_time_mins, r.is_wfpb, r.is_oil_free,
                           r.is_gluten_free, r.is_sugar_free,
                           r.is_public, r.image_source, r.created_at, r.average_rating, r.rating_count,
                           rf.created_at as favorited_at,
                           u.username AS author_name,
                           u.display_name AS author_display_name,
                           u.profile_image AS author_avatar,
                           pr.title AS parent_recipe_title,
                           pr.user_id AS parent_author_id,
                           pu.username AS parent_author_name,
                           CASE WHEN uf.followed_id IS NOT NULL THEN 1 ELSE 0 END AS is_followed_author,
                           (SELECT COUNT(*) FROM recipes rv WHERE (rv.parent_recipe_id = r.id OR rv.root_recipe_id = r.id) AND rv.id != r.id AND rv.is_public = 1) AS variant_count,
                           (SELECT COUNT(*) FROM recipe_stories rs WHERE rs.recipe_id = r.id AND rs.status = 'published') AS story_count
                    FROM recipe_favorites rf
                    JOIN recipes r ON rf.recipe_id = r.id
                    LEFT JOIN users u ON r.user_id = u.id
                    LEFT JOIN recipes pr ON r.parent_recipe_id = pr.id
                    LEFT JOIN users pu ON pr.user_id = pu.id
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
                $parentRecipeId = !empty($r['parent_recipe_id']) ? (int)$r['parent_recipe_id'] : null;
                $rootRecipeId = !empty($r['root_recipe_id']) ? (int)$r['root_recipe_id'] : null;
                return [
                    'id' => (int)$r['id'],
                    'user_id' => $r['user_id'] ? (int)$r['user_id'] : null,
                    'authorId' => $r['user_id'] ? (int)$r['user_id'] : null,
                    'parent_recipe_id' => $parentRecipeId,
                    'parentRecipeId' => $parentRecipeId,
                    'root_recipe_id' => $rootRecipeId,
                    'rootRecipeId' => $rootRecipeId,
                    'is_masterpiece' => (bool)($r['is_masterpiece'] ?? 0),
                    'isMasterpiece' => (bool)($r['is_masterpiece'] ?? 0),
                    'isVariant' => $parentRecipeId !== null,
                    'variant_summary' => $r['variant_summary'] ?? null,
                    'variantSummary' => $r['variant_summary'] ?? null,
                    'parentRecipeTitle' => $r['parent_recipe_title'] ?? null,
                    'parentAuthorId' => !empty($r['parent_author_id']) ? (int)$r['parent_author_id'] : null,
                    'parentAuthorName' => $parentRecipeId !== null ? ($r['parent_author_name'] ?? 'Emma (AI)') : null,
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

                    'variant_count' => (int)($r['variant_count'] ?? 0),
                    'variantCount' => (int)($r['variant_count'] ?? 0),
                    'story_count' => (int)($r['story_count'] ?? 0),
                    'storyCount' => (int)($r['story_count'] ?? 0),

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
        $rawBody = trim((string)($data['body'] ?? ''));
        $rawImage = $data['image'] ?? null;

        // Take identity from session, not client input
        $authorName = $currentUser['username'];
        $userId = $currentUser['id'];

        if (!$recipeId || $rawBody === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Cannot post an empty comment."]);
            return;
        }

        (new ProfanityFilterService())->assertClean($rawBody, 'comment');
        $body = htmlspecialchars($rawBody);

        $finalImageUrl = null;

        // Compress and save image attachment if present
        if (!empty($rawImage) && strpos($rawImage, 'data:image') === 0) {
            $parts = explode(';', $rawImage, 2);
            $type = $parts[0] ?? '';
            $dataParts = explode(',', $parts[1] ?? '', 2);
            $decodedData = base64_decode($dataParts[1] ?? '', true);
            $imgInfo = ($decodedData !== false && !empty($decodedData) && strlen($decodedData) <= 10 * 1024 * 1024)
                ? @getimagesizefromstring($decodedData)
                : false;

            if ($imgInfo === false || !in_array($imgInfo['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Invalid image attachment provided."]);
                return;
            }

            (new ImageModerationService())->assertImageSafe($decodedData, $imgInfo['mime'], 'comment');

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
            $newCommentId = (int)$db->lastInsertId();

            // Create notifications for recipe owner (and parent comment author if replying)
            try {
                $rStmt = $db->prepare("SELECT user_id, title FROM recipes WHERE id = :rid");
                $rStmt->execute([':rid' => $recipeId]);
                $recipeRow = $rStmt->fetch(\PDO::FETCH_ASSOC);

                $recipeOwnerId = $recipeRow ? (int)$recipeRow['user_id'] : 0;
                $recipeTitle = $recipeRow['title'] ?? 'your recipe';
                $snippet = mb_substr(html_entity_decode($body, ENT_QUOTES, 'UTF-8'), 0, 80);
                if (mb_strlen($body) > 80) {
                    $snippet .= '…';
                }

                $notifStmt = $db->prepare("
                    INSERT INTO notifications (user_id, actor_user_id, actor_username, recipe_id, comment_id, type, message)
                    VALUES (:uid, :actor_id, :actor_name, :rid, :cid, :type, :msg)
                ");

                if ($recipeOwnerId > 0 && $recipeOwnerId !== (int)$userId) {
                    $notifStmt->execute([
                        ':uid' => $recipeOwnerId,
                        ':actor_id' => (int)$userId,
                        ':actor_name' => $authorName,
                        ':rid' => (int)$recipeId,
                        ':cid' => $newCommentId,
                        ':type' => 'recipe_comment',
                        ':msg' => "{$authorName} commented on \"{$recipeTitle}\": \"{$snippet}\""
                    ]);
                }

                if (!empty($parentId)) {
                    $pStmt = $db->prepare("SELECT user_id FROM recipe_comments WHERE id = :pid");
                    $pStmt->execute([':pid' => (int)$parentId]);
                    $parentOwnerId = (int)$pStmt->fetchColumn();
                    if ($parentOwnerId > 0 && $parentOwnerId !== (int)$userId && $parentOwnerId !== $recipeOwnerId) {
                        $notifStmt->execute([
                            ':uid' => $parentOwnerId,
                            ':actor_id' => (int)$userId,
                            ':actor_name' => $authorName,
                            ':rid' => (int)$recipeId,
                            ':cid' => $newCommentId,
                            ':type' => 'comment_reply',
                            ':msg' => "{$authorName} replied to your comment on \"{$recipeTitle}\": \"{$snippet}\""
                        ]);
                    }
                }
            } catch (\Exception $notifErr) {
                // Never fail comment posting if notifications fail
            }
            
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

    private function ensureRecipeImagesTable(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS recipe_images (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recipe_id INT NOT NULL,
                user_id INT NOT NULL,
                image_url VARCHAR(500) NOT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'user',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_recipe_id (recipe_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function seedOriginalRecipeImageIfNeeded(int $recipeId, int $ownerUserId, string $currentImageUrl, string $currentSource): void {
        $cleanUrl = trim($currentImageUrl);
        if ($cleanUrl === '' || str_contains($cleanUrl, 'default-veggie-vault-placeholder')) {
            return;
        }
        $check = $this->db->prepare("SELECT id FROM recipe_images WHERE recipe_id = ? AND image_url = ? LIMIT 1");
        $check->execute([$recipeId, $cleanUrl]);
        if (!$check->fetchColumn()) {
            $src = ($currentSource === 'ai' || str_contains($cleanUrl, '_ai_recipe_')) ? 'ai' : 'user';
            $ins = $this->db->prepare("INSERT INTO recipe_images (recipe_id, user_id, image_url, source) VALUES (?, ?, ?, ?)");
            $ins->execute([$recipeId, $ownerUserId, $cleanUrl, $src]);
        }
    }

    private function getRecipeGalleryImages(int $recipeId, string $primaryImageUrl = '', string $primarySource = 'none'): array {
        $this->ensureRecipeImagesTable();
        $stmt = $this->db->prepare("SELECT id, recipe_id, user_id, image_url, source, created_at FROM recipe_images WHERE recipe_id = ? ORDER BY id ASC");
        $stmt->execute([$recipeId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $cleanPrimary = trim($primaryImageUrl);
        if ($cleanPrimary !== '' && !str_contains($cleanPrimary, 'default-veggie-vault-placeholder')) {
            $found = false;
            foreach ($rows as $row) {
                if (($row['image_url'] ?? '') === $cleanPrimary) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                array_unshift($rows, [
                    'id' => 0,
                    'recipe_id' => $recipeId,
                    'user_id' => 0,
                    'image_url' => $cleanPrimary,
                    'source' => ($primarySource === 'ai' || str_contains($cleanPrimary, '_ai_recipe_')) ? 'ai' : 'user',
                    'created_at' => null,
                ]);
            }
        }

        return $rows;
    }

    /**
     * POST /recipes/photos
     * Allows the recipe creator to upload one or more additional dish photos to a recipe.
     * Keeps the existing primary cover photo (e.g. Emma's AI image) unless set_primary is true.
     */
    public function addRecipePhotos() {
        $currentUser = $this->requireAuth();
        $userId = (int)$currentUser['id'];
        $this->ensureRecipeImagesTable();

        $data = json_decode(file_get_contents("php://input"), true) ?: [];
        $recipeId = (int)($data['recipe_id'] ?? 0);
        $rawImages = $data['images'] ?? [];
        $setPrimary = !empty($data['set_primary']);

        if ($recipeId <= 0 || !is_array($rawImages) || empty($rawImages)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Recipe ID and at least one image are required."]);
            return;
        }

        $recipe = $this->requireOwnedRecipe($recipeId, $userId);
        $ownerUserId = (int)($recipe['user_id'] ?? $userId);
        $currentPrimary = (string)($recipe['image_url'] ?? '');
        $currentSource = (string)($recipe['image_source'] ?? 'none');

        $this->seedOriginalRecipeImageIfNeeded($recipeId, $ownerUserId, $currentPrimary, $currentSource);

        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        $uploadDir = __DIR__ . '/../../public/images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $moderationService = new ImageModerationService();
        $stagedPhotos = [];
        foreach (array_slice($rawImages, 0, 8) as $rawImage) {
            if (!is_string($rawImage) || trim($rawImage) === '') {
                continue;
            }
            $base64Body = $rawImage;
            if (strpos($rawImage, 'data:image') === 0) {
                $parts = explode(';', $rawImage, 2);
                $dataParts = explode(',', $parts[1] ?? '', 2);
                $base64Body = $dataParts[1] ?? '';
            }
            $decodedData = base64_decode($base64Body, true);
            $imgInfo = ($decodedData !== false && !empty($decodedData) && strlen($decodedData) <= 10 * 1024 * 1024)
                ? @getimagesizefromstring($decodedData)
                : false;

            if ($imgInfo === false || !isset($mimeToExt[$imgInfo['mime'] ?? ''])) {
                continue;
            }

            // Moderate every photo in memory before writing any image to disk
            $modRes = $moderationService->moderateImageBytes($decodedData, $imgInfo['mime'], 'recipe');
            if (empty($modRes['safe'])) {
                http_response_code((int)($modRes['status'] ?? 400));
                echo json_encode([
                    "success" => false,
                    "message" => $modRes['message'] ?? 'This image cannot be uploaded because it appears to contain inappropriate content.',
                ]);
                return;
            }

            $stagedPhotos[] = [
                'bytes' => $decodedData,
                'ext' => $mimeToExt[$imgInfo['mime']],
            ];
        }

        if (empty($stagedPhotos)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "No valid images could be saved."]);
            return;
        }

        $savedUrls = [];
        foreach ($stagedPhotos as $photo) {
            $filename = time() . '_' . bin2hex(random_bytes(4)) . '_user_recipe_' . $recipeId . '.' . $photo['ext'];
            if (file_put_contents($uploadDir . $filename, $photo['bytes']) !== false) {
                $url = '/images/' . $filename;
                $ins = $this->db->prepare("INSERT INTO recipe_images (recipe_id, user_id, image_url, source) VALUES (?, ?, ?, 'user')");
                $ins->execute([$recipeId, $userId, $url]);
                $savedUrls[] = $url;
            }
        }

        if (empty($savedUrls)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "No valid images could be saved."]);
            return;
        }

        $newPrimary = $currentPrimary;
        if ($setPrimary || $currentPrimary === '' || str_contains($currentPrimary, 'default-veggie-vault-placeholder')) {
            $newPrimary = $savedUrls[0];
            $upd = $this->db->prepare("UPDATE recipes SET image_url = ?, image_source = 'user' WHERE id = ?");
            $upd->execute([$newPrimary, $recipeId]);
        }

        $gallery = $this->getRecipeGalleryImages($recipeId, $newPrimary, $currentSource);
        echo json_encode([
            "success" => true,
            "message" => count($savedUrls) === 1 ? "Photo added to recipe!" : count($savedUrls) . " photos added to recipe!",
            "image_url" => $newPrimary,
            "images" => $gallery,
        ]);
    }

    /**
     * PUT /recipes/primary-photo
     * Lets the recipe creator choose which gallery image is shown publicly on the recipe.
     */
    public function setPrimaryPhoto() {
        $currentUser = $this->requireAuth();
        $userId = (int)$currentUser['id'];
        $this->ensureRecipeImagesTable();

        $data = json_decode(file_get_contents("php://input"), true) ?: [];
        $recipeId = (int)($data['recipe_id'] ?? 0);
        $imageUrl = trim((string)($data['image_url'] ?? ''));

        if ($recipeId <= 0 || $imageUrl === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Recipe ID and image URL are required."]);
            return;
        }

        $recipe = $this->requireOwnedRecipe($recipeId, $userId);
        $ownerUserId = (int)($recipe['user_id'] ?? $userId);
        $this->seedOriginalRecipeImageIfNeeded(
            $recipeId,
            $ownerUserId,
            (string)($recipe['image_url'] ?? ''),
            (string)($recipe['image_source'] ?? 'none')
        );

        $stmt = $this->db->prepare("SELECT image_url, source FROM recipe_images WHERE recipe_id = ? AND image_url = ? LIMIT 1");
        $stmt->execute([$recipeId, $imageUrl]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Selected photo was not found in this recipe's gallery."]);
            return;
        }

        $upd = $this->db->prepare("UPDATE recipes SET image_url = ?, image_source = ? WHERE id = ?");
        $upd->execute([$row['image_url'], $row['source'] ?: 'user', $recipeId]);

        $gallery = $this->getRecipeGalleryImages($recipeId, $row['image_url'], $row['source'] ?: 'user');
        echo json_encode([
            "success" => true,
            "message" => "Public recipe photo updated!",
            "image_url" => $row['image_url'],
            "image_source" => $row['source'] ?: 'user',
            "images" => $gallery,
        ]);
    }

    /**
     * DELETE /recipes/photos
     * Removes a photo from a recipe's gallery (cannot remove the sole remaining photo if published).
     */
    public function deleteRecipePhoto() {
        $currentUser = $this->requireAuth();
        $userId = (int)$currentUser['id'];
        $this->ensureRecipeImagesTable();

        $recipeId = (int)($_GET['recipe_id'] ?? 0);
        $imageId = (int)($_GET['image_id'] ?? 0);
        if ($recipeId <= 0 || $imageId <= 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Recipe ID and image ID are required."]);
            return;
        }

        $recipe = $this->requireOwnedRecipe($recipeId, $userId);
        $stmt = $this->db->prepare("SELECT id, image_url FROM recipe_images WHERE id = ? AND recipe_id = ? LIMIT 1");
        $stmt->execute([$imageId, $recipeId]);
        $target = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$target) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Photo not found."]);
            return;
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM recipe_images WHERE recipe_id = ?");
        $countStmt->execute([$recipeId]);
        $totalCount = (int)$countStmt->fetchColumn();
        if (!empty($recipe['is_public']) && $totalCount <= 1) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "A published recipe must keep at least one photo."]);
            return;
        }

        $del = $this->db->prepare("DELETE FROM recipe_images WHERE id = ? AND recipe_id = ?");
        $del->execute([$imageId, $recipeId]);

        $newPrimary = (string)($recipe['image_url'] ?? '');
        $newSource = (string)($recipe['image_source'] ?? 'none');
        if ($newPrimary === $target['image_url']) {
            $nextStmt = $this->db->prepare("SELECT image_url, source FROM recipe_images WHERE recipe_id = ? ORDER BY id ASC LIMIT 1");
            $nextStmt->execute([$recipeId]);
            $next = $nextStmt->fetch(\PDO::FETCH_ASSOC);
            $newPrimary = $next ? $next['image_url'] : '';
            $newSource = $next ? ($next['source'] ?: 'user') : 'none';
            $upd = $this->db->prepare("UPDATE recipes SET image_url = ?, image_source = ? WHERE id = ?");
            $upd->execute([$newPrimary, $newSource, $recipeId]);
        }

        $gallery = $this->getRecipeGalleryImages($recipeId, $newPrimary, $newSource);
        echo json_encode([
            "success" => true,
            "message" => "Photo removed.",
            "image_url" => $newPrimary,
            "images" => $gallery,
        ]);
    }

    /**
     * PUT /recipes/title
     * Lets a recipe owner (or admin) rename their own recipe directly.
     */
    public function updateRecipeTitle() {
        $currentUser = $this->requireAuth();
        $userId = (int)$currentUser['id'];

        $data = json_decode(file_get_contents("php://input"), true) ?: [];
        $recipeId = (int)($data['recipe_id'] ?? 0);
        $rawTitle = trim((string)($data['title'] ?? ''));

        $cleanTitle = preg_replace('/\s+/', ' ', $rawTitle);
        $cleanTitle = preg_replace('/^\[(.+)\]$/', '$1', trim((string)$cleanTitle));
        $cleanTitle = trim((string)$cleanTitle);

        if ($recipeId <= 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Recipe ID is required."]);
            return;
        }

        if ($cleanTitle === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Recipe name cannot be empty."]);
            return;
        }

        if (mb_strlen($cleanTitle) > 200) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Recipe name must be 200 characters or fewer."]);
            return;
        }

        (new ProfanityFilterService())->assertClean($cleanTitle, 'recipe name');

        $this->requireOwnedRecipe($recipeId, $userId);

        $stmt = $this->db->prepare("UPDATE recipes SET title = ? WHERE id = ?");
        $stmt->execute([$cleanTitle, $recipeId]);

        echo json_encode([
            "success" => true,
            "message" => "Recipe name updated!",
            "title" => $cleanTitle,
        ]);
    }

    /**
     * POST /recipes/create-variant
     * Creates a user-remixed Recipe Variant ("fork") from an existing parent recipe.
     * Pricing:
     *  - 0.5 Tokens: Base Variant (user uploads own photo)
     *  - 1.0 Token: AI Visual Variant (Emma generates a new AI dish photo)
     * Optional `include_micros` flags the variant as a Masterpiece Variant (`is_masterpiece = 1`).
     */
    public function createRecipeVariant() {
        $currentUser = $this->requireAuth();
        $userId = (int)$currentUser['id'];
        $username = (string)($currentUser['username'] ?? 'Vault Chef');

        RecipeVariantService::ensureSchema($this->db);
        $this->ensureRecipeImagesTable();

        $data = json_decode(file_get_contents("php://input"), true) ?: [];
        $parentRecipeId = (int)($data['parent_recipe_id'] ?? 0);
        $changesRequested = trim((string)($data['changes_requested'] ?? ''));
        $customTitle = trim((string)($data['title'] ?? ''));
        $imageMode = ($data['image_mode'] ?? 'upload') === 'ai' ? 'ai' : 'upload';
        $uploadedImage = trim((string)($data['uploaded_image'] ?? ''));
        $imageStyleOptions = is_array($data['image_style_options'] ?? null) ? $data['image_style_options'] : [];
        $includeMicros = !empty($data['include_micros']);

        $tokenCost = $imageMode === 'ai' ? 1.0 : 0.5;

        if ($parentRecipeId <= 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Parent recipe ID is required."]);
            return;
        }

        // Profanity filter check on requested modification text and custom title
        $profanity = new ProfanityFilterService();
        $profanity->assertClean($changesRequested, 'variant changes');
        if ($customTitle !== '') {
            $profanity->assertClean($customTitle, 'variant title');
        }

        $variantService = new RecipeVariantService($this->db);
        $preCheckError = $variantService->validateMeaningfulRequestText($changesRequested);
        if ($preCheckError !== null) {
            http_response_code(422);
            echo json_encode([
                "success" => false,
                "trivialChange" => true,
                "message" => $preCheckError,
            ]);
            return;
        }

        // Verify user token balance before running external AI calls
        $userStmt = $this->db->prepare("SELECT generation_tokens, allergies FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$userId]);
        $userRow = $userStmt->fetch(\PDO::FETCH_ASSOC);
        $currentTokens = $userRow ? (float)$userRow['generation_tokens'] : 0.0;
        $userAllergies = (string)($userRow['allergies'] ?? '');

        if ($currentTokens < $tokenCost) {
            http_response_code(403);
            echo json_encode([
                "success" => false,
                "insufficientTokens" => true,
                "requiredTokens" => $tokenCost,
                "currentTokens" => $currentTokens,
                "message" => sprintf(
                    "Oh dear! You need %.1f tokens to create this variant, but your balance is %.2f tokens.",
                    $tokenCost,
                    $currentTokens
                ),
            ]);
            return;
        }

        // Load parent recipe
        $parentStmt = $this->db->prepare("
            SELECT r.*, u.username AS author_name
            FROM recipes r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
            LIMIT 1
        ");
        $parentStmt->execute([$parentRecipeId]);
        $parentRecipe = $parentStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$parentRecipe) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Original parent recipe not found."]);
            return;
        }

        $isAdmin = !empty($_SESSION['is_admin']);
        if (empty($parentRecipe['is_public']) && (int)($parentRecipe['user_id'] ?? 0) !== $userId && !$isAdmin) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "You cannot create a variant of a private draft recipe."]);
            return;
        }

        $ingStmt = $this->db->prepare("SELECT quantity, unit, ingredient_name FROM ingredients WHERE recipe_id = ?");
        $ingStmt->execute([$parentRecipeId]);
        $parentRecipe['ingredients'] = $ingStmt->fetchAll(\PDO::FETCH_ASSOC);

        $instStmt = $this->db->prepare("SELECT step_number, instruction_text FROM instructions WHERE recipe_id = ? ORDER BY step_number ASC");
        $instStmt->execute([$parentRecipeId]);
        $parentRecipe['instructions'] = $instStmt->fetchAll(\PDO::FETCH_ASSOC);

        // If user chose 'upload' (0.5 tokens), validate and moderate their uploaded image first
        $validatedUploadBytes = null;
        $validatedUploadExt = null;
        if ($imageMode === 'upload') {
            if ($uploadedImage === '') {
                http_response_code(400);
                echo json_encode([
                    "success" => false,
                    "message" => "Please upload a dish photo for the 0.5-token Base Variant, or switch to 'Generate AI Image with Emma' (1.0 token).",
                ]);
                return;
            }

            $base64Body = $uploadedImage;
            if (strpos($uploadedImage, 'data:image') === 0) {
                $parts = explode(';', $uploadedImage, 2);
                $dataParts = explode(',', $parts[1] ?? '', 2);
                $base64Body = $dataParts[1] ?? '';
            }
            $decodedData = base64_decode($base64Body, true);
            $imgInfo = ($decodedData !== false && !empty($decodedData) && strlen($decodedData) <= 10 * 1024 * 1024)
                ? @getimagesizefromstring($decodedData)
                : false;
            $mimeToExt = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            if ($imgInfo === false || !isset($mimeToExt[$imgInfo['mime'] ?? ''])) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Invalid uploaded image format. Please choose a JPG, PNG, or WebP photo."]);
                return;
            }

            (new ImageModerationService())->assertImageSafe($decodedData, $imgInfo['mime'], 'recipe');
            $validatedUploadBytes = $decodedData;
            $validatedUploadExt = $mimeToExt[$imgInfo['mime']];
        }

        // Generate variant diff with Gemini (includes anti-clout-stealing verification)
        $diffResult = $variantService->generateVariantDiff(
            $parentRecipe,
            $changesRequested,
            $customTitle,
            $includeMicros,
            $userAllergies
        );

        if (empty($diffResult['success'])) {
            http_response_code((int)($diffResult['status'] ?? 422));
            echo json_encode([
                "success" => false,
                "trivialChange" => !empty($diffResult['trivialChange']),
                "message" => $diffResult['message'] ?? 'Could not generate recipe variant.',
            ]);
            return;
        }

        $variant = $diffResult['variant'];
        $profanity->assertClean($variant['title'], 'variant title');

        // Run deterministic USDA FoodData Central calculation on the modified ingredients
        $usdaResult = null;
        try {
            $usdaService = new UsdaNutritionService($this->db);
            $usdaCandidate = $usdaService->calculateRecipeNutrition(
                $variant['ingredients'],
                $variant['yields'],
                (string)($variant['title'] ?? '')
            );
            if (!empty($usdaCandidate['success'])) {
                $usdaResult = $usdaCandidate;
            }
        } catch (\Throwable $e) {
            error_log('USDA calculation skipped on createRecipeVariant: ' . $e->getMessage());
        }

        // If AI image mode (1.0 token), generate the AI image BEFORE opening transaction or deducting tokens
        $finalImageBytes = $validatedUploadBytes;
        $finalImageExt = $validatedUploadExt;
        $finalImageSource = 'user';

        if ($imageMode === 'ai') {
            $aiImg = $variantService->generateVariantAiImage(
                $variant['title'],
                $variant['image_prompt'] ?: $variant['title'],
                $imageStyleOptions
            );
            if (empty($aiImg['ok']) || empty($aiImg['bytes'])) {
                http_response_code(502);
                echo json_encode([
                    "success" => false,
                    "message" => ($aiImg['message'] ?? "Emma's camera jammed while painting your variant!") . " No tokens were deducted — please try again.",
                ]);
                return;
            }
            $finalImageBytes = $aiImg['bytes'];
            $finalImageExt = $aiImg['ext'] ?? 'jpg';
            $finalImageSource = 'ai';
        }

        // Write image file to /public/images/
        $uploadDir = __DIR__ . '/../../public/images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $prefix = $finalImageSource === 'ai' ? 'ai_variant_' : 'user_variant_';
        $filename = time() . '_' . bin2hex(random_bytes(4)) . '_' . $prefix . $parentRecipeId . '.' . $finalImageExt;
        if (file_put_contents($uploadDir . $filename, $finalImageBytes) === false) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to save variant image to disk. No tokens were deducted."]);
            return;
        }
        $finalImageUrl = '/images/' . $filename;

        $rootRecipeId = !empty($parentRecipe['root_recipe_id'])
            ? (int)$parentRecipe['root_recipe_id']
            : (int)$parentRecipe['id'];
        $isMasterpiece = $includeMicros ? 1 : 0;
        $effectiveVariantDeepDive = ($includeMicros && $usdaResult && !empty($usdaResult['deep_dive']))
            ? trim((string)$usdaResult['deep_dive'])
            : trim((string)($variant['deep_dive'] ?? ''));
        $notes = ($includeMicros && $effectiveVariantDeepDive !== '')
            ? "### Advanced Nutritional Analysis\n" . $effectiveVariantDeepDive
            : '';

        // Atomic MySQL Transaction: Lock user balance, deduct tokens, insert variant recipe & relations, notify parent author
        try {
            $this->db->beginTransaction();

            $lockStmt = $this->db->prepare("SELECT generation_tokens FROM users WHERE id = ? FOR UPDATE");
            $lockStmt->execute([$userId]);
            $lockedBalance = (float)$lockStmt->fetchColumn();

            if ($lockedBalance < $tokenCost) {
                $this->db->rollBack();
                @unlink($uploadDir . $filename);
                http_response_code(403);
                echo json_encode([
                    "success" => false,
                    "insufficientTokens" => true,
                    "message" => "Insufficient token balance to complete variant creation.",
                ]);
                return;
            }

            $newBalance = round($lockedBalance - $tokenCost, 2);
            $deductStmt = $this->db->prepare("UPDATE users SET generation_tokens = :bal WHERE id = :id");
            $deductStmt->execute([':bal' => $newBalance, ':id' => $userId]);

            $insRecipe = $this->db->prepare("
                INSERT INTO recipes (
                    user_id, parent_recipe_id, root_recipe_id, title, description, variant_summary,
                    notes, image_url, yields, prep_time_mins, cook_time_mins,
                    is_wfpb, is_oil_free, is_gluten_free, is_sugar_free,
                    is_public, is_masterpiece, image_source
                ) VALUES (
                    :user_id, :parent_id, :root_id, :title, :description, :variant_summary,
                    :notes, :image_url, :yields, :prep_time, :cook_time,
                    1, :is_oil_free, :is_gluten_free, :is_sugar_free,
                    1, :is_masterpiece, :image_source
                )
            ");
            $insRecipe->execute([
                ':user_id' => $userId,
                ':parent_id' => $parentRecipeId,
                ':root_id' => $rootRecipeId,
                ':title' => $variant['title'],
                ':description' => $variant['description'],
                ':variant_summary' => $variant['variant_summary'],
                ':notes' => $notes,
                ':image_url' => $finalImageUrl,
                ':yields' => $variant['yields'],
                ':prep_time' => $variant['prep_time_mins'],
                ':cook_time' => $variant['cook_time_mins'],
                ':is_oil_free' => $variant['is_oil_free'],
                ':is_gluten_free' => $variant['is_gluten_free'],
                ':is_sugar_free' => $variant['is_sugar_free'],
                ':is_masterpiece' => $isMasterpiece,
                ':image_source' => $finalImageSource,
            ]);

            $newVariantId = (int)$this->db->lastInsertId();

            // Seed gallery photo & auto-vault into user's favorites
            $imgGalleryStmt = $this->db->prepare("INSERT INTO recipe_images (recipe_id, user_id, image_url, source) VALUES (?, ?, ?, ?)");
            $imgGalleryStmt->execute([$newVariantId, $userId, $finalImageUrl, $finalImageSource]);

            $favStmt = $this->db->prepare("INSERT IGNORE INTO recipe_favorites (user_id, recipe_id) VALUES (?, ?)");
            $favStmt->execute([$userId, $newVariantId]);

            // Insert instructions
            $instIns = $this->db->prepare("INSERT INTO instructions (recipe_id, step_number, instruction_text) VALUES (?, ?, ?)");
            foreach ($variant['instructions'] as $idx => $stepText) {
                $instIns->execute([$newVariantId, $idx + 1, $stepText]);
            }

            // Insert ingredients
            $ingIns = $this->db->prepare("INSERT INTO ingredients (recipe_id, quantity, unit, ingredient_name) VALUES (?, 0.00, '', ?)");
            foreach ($variant['ingredients'] as $ingLine) {
                $ingIns->execute([$newVariantId, $ingLine]);
            }

            // Insert macros & (if requested) micros
            $macrosToSave = ($usdaResult && !empty($usdaResult['macros']))
                ? $usdaResult['macros']
                : [
                    'calories' => (float)($variant['macros']['calories'] ?? 0),
                    'protein_g' => (float)($variant['macros']['protein_g'] ?? 0),
                    'carbs_g' => (float)($variant['macros']['carbs_g'] ?? 0),
                    'fat_g' => (float)($variant['macros']['fat_g'] ?? 0),
                    'fiber_g' => (float)($variant['macros']['fiber_g'] ?? 0),
                    'math_calculations' => '',
                ];

            $microsToSave = null;
            if ($includeMicros) {
                $microsToSave = ($usdaResult && !empty($usdaResult['micros']))
                    ? $usdaResult['micros']
                    : $variant['micros'];
            }

            $this->replaceNutritionRowsInDb($newVariantId, $macrosToSave, $microsToSave);

            // Creator notification (skip if user forked their own recipe)
            $parentOwnerId = !empty($parentRecipe['user_id']) ? (int)$parentRecipe['user_id'] : 0;
            $parentTitle = (string)($parentRecipe['title'] ?? 'your recipe');
            if ($parentOwnerId > 0 && $parentOwnerId !== $userId) {
                $notifMsg = mb_substr(
                    sprintf('%s created a variant ("%s") of your recipe "%s"', $username, $variant['title'], $parentTitle),
                    0,
                    255
                );
                $notifStmt = $this->db->prepare("
                    INSERT INTO notifications (user_id, actor_user_id, actor_username, recipe_id, comment_id, type, message)
                    VALUES (:uid, :actor_id, :actor_name, :rid, NULL, 'recipe_variant', :msg)
                ");
                $notifStmt->execute([
                    ':uid' => $parentOwnerId,
                    ':actor_id' => $userId,
                    ':actor_name' => $username,
                    ':rid' => $newVariantId,
                    ':msg' => $notifMsg,
                ]);
            }

            $this->db->commit();
            $_SESSION['generation_tokens'] = $newBalance;

            echo json_encode([
                "success" => true,
                "recipeId" => $newVariantId,
                "tokensRemaining" => $newBalance,
                "tokensSpent" => $tokenCost,
                "isMasterpiece" => (bool)$isMasterpiece,
                "message" => $isMasterpiece
                    ? "Smashing! Your Masterpiece Variant has been forged and published to the Vault!"
                    : "Brilliant! Your Recipe Variant has been forged and published to the Vault!",
            ]);
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            @unlink($uploadDir . $filename);
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Database error while saving variant (no tokens were deducted): " . $e->getMessage(),
            ]);
        }
    }
}