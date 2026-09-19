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
     * Fetches the high-level list of all recipes for the Home Grid.
     * Includes core macros for the quick-view badges!
     */
public function getAllRecipes() {
        try {
            $db = Database::connect();
            
            // Notice the 'r.' prefix and the LEFT JOIN linking the users table!
            $sql = "SELECT r.id, r.title, r.description, r.image_url, r.yields, 
                           r.prep_time_mins, r.cook_time_mins, r.is_wfpb, r.is_oil_free, 
                           r.is_public, r.image_source, r.created_at, r.average_rating, r.rating_count,
                           u.username AS author_name
                    FROM recipes r
                    LEFT JOIN users u ON r.user_id = u.id
                    WHERE r.is_public = 1
                    ORDER BY r.id DESC";
                    
            $stmt = $db->query($sql);
            $recipes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $formatted = array_map(function($r) {
                return [
                    'id' => $r['id'],
                    'title' => $r['title'],
                    'authorName' => $r['author_name'] ?? 'Emma (AI)', // Falls back to me if no user is found!
                    'description' => $r['description'],
                    'prepTime' => $r['prep_time_mins'],
                    'cookTime' => $r['cook_time_mins'],
                    'imageUrl' => $r['image_url'],
                    'yields' => $r['yields'],
                    'isWfpb' => (bool)$r['is_wfpb'], 
                    'isOilFree' => (bool)$r['is_oil_free'],
                    'isDraft' => !(bool)$r['is_public'], 
                    'isPublic' => (bool)$r['is_public'],
                    'imageSource' => $r['image_source'],
                    'createdAt' => $r['created_at'],
                    'averageRating' => (float)($r['average_rating'] ?? 0),
                    'ratingCount' => (int)($r['rating_count'] ?? 0)
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
            // 2. Fetch the overarching recipe details AND the author's username
            $sql = "SELECT r.*, u.username AS author_name 
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

            // Map the author name for React
            $recipe['authorName'] = $recipe['author_name'] ?? 'Emma (AI)';
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
            $isPublic = 1; // Unlock it for the General Public Feed!
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
                (user_id, title, description, notes, image_url, yields, prep_time_mins, cook_time_mins, is_wfpb, is_oil_free, is_public, image_source) 
                VALUES 
                (:user_id, :title, :description, :notes, :image_url, :yields, :prep_time_mins, :cook_time_mins, 1, 1, :is_public, :image_source)";
            
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
            echo json_encode(["success" => true, "message" => $msg]);

        } catch (\Exception $e) {
            // Something broke. Cancel the entire transaction.
            $db->rollBack();
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
        }
    }
    /*
     * Update an existing recipe.
     * "Wipe and Replace" strategy for relational data.
     */
    public function updateRecipe() {
        if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
            http_response_code(400);
            echo json_encode(["message" => "Invalid or Missing ID"]);
            return;
        }

        $id = (int)$_GET['id'];
        $db = Database::connect();
        $data = json_decode(file_get_contents("php://input"), true);

        // A massive UPDATE statement to overwrite the old data AND our new columns
        $sql = "UPDATE recipes SET 
                title = :title, 
                description = :description, 
                ingredients = :ingredients, 
                instructions = :instructions, 
                prep_time = :prep_time, 
                cook_time = :cook_time, 
                nutrition_info = :nutrition_info, 
                notes = :notes,
                yields = :yields,
                image_url = :image_url,
                is_public = :is_public,
                image_source = :image_source
                WHERE id = :id";
        
        $stmt = $db->prepare($sql);
        $success = $stmt->execute([
            ':title' => $data['title'] ?? '',
            ':description' => $data['description'] ?? '',
            ':ingredients' => json_encode($data['ingredients'] ?? []),
            ':instructions' => json_encode($data['instructions'] ?? []),
            ':prep_time' => $data['prepTime'] ?? 0,
            ':cook_time' => $data['cookTime'] ?? 0,
            ':nutrition_info' => json_encode($data['nutritionInfo'] ?? []),
            ':notes' => $data['notes'] ?? '',
            ':yields' => $data['yields'] ?? '',
            ':image_url' => $data['imageUrl'] ?? '',
            ':is_public' => $data['isPublic'] ?? 0,
            ':image_source' => $data['imageSource'] ?? 'none',
            ':id' => $id
        ]);

        if ($success) {
            echo json_encode(["success" => true, "message" => "Recipe successfully updated!"]);
        } else {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to update the recipe."]);
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

    public function getUserFavorites() {
        $currentUser = $this->requireAuth();
        $userId = $currentUser['id'];

        try {
            // Use the class db connection
            $db = $this->db;

            // Force PDO to throw exceptions on SQL errors!
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Notice we REMOVED r.username from the SELECT list here
            $sql = "SELECT r.id, r.user_id, r.title, r.description, r.image_url, r.yields, 
                           r.prep_time_mins, r.cook_time_mins, r.is_wfpb, r.is_oil_free, 
                           r.is_public, r.image_source, r.created_at, r.average_rating, r.rating_count,
                           rf.created_at as favorited_at,
                           u.username AS author_name
                    FROM recipe_favorites rf
                    JOIN recipes r ON rf.recipe_id = r.id
                    LEFT JOIN users u ON r.user_id = u.id
                    WHERE rf.user_id = ?
                    ORDER BY rf.created_at DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute([$userId]);
            
            $recipes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Defensive programming: If the query fails, default to an empty array so array_map doesn't crash
            if (!is_array($recipes)) {
                $recipes = [];
            }

            $formatted = array_map(function($r) use ($userId) {
                return [
                    'id' => (int)$r['id'],
                    'title' => $r['title'],
                    'authorName' => $r['author_name'] ?? 'Emma (AI)',
                    'description' => $r['description'],
                    'yields' => $r['yields'],

                    // Support both snake_case (standard DB) and camelCase
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
            SELECT id, parent_id, author_name, body, image_url, likes, dislikes, created_at 
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