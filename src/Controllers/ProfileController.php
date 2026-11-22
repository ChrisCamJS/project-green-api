<?php

namespace App\Controllers;

use App\Database;
use App\Services\ImageModerationService;
use App\Services\ProfanityFilterService;
use PDO;

class ProfileController {
    private $db;

    public function __construct() {
        $this->db = Database::connect();
        $this->ensureSchema();
    }

    /**
     * Ensures profile columns on `users` and the `user_follows` table exist.
     */
    private function ensureSchema(): void {
        $existingCols = $this->db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $columnsToAdd = [
            'display_name' => "ALTER TABLE users ADD COLUMN display_name VARCHAR(100) DEFAULT NULL",
            'location' => "ALTER TABLE users ADD COLUMN location VARCHAR(120) DEFAULT NULL",
            'bio' => "ALTER TABLE users ADD COLUMN bio TEXT DEFAULT NULL",
            'profile_image' => "ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) DEFAULT NULL",
            'favorite_cuisine' => "ALTER TABLE users ADD COLUMN favorite_cuisine VARCHAR(120) DEFAULT NULL",
            'dietary_focus' => "ALTER TABLE users ADD COLUMN dietary_focus VARCHAR(120) DEFAULT NULL",
            'allergies' => "ALTER TABLE users ADD COLUMN allergies TEXT NULL DEFAULT NULL",
            'website_url' => "ALTER TABLE users ADD COLUMN website_url VARCHAR(255) DEFAULT NULL",
            'pronouns' => "ALTER TABLE users ADD COLUMN pronouns VARCHAR(50) DEFAULT NULL",
            'height_ft' => "ALTER TABLE users ADD COLUMN height_ft INT NULL DEFAULT NULL",
            'height_in' => "ALTER TABLE users ADD COLUMN height_in DECIMAL(4,1) NULL DEFAULT NULL",
            'weight_lbs' => "ALTER TABLE users ADD COLUMN weight_lbs DECIMAL(5,1) NULL DEFAULT NULL",
            'age' => "ALTER TABLE users ADD COLUMN age INT NULL DEFAULT NULL",
            'gender' => "ALTER TABLE users ADD COLUMN gender VARCHAR(16) NULL DEFAULT NULL",
            'activity_level' => "ALTER TABLE users ADD COLUMN activity_level VARCHAR(32) NULL DEFAULT NULL",
            'bmi' => "ALTER TABLE users ADD COLUMN bmi DECIMAL(4,1) NULL DEFAULT NULL",
            'bmr' => "ALTER TABLE users ADD COLUMN bmr INT NULL DEFAULT NULL",
            'tdee' => "ALTER TABLE users ADD COLUMN tdee INT NULL DEFAULT NULL",
            'calorie_target' => "ALTER TABLE users ADD COLUMN calorie_target INT NULL DEFAULT NULL",
            'macro_plan' => "ALTER TABLE users ADD COLUMN macro_plan VARCHAR(32) NULL DEFAULT NULL",
            'custom_protein_pct' => "ALTER TABLE users ADD COLUMN custom_protein_pct INT NULL DEFAULT NULL",
            'custom_carbs_pct' => "ALTER TABLE users ADD COLUMN custom_carbs_pct INT NULL DEFAULT NULL",
            'custom_fat_pct' => "ALTER TABLE users ADD COLUMN custom_fat_pct INT NULL DEFAULT NULL",
            'protein_target_g' => "ALTER TABLE users ADD COLUMN protein_target_g INT NULL DEFAULT NULL",
            'carbs_target_g' => "ALTER TABLE users ADD COLUMN carbs_target_g INT NULL DEFAULT NULL",
            'fat_target_g' => "ALTER TABLE users ADD COLUMN fat_target_g INT NULL DEFAULT NULL",
        ];

        foreach ($columnsToAdd as $col => $sql) {
            if (!in_array($col, $existingCols, true)) {
                $this->db->exec($sql);
            }
        }

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS user_follows (
                id INT AUTO_INCREMENT PRIMARY KEY,
                follower_id INT NOT NULL,
                followed_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_follow (follower_id, followed_id),
                KEY idx_follower (follower_id),
                KEY idx_followed (followed_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $recipeCols = $this->db->query("SHOW COLUMNS FROM recipes")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_gluten_free', $recipeCols, true)) {
            $this->db->exec("ALTER TABLE recipes ADD COLUMN is_gluten_free TINYINT(1) NOT NULL DEFAULT 0 AFTER is_oil_free");
        }
        if (!in_array('is_sugar_free', $recipeCols, true)) {
            $this->db->exec("ALTER TABLE recipes ADD COLUMN is_sugar_free TINYINT(1) NOT NULL DEFAULT 1 AFTER is_gluten_free");
        }
    }

    /**
     * Requires an active authenticated session and returns the user ID.
     */
    private function requireAuth(): int {
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

        return (int)$userId;
    }

    /**
     * GET /users/profile
     * Fetches a public profile by id/username/identifier, or the logged-in user's own profile.
     */
    public function getProfile() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $currentUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

        $identifier = $_GET['identifier'] ?? $_GET['id'] ?? $_GET['username'] ?? null;
        if ($identifier !== null) {
            $identifier = trim((string)$identifier);
        }

        if ($identifier === null || $identifier === '') {
            if ($currentUserId <= 0) {
                http_response_code(401);
                echo json_encode(["success" => false, "message" => "Please log in to view your profile."]);
                return;
            }
            $lookupSql = "SELECT id, username, email, display_name, location, bio, profile_image,
                                 favorite_cuisine, dietary_focus, allergies, website_url, pronouns,
                                 account_tier, generation_tokens, is_admin, created_at,
                                 height_ft, height_in, weight_lbs, age, gender, activity_level, bmi, bmr, tdee,
                                 calorie_target, macro_plan, custom_protein_pct, custom_carbs_pct, custom_fat_pct,
                                 protein_target_g, carbs_target_g, fat_target_g
                          FROM users WHERE id = :val LIMIT 1";
            $lookupVal = $currentUserId;
        } elseif (ctype_digit($identifier)) {
            $lookupSql = "SELECT id, username, email, display_name, location, bio, profile_image,
                                 favorite_cuisine, dietary_focus, allergies, website_url, pronouns,
                                 account_tier, generation_tokens, is_admin, created_at,
                                 height_ft, height_in, weight_lbs, age, gender, activity_level, bmi, bmr, tdee,
                                 calorie_target, macro_plan, custom_protein_pct, custom_carbs_pct, custom_fat_pct,
                                 protein_target_g, carbs_target_g, fat_target_g
                          FROM users WHERE id = :val LIMIT 1";
            $lookupVal = (int)$identifier;
        } else {
            $lookupSql = "SELECT id, username, email, display_name, location, bio, profile_image,
                                 favorite_cuisine, dietary_focus, allergies, website_url, pronouns,
                                 account_tier, generation_tokens, is_admin, created_at,
                                 height_ft, height_in, weight_lbs, age, gender, activity_level, bmi, bmr, tdee,
                                 calorie_target, macro_plan, custom_protein_pct, custom_carbs_pct, custom_fat_pct,
                                 protein_target_g, carbs_target_g, fat_target_g
                          FROM users WHERE username = :val LIMIT 1";
            $lookupVal = $identifier;
        }

        $stmt = $this->db->prepare($lookupSql);
        $stmt->execute([':val' => $lookupVal]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Chef profile not found in the Vault."]);
            return;
        }

        $targetUserId = (int)$user['id'];
        $isOwnProfile = $currentUserId > 0 && $currentUserId === $targetUserId;

        $followerStmt = $this->db->prepare("SELECT COUNT(*) FROM user_follows WHERE followed_id = ?");
        $followerStmt->execute([$targetUserId]);
        $followerCount = (int)$followerStmt->fetchColumn();

        $followingStmt = $this->db->prepare("SELECT COUNT(*) FROM user_follows WHERE follower_id = ?");
        $followingStmt->execute([$targetUserId]);
        $followingCount = (int)$followingStmt->fetchColumn();

        $isFollowing = false;
        if ($currentUserId > 0 && !$isOwnProfile) {
            $checkFollowStmt = $this->db->prepare("SELECT 1 FROM user_follows WHERE follower_id = ? AND followed_id = ? LIMIT 1");
            $checkFollowStmt->execute([$currentUserId, $targetUserId]);
            $isFollowing = (bool)$checkFollowStmt->fetchColumn();
        }

        $recipesSql = "SELECT r.id, r.user_id, r.title, r.description, r.image_url, r.yields,
                              r.prep_time_mins, r.cook_time_mins, r.is_wfpb, r.is_oil_free,
                              r.is_gluten_free, r.is_sugar_free,
                              r.is_public, r.image_source, r.created_at, r.average_rating, r.rating_count,
                              u.username AS author_name,
                              u.display_name AS author_display_name,
                              u.profile_image AS author_avatar,
                              CASE WHEN rf.recipe_id IS NOT NULL THEN 1 ELSE 0 END AS is_favorite
                       FROM recipes r
                       LEFT JOIN users u ON r.user_id = u.id
                       LEFT JOIN recipe_favorites rf ON rf.recipe_id = r.id AND rf.user_id = :fav_user_id
                       WHERE r.user_id = :target_user_id AND r.is_public = 1
                       ORDER BY r.created_at DESC";
        $recipesStmt = $this->db->prepare($recipesSql);
        $recipesStmt->execute([
            ':fav_user_id' => $currentUserId,
            ':target_user_id' => $targetUserId
        ]);
        $rawRecipes = $recipesStmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedRecipes = array_map(function($r) use ($isFollowing) {
            return [
                'id' => (int)$r['id'],
                'user_id' => $r['user_id'] ? (int)$r['user_id'] : null,
                'authorId' => $r['user_id'] ? (int)$r['user_id'] : null,
                'title' => $r['title'],
                'authorName' => $r['author_name'] ?? 'Emma (AI)',
                'authorDisplayName' => $r['author_display_name'] ?? null,
                'authorAvatar' => $r['author_avatar'] ?? null,
                'isFollowedAuthor' => $isFollowing,
                'description' => $r['description'],
                'prepTime' => (int)$r['prep_time_mins'],
                'cookTime' => (int)$r['cook_time_mins'],
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
        }, $rawRecipes);

        $profileData = [
            'id' => $targetUserId,
            'username' => $user['username'],
            'display_name' => $user['display_name'],
            'location' => $user['location'],
            'bio' => $user['bio'],
            'profile_image' => $user['profile_image'],
            'favorite_cuisine' => $user['favorite_cuisine'],
            'dietary_focus' => $user['dietary_focus'],
            'website_url' => $user['website_url'],
            'pronouns' => $user['pronouns'],
            'account_tier' => $user['account_tier'],
            'is_admin' => (int)$user['is_admin'],
            'created_at' => $user['created_at'],
            'follower_count' => $followerCount,
            'following_count' => $followingCount,
            'recipe_count' => count($formattedRecipes),
            'is_following' => $isFollowing,
            'is_own_profile' => $isOwnProfile,
            'achievements' => AchievementController::syncAndFetch($this->db, $targetUserId),
        ];

        if ($isOwnProfile) {
            $profileData['email'] = $user['email'];
            $profileData['generation_tokens'] = (float)$user['generation_tokens'];
            $profileData['allergies'] = $user['allergies'] ?? null;
            $profileData['height_ft'] = $user['height_ft'] !== null ? (int)$user['height_ft'] : null;
            $profileData['height_in'] = $user['height_in'] !== null ? (float)$user['height_in'] : null;
            $profileData['weight_lbs'] = $user['weight_lbs'] !== null ? (float)$user['weight_lbs'] : null;
            $profileData['age'] = $user['age'] !== null ? (int)$user['age'] : null;
            $profileData['gender'] = $user['gender'] ?? null;
            $profileData['activity_level'] = $user['activity_level'] ?? null;
            $profileData['bmi'] = $user['bmi'] !== null ? (float)$user['bmi'] : null;
            $profileData['bmr'] = $user['bmr'] !== null ? (int)$user['bmr'] : null;
            $profileData['tdee'] = $user['tdee'] !== null ? (int)$user['tdee'] : null;
            $profileData['calorie_target'] = $user['calorie_target'] !== null ? (int)$user['calorie_target'] : null;
            $profileData['macro_plan'] = $user['macro_plan'] ?? null;
            $profileData['custom_protein_pct'] = $user['custom_protein_pct'] !== null ? (int)$user['custom_protein_pct'] : null;
            $profileData['custom_carbs_pct'] = $user['custom_carbs_pct'] !== null ? (int)$user['custom_carbs_pct'] : null;
            $profileData['custom_fat_pct'] = $user['custom_fat_pct'] !== null ? (int)$user['custom_fat_pct'] : null;
            $profileData['protein_target_g'] = $user['protein_target_g'] !== null ? (int)$user['protein_target_g'] : null;
            $profileData['carbs_target_g'] = $user['carbs_target_g'] !== null ? (int)$user['carbs_target_g'] : null;
            $profileData['fat_target_g'] = $user['fat_target_g'] !== null ? (int)$user['fat_target_g'] : null;

            $followersListStmt = $this->db->prepare("
                SELECT u.id, u.username, u.display_name, u.profile_image, u.location,
                       (SELECT COUNT(*) FROM recipes r WHERE r.user_id = u.id AND r.is_public = 1) AS recipe_count
                FROM user_follows uf
                JOIN users u ON u.id = uf.follower_id
                WHERE uf.followed_id = ?
                ORDER BY uf.created_at DESC
            ");
            $followersListStmt->execute([$targetUserId]);
            $profileData['followers'] = $followersListStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        echo json_encode([
            "success" => true,
            "profile" => $profileData,
            "recipes" => $formattedRecipes
        ]);
    }

    /**
     * PUT /users/profile
     * Updates the authenticated user's public profile fields and optional avatar image.
     */
    public function updateProfile() {
        $userId = $this->requireAuth();
        $data = json_decode(file_get_contents("php://input"), true);
        if (!is_array($data)) {
            $data = [];
        }

        $username = trim((string)($data['username'] ?? ''));
        if (strlen($username) < 3 || strlen($username) > 50) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Username must be between 3 and 50 characters."]);
            return;
        }

        // Check username uniqueness against other users
        $checkStmt = $this->db->prepare("SELECT id FROM users WHERE username = :u AND id != :id LIMIT 1");
        $checkStmt->execute([':u' => $username, ':id' => $userId]);
        if ($checkStmt->fetch()) {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "That username is already taken by another chef."]);
            return;
        }

        $displayName = trim((string)($data['display_name'] ?? ''));
        $location = trim((string)($data['location'] ?? ''));
        $bio = trim((string)($data['bio'] ?? ''));
        $favoriteCuisine = trim((string)($data['favorite_cuisine'] ?? ''));
        $dietaryFocus = trim((string)($data['dietary_focus'] ?? ''));
        $allergies = trim((string)($data['allergies'] ?? ''));
        $websiteUrl = trim((string)($data['website_url'] ?? ''));
        $pronouns = trim((string)($data['pronouns'] ?? ''));

        (new ProfanityFilterService())->assertFieldsClean([
            'username' => $username,
            'display name' => $displayName,
            'location' => $location,
            'bio' => $bio,
            'favorite cuisine' => $favoriteCuisine,
            'dietary focus' => $dietaryFocus,
            'allergies' => $allergies,
            'pronouns' => $pronouns,
        ]);

        if ($websiteUrl !== '' && !preg_match('#^https?://#i', $websiteUrl)) {
            $websiteUrl = 'https://' . $websiteUrl;
        }
        if ($websiteUrl !== '' && !filter_var($websiteUrl, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Please enter a valid website URL (or leave it blank)."]);
            return;
        }

        $currStmt = $this->db->prepare("SELECT profile_image FROM users WHERE id = ? LIMIT 1");
        $currStmt->execute([$userId]);
        $currentImage = $currStmt->fetchColumn() ?: null;
        $newProfileImage = $currentImage;

        $base64Image = $data['profile_image_base64'] ?? '';
        $removeAvatar = !empty($data['remove_avatar']);

        if (!empty($base64Image) && strpos($base64Image, 'data:image') === 0) {
            $parts = explode(';', $base64Image, 2);
            $type = $parts[0] ?? '';
            $dataParts = explode(',', $parts[1] ?? '', 2);
            $base64Body = $dataParts[1] ?? '';

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
                echo json_encode(["success" => false, "message" => "Invalid profile image data provided."]);
                return;
            }

            (new ImageModerationService())->assertImageSafe($decodedData, $imgInfo['mime'], 'profile');
            $ext = $mimeToExt[$imgInfo['mime']];

            $uploadDir = __DIR__ . '/../../public/images/avatars/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
            if (file_put_contents($uploadDir . $filename, $decodedData) === false) {
                http_response_code(500);
                echo json_encode(["success" => false, "message" => "Failed to save profile image to server."]);
                return;
            }

            $newProfileImage = '/images/avatars/' . $filename;
        } elseif ($removeAvatar) {
            $newProfileImage = null;
        }

        $updateStmt = $this->db->prepare("
            UPDATE users
            SET username = :username,
                display_name = :display_name,
                location = :location,
                bio = :bio,
                favorite_cuisine = :favorite_cuisine,
                dietary_focus = :dietary_focus,
                allergies = :allergies,
                website_url = :website_url,
                pronouns = :pronouns,
                profile_image = :profile_image
            WHERE id = :id
        ");
        $updateStmt->execute([
            ':username' => $username,
            ':display_name' => $displayName !== '' ? substr($displayName, 0, 100) : null,
            ':location' => $location !== '' ? substr($location, 0, 120) : null,
            ':bio' => $bio !== '' ? $bio : null,
            ':favorite_cuisine' => $favoriteCuisine !== '' ? substr($favoriteCuisine, 0, 120) : null,
            ':dietary_focus' => $dietaryFocus !== '' ? substr($dietaryFocus, 0, 120) : null,
            ':allergies' => $allergies !== '' ? $allergies : null,
            ':website_url' => $websiteUrl !== '' ? substr($websiteUrl, 0, 255) : null,
            ':pronouns' => $pronouns !== '' ? substr($pronouns, 0, 50) : null,
            ':profile_image' => $newProfileImage,
            ':id' => $userId,
        ]);

        $_SESSION['username'] = $username;

        // Keep past comments consistent with the updated username
        $commentUpdateStmt = $this->db->prepare("UPDATE recipe_comments SET author_name = :u WHERE user_id = :id");
        $commentUpdateStmt->execute([':u' => $username, ':id' => $userId]);

        $fetchStmt = $this->db->prepare("
            SELECT id, username, email, display_name, location, bio, profile_image,
                   favorite_cuisine, dietary_focus, allergies, website_url, pronouns,
                   is_admin, account_tier, generation_tokens, created_at,
                   height_ft, height_in, weight_lbs, age, gender, activity_level, bmi, bmr, tdee,
                   calorie_target, macro_plan, custom_protein_pct, custom_carbs_pct, custom_fat_pct,
                   protein_target_g, carbs_target_g, fat_target_g
            FROM users WHERE id = ? LIMIT 1
        ");
        $fetchStmt->execute([$userId]);
        $updatedUser = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "message" => "Profile updated!",
            "user" => $updatedUser
        ]);
    }

    /**
     * PUT /users/wellness
     * Saves wellness calculations (BMI, TDEE, Calorie Target, Macros) to the user's profile.
     */
    public function updateWellness() {
        $userId = $this->requireAuth();
        $data = json_decode(file_get_contents("php://input"), true);
        if (!is_array($data)) {
            $data = [];
        }

        $fields = [
            'height_ft' => isset($data['height_ft']) && $data['height_ft'] !== '' ? (int)$data['height_ft'] : null,
            'height_in' => isset($data['height_in']) && $data['height_in'] !== '' ? (float)$data['height_in'] : null,
            'weight_lbs' => isset($data['weight_lbs']) && $data['weight_lbs'] !== '' ? (float)$data['weight_lbs'] : null,
            'age' => isset($data['age']) && $data['age'] !== '' ? (int)$data['age'] : null,
            'gender' => isset($data['gender']) && $data['gender'] !== '' ? substr(trim((string)$data['gender']), 0, 16) : null,
            'activity_level' => isset($data['activity_level']) && $data['activity_level'] !== '' ? substr(trim((string)$data['activity_level']), 0, 32) : null,
            'bmi' => isset($data['bmi']) && $data['bmi'] !== '' ? (float)$data['bmi'] : null,
            'bmr' => isset($data['bmr']) && $data['bmr'] !== '' ? (int)$data['bmr'] : null,
            'tdee' => isset($data['tdee']) && $data['tdee'] !== '' ? (int)$data['tdee'] : null,
            'calorie_target' => isset($data['calorie_target']) && $data['calorie_target'] !== '' ? (int)$data['calorie_target'] : null,
            'macro_plan' => isset($data['macro_plan']) && $data['macro_plan'] !== '' ? substr(trim((string)$data['macro_plan']), 0, 32) : null,
            'custom_protein_pct' => isset($data['custom_protein_pct']) && $data['custom_protein_pct'] !== '' ? (int)$data['custom_protein_pct'] : null,
            'custom_carbs_pct' => isset($data['custom_carbs_pct']) && $data['custom_carbs_pct'] !== '' ? (int)$data['custom_carbs_pct'] : null,
            'custom_fat_pct' => isset($data['custom_fat_pct']) && $data['custom_fat_pct'] !== '' ? (int)$data['custom_fat_pct'] : null,
            'protein_target_g' => isset($data['protein_target_g']) && $data['protein_target_g'] !== '' ? (int)$data['protein_target_g'] : null,
            'carbs_target_g' => isset($data['carbs_target_g']) && $data['carbs_target_g'] !== '' ? (int)$data['carbs_target_g'] : null,
            'fat_target_g' => isset($data['fat_target_g']) && $data['fat_target_g'] !== '' ? (int)$data['fat_target_g'] : null,
        ];

        $setClauses = [];
        $params = [':id' => $userId];
        foreach ($fields as $key => $val) {
            if (array_key_exists($key, $data)) {
                $setClauses[] = "{$key} = :{$key}";
                $params[":{$key}"] = $val;
            }
        }

        if (empty($setClauses)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "No wellness fields provided."]);
            return;
        }

        $sql = "UPDATE users SET " . implode(', ', $setClauses) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $fetchStmt = $this->db->prepare("
            SELECT id, username, email, display_name, location, bio, profile_image,
                   favorite_cuisine, dietary_focus, allergies, website_url, pronouns,
                   is_admin, account_tier, generation_tokens, created_at,
                   height_ft, height_in, weight_lbs, age, gender, activity_level, bmi, bmr, tdee,
                   calorie_target, macro_plan, custom_protein_pct, custom_carbs_pct, custom_fat_pct,
                   protein_target_g, carbs_target_g, fat_target_g
            FROM users WHERE id = ? LIMIT 1
        ");
        $fetchStmt->execute([$userId]);
        $updatedUser = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "message" => "Wellness calculations saved to your profile!",
            "user" => $updatedUser
        ]);
    }

    /**
     * PUT /users/email
     * Updates the authenticated user's email address after verifying their current password.
     */
    public function updateEmail() {
        $userId = $this->requireAuth();
        $data = json_decode(file_get_contents("php://input"), true) ?: [];

        $newEmail = trim((string)($data['email'] ?? ''));
        $currentPassword = (string)($data['currentPassword'] ?? '');

        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Please enter a valid email address."]);
            return;
        }

        if ($currentPassword === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Current password is required to change your email."]);
            return;
        }

        $stmt = $this->db->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($currentPassword, $hash)) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Current password is incorrect."]);
            return;
        }

        $dupStmt = $this->db->prepare("SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1");
        $dupStmt->execute([':email' => $newEmail, ':id' => $userId]);
        if ($dupStmt->fetch()) {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "That email address is already registered to another account."]);
            return;
        }

        $updateStmt = $this->db->prepare("UPDATE users SET email = :email WHERE id = :id");
        $updateStmt->execute([':email' => $newEmail, ':id' => $userId]);
        $_SESSION['email'] = $newEmail;

        echo json_encode([
            "success" => true,
            "message" => "Email address updated successfully!",
            "email" => $newEmail
        ]);
    }

    /**
     * PUT /users/password
     * Updates the authenticated user's password after verifying their current password.
     */
    public function updatePassword() {
        $userId = $this->requireAuth();
        $data = json_decode(file_get_contents("php://input"), true) ?: [];

        $currentPassword = (string)($data['currentPassword'] ?? '');
        $newPassword = (string)($data['newPassword'] ?? '');
        $confirmPassword = $data['confirmPassword'] ?? null;

        if ($currentPassword === '' || $newPassword === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Current and new passwords are required."]);
            return;
        }

        if (strlen($newPassword) < 8) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Your new password must be at least 8 characters long."]);
            return;
        }

        if ($confirmPassword !== null && $newPassword !== $confirmPassword) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "New passwords do not match."]);
            return;
        }

        $stmt = $this->db->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($currentPassword, $hash)) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Current password is incorrect."]);
            return;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $this->db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $updateStmt->execute([':hash' => $newHash, ':id' => $userId]);

        echo json_encode([
            "success" => true,
            "message" => "Password updated successfully!"
        ]);
    }

    /**
     * POST /users/follow
     * Follows or unfollows another user.
     */
    public function toggleFollow() {
        $followerId = $this->requireAuth();
        $data = json_decode(file_get_contents("php://input"), true) ?: [];

        $followedId = (int)($data['followed_id'] ?? 0);
        $targetUsername = trim((string)($data['username'] ?? ''));
        $action = $data['action'] ?? null;

        if ($followedId <= 0 && $targetUsername !== '') {
            $lookup = $this->db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $lookup->execute([$targetUsername]);
            $followedId = (int)$lookup->fetchColumn();
        }

        if ($followedId <= 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "A valid chef ID is required."]);
            return;
        }

        if ($followedId === $followerId) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "You cannot follow yourself, love."]);
            return;
        }

        $userCheck = $this->db->prepare("SELECT id, username FROM users WHERE id = ? LIMIT 1");
        $userCheck->execute([$followedId]);
        $targetUser = $userCheck->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Target user not found."]);
            return;
        }

        $existsStmt = $this->db->prepare("SELECT id FROM user_follows WHERE follower_id = ? AND followed_id = ? LIMIT 1");
        $existsStmt->execute([$followerId, $followedId]);
        $alreadyFollowing = (bool)$existsStmt->fetchColumn();

        if ($alreadyFollowing && $action !== 'follow') {
            $delStmt = $this->db->prepare("DELETE FROM user_follows WHERE follower_id = ? AND followed_id = ?");
            $delStmt->execute([$followerId, $followedId]);
            $isFollowing = false;
            $message = "Unfollowed @{$targetUser['username']}.";
        } elseif (!$alreadyFollowing && $action !== 'unfollow') {
            $insStmt = $this->db->prepare("INSERT IGNORE INTO user_follows (follower_id, followed_id) VALUES (?, ?)");
            $insStmt->execute([$followerId, $followedId]);
            $isFollowing = true;
            $message = "Now following @{$targetUser['username']}! Their recipes will appear first in your feed.";
        } else {
            $isFollowing = $alreadyFollowing;
            $message = $isFollowing ? "Already following @{$targetUser['username']}." : "Not following @{$targetUser['username']}.";
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM user_follows WHERE followed_id = ?");
        $countStmt->execute([$followedId]);
        $followerCount = (int)$countStmt->fetchColumn();

        echo json_encode([
            "success" => true,
            "isFollowing" => $isFollowing,
            "followerCount" => $followerCount,
            "message" => $message
        ]);
    }

    /**
     * GET /users/following
     * Lists all chefs currently followed by the authenticated user.
     */
    public function getFollowing() {
        $followerId = $this->requireAuth();

        $sql = "SELECT u.id, u.username, u.display_name, u.location, u.bio, u.profile_image,
                       u.favorite_cuisine, u.dietary_focus, uf.created_at AS followed_at,
                       (SELECT COUNT(*) FROM recipes r WHERE r.user_id = u.id AND r.is_public = 1) AS recipe_count,
                       (SELECT COUNT(*) FROM user_follows uf2 WHERE uf2.followed_id = u.id) AS follower_count
                FROM user_follows uf
                JOIN users u ON uf.followed_id = u.id
                WHERE uf.follower_id = :follower_id
                ORDER BY uf.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':follower_id' => $followerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $following = array_map(function($row) {
            return [
                'id' => (int)$row['id'],
                'username' => $row['username'],
                'display_name' => $row['display_name'],
                'location' => $row['location'],
                'bio' => $row['bio'],
                'profile_image' => $row['profile_image'],
                'favorite_cuisine' => $row['favorite_cuisine'],
                'dietary_focus' => $row['dietary_focus'],
                'followed_at' => $row['followed_at'],
                'recipe_count' => (int)$row['recipe_count'],
                'follower_count' => (int)$row['follower_count'],
            ];
        }, $rows);

        echo json_encode([
            "success" => true,
            "following" => $following
        ]);
    }
}
