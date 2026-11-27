<?php

namespace App\Controllers;

use App\Database;
use App\Services\ImageModerationService;
use App\Services\ProfanityFilterService;
use App\Services\RecipeVariantService;
use App\Services\StoryService;
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

        RecipeVariantService::ensureSchema($this->db);
        StoryService::ensureSchema($this->db);
        \App\Services\OgImageService::warmAllOgImages($this->db);

        $recipesSql = "SELECT r.id, r.user_id, r.parent_recipe_id, r.root_recipe_id, r.is_masterpiece, r.variant_summary,
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
                              (SELECT COUNT(*) FROM recipes rv WHERE (rv.parent_recipe_id = r.id OR rv.root_recipe_id = r.id) AND rv.id != r.id AND rv.is_public = 1) AS variant_count,
                              (SELECT COUNT(*) FROM recipe_stories rs WHERE rs.recipe_id = r.id AND rs.status = 'published') AS story_count
                       FROM recipes r
                       LEFT JOIN users u ON r.user_id = u.id
                       LEFT JOIN recipes pr ON r.parent_recipe_id = pr.id
                       LEFT JOIN users pu ON pr.user_id = pu.id
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
                'variant_count' => (int)($r['variant_count'] ?? 0),
                'variantCount' => (int)($r['variant_count'] ?? 0),
                'story_count' => (int)($r['story_count'] ?? 0),
                'storyCount' => (int)($r['story_count'] ?? 0),
                'is_favorite' => (bool)$r['is_favorite'],
                'isFavorited' => (bool)$r['is_favorite']
            ];
        }, $rawRecipes);

        StoryService::ensureSchema($this->db);
        $storiesWhere = $isOwnProfile
            ? "WHERE s.user_id = :target_uid"
            : "WHERE s.user_id = :target_uid AND s.status = 'published'";
        $storiesStmt = $this->db->prepare("
            SELECT s.id, s.recipe_id, s.user_id, s.title, s.slug, s.excerpt,
                   s.cover_image, s.read_time_minutes, s.status, s.created_at, s.updated_at,
                   u.username AS author_name,
                   u.display_name AS author_display_name,
                   u.profile_image AS author_avatar,
                   r.title AS recipe_title,
                   r.image_url AS recipe_image_url,
                   (SELECT COUNT(*) FROM story_likes sl WHERE sl.story_id = s.id) AS like_count
            FROM recipe_stories s
            LEFT JOIN users u ON u.id = s.user_id
            LEFT JOIN recipes r ON r.id = s.recipe_id
            $storiesWhere
            ORDER BY s.created_at DESC
        ");
        $storiesStmt->execute([':target_uid' => $targetUserId]);
        $rawStories = $storiesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $formattedStories = array_map(function($s) {
            $cover = !empty($s['cover_image']) ? $s['cover_image'] : null;
            $recipeImg = !empty($s['recipe_image_url']) ? $s['recipe_image_url'] : null;
            return [
                'id' => (int)$s['id'],
                'recipeId' => (int)$s['recipe_id'],
                'recipe_id' => (int)$s['recipe_id'],
                'userId' => (int)$s['user_id'],
                'user_id' => (int)$s['user_id'],
                'authorId' => (int)$s['user_id'],
                'authorName' => $s['author_name'] ?? 'Vault Chef',
                'authorDisplayName' => $s['author_display_name'] ?? null,
                'authorAvatar' => $s['author_avatar'] ?? null,
                'title' => $s['title'],
                'slug' => $s['slug'],
                'excerpt' => $s['excerpt'],
                'coverImage' => $cover,
                'cover_image' => $cover,
                'effectiveCoverImage' => $cover ?: $recipeImg,
                'readTimeMinutes' => max(1, (int)($s['read_time_minutes'] ?? 1)),
                'read_time_minutes' => max(1, (int)($s['read_time_minutes'] ?? 1)),
                'status' => $s['status'] ?? 'published',
                'createdAt' => $s['created_at'],
                'created_at' => $s['created_at'],
                'updatedAt' => $s['updated_at'] ?? $s['created_at'],
                'recipeTitle' => $s['recipe_title'] ?? null,
                'recipeImageUrl' => $recipeImg,
                'likeCount' => (int)($s['like_count'] ?? 0),
            ];
        }, $rawStories);

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
            'story_count' => count($formattedStories),
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
            "recipes" => $formattedRecipes,
            "stories" => $formattedStories
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

    /**
     * GET /users/profile-og-image?identifier=...(&mode=achievements&badge_key=...&level=...)
     * Dynamically composites the user's circular profile avatar and @username pill
     * (plus an Achievement Showcase card when sharing achievements) onto the standard
     * Veggie Vault OGP card (1200x630 JPEG).
     */
    public function getProfileOgImage() {
        $rawIdentifier = trim((string)($_GET['identifier'] ?? ''));
        $lookup = ltrim($rawIdentifier, '@');
        $mode = ($_GET['mode'] ?? 'profile') === 'achievements' ? 'achievements' : 'profile';
        $badgeKey = trim((string)($_GET['badge_key'] ?? ''));
        $badgeLevel = isset($_GET['level']) && ctype_digit((string)$_GET['level']) ? (int)$_GET['level'] : 0;

        $user = null;
        if ($lookup !== '') {
            if (ctype_digit($lookup)) {
                $stmt = $this->db->prepare("SELECT id, username, display_name, profile_image FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([(int)$lookup]);
            } else {
                $stmt = $this->db->prepare("SELECT id, username, display_name, profile_image FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
                $stmt->execute([$lookup]);
            }
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!extension_loaded('gd')) {
            if (!headers_sent()) {
                http_response_code(500);
                echo json_encode(["success" => false, "message" => "GD extension unavailable."]);
            }
            return;
        }

        $outW = 1200;
        $outH = 630;
        $canvas = $user ? $this->loadLatestPublishedRecipeOgCanvas((int)$user['id'], $outW, $outH) : null;

        if (!$canvas) {
            $basePath = $this->resolveBaseOgImagePath();
            if (!$basePath) {
                if (!headers_sent()) {
                    http_response_code(404);
                    echo json_encode(["success" => false, "message" => "Base OGP image unavailable."]);
                }
                return;
            }
            $canvas = $this->createResizedCoverCanvas($basePath, $outW, $outH);
        }

        if (!$canvas) {
            if (!headers_sent()) {
                http_response_code(500);
                echo json_encode(["success" => false, "message" => "Failed to render OGP image."]);
            }
            return;
        }

        if ($user) {
            $this->compositeProfileBadgeOnOgCanvas($canvas, $user);
        }

        if ($mode === 'achievements' || $badgeKey !== '') {
            $cardData = $this->resolveAchievementOgCardData($user ? (int)$user['id'] : 0, $badgeKey, $badgeLevel);
            $this->compositeAchievementCardOnOgCanvas($canvas, $cardData);
        }

        imageinterlace($canvas, false);
        ob_start();
        imagejpeg($canvas, null, 88);
        $jpegBytes = (string)ob_get_clean();

        if (!headers_sent()) {
            header_remove('Content-Type');
            header_remove('X-Frame-Options');
            header('Access-Control-Allow-Origin: *');
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . strlen($jpegBytes));
            header('Cache-Control: public, max-age=3600');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }
        echo $jpegBytes;
    }

    /**
     * Locates og-vault-preview.jpg across API and frontend public folders.
     */
    private function resolveBaseOgImagePath(): ?string {
        $candidates = [
            __DIR__ . '/../../public/og-vault-preview.jpg',
            dirname(__DIR__, 3) . '/project-green/public/og-vault-preview.jpg',
        ];
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/og-vault-preview.jpg';
        }
        foreach ($candidates as $path) {
            if (is_string($path) && is_file($path) && is_readable($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Loads the user's most recently published recipe image (is_public = 1)
     * and cover-scales it to the target OGP dimensions, or returns null when unavailable.
     */
    private function loadLatestPublishedRecipeOgCanvas(int $userId, int $targetW, int $targetH) {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT image_url
            FROM recipes
            WHERE user_id = ?
              AND is_public = 1
              AND image_url IS NOT NULL
              AND TRIM(image_url) != ''
            ORDER BY created_at DESC, id DESC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $imgUrl = trim((string)($row['image_url'] ?? ''));
            if ($imgUrl === '') {
                continue;
            }
            $src = $this->loadAvatarGdImage($imgUrl);
            if ($src) {
                $canvas = $this->createResizedCoverCanvasFromGd($src, $targetW, $targetH);
                if ($canvas) {
                    return $canvas;
                }
            }
        }

        return null;
    }

    /**
     * Loads an image file from disk and center-crops/scales it to exact dimensions.
     */
    private function createResizedCoverCanvas(string $path, int $targetW, int $targetH) {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return null;
        }

        return $this->createResizedCoverCanvasFromGd($src, $targetW, $targetH);
    }

    /**
     * Center-crops and cover-scales a GD image to exact target dimensions.
     */
    private function createResizedCoverCanvasFromGd($src, int $targetW, int $targetH) {
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW <= 0 || $srcH <= 0) {
            return null;
        }

        $scale = max($targetW / $srcW, $targetH / $srcH);
        $cropW = (int)round($targetW / $scale);
        $cropH = (int)round($targetH / $scale);
        $cropX = (int)max(0, floor(($srcW - $cropW) / 2));
        $cropY = (int)max(0, floor(($srcH - $cropH) / 2));

        $canvas = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $src, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);
        return $canvas;
    }

    /**
     * Draws a soft shadow, double ring, circular user avatar (or monogram), and @username pill
     * into the upper-left showcase region so the center medallion and bowls remain uncovered.
     */
    private function compositeProfileBadgeOnOgCanvas($canvas, array $user): void {
        $cx = 185;
        $cy = 180;
        $avatarDiameter = 216;
        $avatarRadius = (int)($avatarDiameter / 2); // 108
        $whiteRingRadius = $avatarRadius + 5;       // 113
        $greenRingRadius = $whiteRingRadius + 5;    // 118

        // Soft drop shadow layers
        for ($i = 5; $i >= 1; $i--) {
            $alpha = 127 - (int)round((6 - $i) * 8);
            $shadowColor = imagecolorallocatealpha($canvas, 8, 22, 16, max(75, min(124, $alpha)));
            $r = $greenRingRadius + ($i * 3);
            imagefilledellipse($canvas, $cx + 4, $cy + 8, $r * 2, $r * 2, $shadowColor);
        }

        // Outer sprout-green ring and inner crisp white ring
        $greenRingColor = imagecolorallocate($canvas, 134, 239, 172); // #86efac
        imagefilledellipse($canvas, $cx, $cy, $greenRingRadius * 2, $greenRingRadius * 2, $greenRingColor);

        $whiteColor = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledellipse($canvas, $cx, $cy, $whiteRingRadius * 2, $whiteRingRadius * 2, $whiteColor);

        // Load user's profile image or build a clean initial monogram badge
        $avatarImg = $this->loadAvatarGdImage($user['profile_image'] ?? null);
        if (!$avatarImg) {
            $labelSource = trim((string)($user['display_name'] ?: $user['username'] ?: 'V'));
            $initial = strtoupper(substr($labelSource, 0, 1));
            $avatarImg = $this->createInitialAvatarImage($initial !== '' ? $initial : 'V', 432);
        }

        if ($avatarImg) {
            $circular = $this->createCircularMaskedAvatar($avatarImg, $avatarDiameter);
            if ($circular) {
                imagecopy(
                    $canvas,
                    $circular,
                    $cx - $avatarRadius,
                    $cy - $avatarRadius,
                    0,
                    0,
                    $avatarDiameter,
                    $avatarDiameter
                );
            }
        }

        // Render @username pill underneath the circular avatar
        $username = trim((string)($user['username'] ?? ''));
        if ($username !== '') {
            $handleText = '@' . $username;
            if (strlen($handleText) > 22) {
                $handleText = substr($handleText, 0, 21) . '...';
            }
            $this->drawUsernamePill($canvas, $cx, $cy + $greenRingRadius + 24, $handleText);
        }
    }

    /**
     * Loads an avatar GD resource from local public paths, data URIs, or safe HTTP(S) URLs.
     */
    private function loadAvatarGdImage(?string $profileImage) {
        $raw = trim((string)$profileImage);
        if ($raw === '') {
            return null;
        }

        $bytes = null;

        // Data URI (base64 encoded raster image)
        if (preg_match('#^data:image/(?:png|jpe?g|webp|gif);base64,(.+)$#is', $raw, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false) {
                $bytes = $decoded;
            }
        } else {
            // Map relative paths or URLs pointing to our own public/images directory directly on disk
            $pathPart = $raw;
            if (preg_match('#^https?://#i', $raw)) {
                $parsedUrl = parse_url($raw);
                $pathPart = $parsedUrl['path'] ?? '';
            }

            if ($pathPart !== '' && str_starts_with($pathPart, '/')) {
                $normalized = preg_replace('#^/pantry-rebuild-api/public/#', '/', $pathPart);
                $apiPublicRoot = realpath(__DIR__ . '/../../public');
                $frontendPublicRoot = realpath(dirname(__DIR__, 3) . '/project-green/public');

                foreach ([$apiPublicRoot, $frontendPublicRoot] as $root) {
                    if (!$root) {
                        continue;
                    }
                    $candidate = realpath($root . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $normalized), DIRECTORY_SEPARATOR));
                    if ($candidate && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) && is_file($candidate)) {
                        $fileBytes = @file_get_contents($candidate);
                        if ($fileBytes !== false) {
                            $bytes = $fileBytes;
                            break;
                        }
                    }
                }
            }

            // Fallback: fetch external HTTPS avatar URL via cURL (non-private hosts only)
            if ($bytes === null && preg_match('#^https?://#i', $raw) && function_exists('curl_init')) {
                $host = parse_url($raw, PHP_URL_HOST);
                $resolvedIp = $host ? @gethostbyname($host) : '';
                if ($resolvedIp && filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $ch = curl_init($raw);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS => 2,
                        CURLOPT_TIMEOUT => 5,
                        CURLOPT_SSL_VERIFYPEER => true,
                    ]);
                    $fetched = curl_exec($ch);
                    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if (is_string($fetched) && $fetched !== '' && $status >= 200 && $status < 300 && strlen($fetched) <= 10 * 1024 * 1024) {
                        $bytes = $fetched;
                    }
                }
            }
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            return null;
        }

        // Normalize EXIF orientation when available (e.g. mobile camera uploads)
        if (function_exists('exif_read_data') && str_starts_with($bytes, "\xFF\xD8")) {
            $tmp = @fopen('php://memory', 'r+');
            if ($tmp) {
                fwrite($tmp, $bytes);
                rewind($tmp);
                $exif = @exif_read_data($tmp);
                fclose($tmp);
                $orientation = (int)($exif['Orientation'] ?? 1);
                if ($orientation === 3) {
                    $img = imagerotate($img, 180, 0) ?: $img;
                } elseif ($orientation === 6) {
                    $img = imagerotate($img, -90, 0) ?: $img;
                } elseif ($orientation === 8) {
                    $img = imagerotate($img, 90, 0) ?: $img;
                }
            }
        }

        return $img;
    }

    /**
     * Generates a forest-emerald monogram avatar when the user has not uploaded a photo.
     */
    private function createInitialAvatarImage(string $initial, int $size) {
        $img = imagecreatetruecolor($size, $size);
        $topR = 22; $topG = 101; $topB = 52;   // #166534
        $botR = 13; $botG = 59;  $botB = 40;   // #0d3b28

        for ($y = 0; $y < $size; $y++) {
            $t = $y / max(1, $size - 1);
            $r = (int)round($topR + ($botR - $topR) * $t);
            $g = (int)round($topG + ($botG - $topG) * $t);
            $b = (int)round($topB + ($botB - $topB) * $t);
            $lineColor = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $size, $y, $lineColor);
        }

        $textColor = imagecolorallocate($img, 240, 253, 244); // #f0fdf4
        $fontPath = $this->resolveBoldFontPath();
        if ($fontPath) {
            $fontSize = (int)round($size * 0.42);
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $initial);
            if ($bbox !== false) {
                $textW = $bbox[2] - $bbox[0];
                $textH = $bbox[1] - $bbox[7];
                $x = (int)round(($size - $textW) / 2 - $bbox[0]);
                $y = (int)round(($size - $textH) / 2 - $bbox[7]);
                imagettftext($img, $fontSize, 0, $x, $y, $textColor, $fontPath, $initial);
                return $img;
            }
        }

        $font = 5;
        $charW = imagefontwidth($font);
        $charH = imagefontheight($font);
        imagestring($img, $font, (int)round(($size - $charW) / 2), (int)round(($size - $charH) / 2), $initial, $textColor);
        return $img;
    }

    /**
     * Center-crops an image to a circle with smooth 2x supersampled edge antialiasing.
     */
    private function createCircularMaskedAvatar($srcImg, int $diameter) {
        $srcW = imagesx($srcImg);
        $srcH = imagesy($srcImg);
        if ($srcW <= 0 || $srcH <= 0) {
            return null;
        }

        $workSize = $diameter * 2;
        $square = imagecreatetruecolor($workSize, $workSize);
        imagealphablending($square, false);
        imagesavealpha($square, true);

        // White opaque base before copying transparent PNG/WebP avatars cleanly
        $bg = imagecolorallocatealpha($square, 255, 255, 255, 0);
        imagefilledrectangle($square, 0, 0, $workSize, $workSize, $bg);
        imagealphablending($square, true);

        $cropSide = min($srcW, $srcH);
        $cropX = (int)floor(($srcW - $cropSide) / 2);
        $cropY = (int)floor(($srcH - $cropSide) / 2);
        imagecopyresampled($square, $srcImg, 0, 0, $cropX, $cropY, $workSize, $workSize, $cropSide, $cropSide);

        // Apply circular alpha mask with subtle 2px radial antialiasing
        imagealphablending($square, false);
        $center = ($workSize - 1) / 2.0;
        $outerR = $workSize / 2.0;
        $innerR = $outerR - 2.0;
        $outerR2 = $outerR * $outerR;

        for ($y = 0; $y < $workSize; $y++) {
            $dy = $y - $center;
            $dy2 = $dy * $dy;
            for ($x = 0; $x < $workSize; $x++) {
                $dx = $x - $center;
                $dist2 = ($dx * $dx) + $dy2;
                if ($dist2 >= $outerR2) {
                    $rgba = imagecolorat($square, $x, $y);
                    $rgb = $rgba & 0x00FFFFFF;
                    imagesetpixel($square, $x, $y, (127 << 24) | $rgb);
                } elseif ($dist2 > ($innerR * $innerR)) {
                    $dist = sqrt($dist2);
                    $frac = min(1.0, max(0.0, ($dist - $innerR) / ($outerR - $innerR)));
                    $alpha = (int)round($frac * 127);
                    $rgba = imagecolorat($square, $x, $y);
                    $rgb = $rgba & 0x00FFFFFF;
                    imagesetpixel($square, $x, $y, ($alpha << 24) | $rgb);
                }
            }
        }

        $out = imagecreatetruecolor($diameter, $diameter);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $clear = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefilledrectangle($out, 0, 0, $diameter, $diameter, $clear);
        imagecopyresampled($out, $square, 0, 0, 0, 0, $diameter, $diameter, $workSize, $workSize);
        return $out;
    }

    /**
     * Draws a dark emerald capsule pill containing @username beneath the avatar.
     */
    private function drawUsernamePill($canvas, int $centerX, int $centerY, string $text): void {
        $fontPath = $this->resolveBoldFontPath();
        $padX = 20;
        $padY = 10;

        if ($fontPath) {
            $fontSize = 18;
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $text);
            $textW = $bbox !== false ? ($bbox[2] - $bbox[0]) : strlen($text) * 11;
            $textH = $bbox !== false ? ($bbox[1] - $bbox[7]) : 20;
        } else {
            $font = 5;
            $textW = imagefontwidth($font) * strlen($text);
            $textH = imagefontheight($font);
        }

        $pillW = max(140, (int)$textW + ($padX * 2));
        $pillH = max(38, (int)$textH + ($padY * 2));
        $halfW = (int)round($pillW / 2);
        $halfH = (int)round($pillH / 2);
        $radius = $halfH;
        $left = $centerX - $halfW;
        $right = $centerX + $halfW;
        $top = $centerY - $halfH;
        $bottom = $centerY + $halfH;

        // Subtle pill shadow
        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 75);
        $this->drawFilledCapsule($canvas, $left + 2, $top + 4, $right + 2, $bottom + 4, $radius, $shadow);

        // Outer border (#86efac) + inner dark forest fill
        $borderColor = imagecolorallocate($canvas, 134, 239, 172);
        $this->drawFilledCapsule($canvas, $left - 2, $top - 2, $right + 2, $bottom + 2, $radius + 2, $borderColor);

        $bgColor = imagecolorallocatealpha($canvas, 17, 56, 40, 12);
        $this->drawFilledCapsule($canvas, $left, $top, $right, $bottom, $radius, $bgColor);

        $textColor = imagecolorallocate($canvas, 255, 255, 255);
        if ($fontPath && isset($bbox) && $bbox !== false) {
            $tx = (int)round($centerX - ($textW / 2) - $bbox[0]);
            $ty = (int)round($centerY - ($textH / 2) - $bbox[7]);
            imagettftext($canvas, $fontSize, 0, $tx, $ty, $textColor, $fontPath, $text);
        } else {
            $tx = (int)round($centerX - ($textW / 2));
            $ty = (int)round($centerY - ($textH / 2));
            imagestring($canvas, 5, $tx, $ty, $text, $textColor);
        }
    }

    /**
     * Draws a stadium/capsule shape cleanly via a non-overlapping RGBA layer.
     */
    private function drawFilledCapsule($canvas, int $left, int $top, int $right, int $bottom, int $radius, int $color): void {
        $w = max(1, ($right - $left) + 1);
        $h = max(1, ($bottom - $top) + 1);
        $r = max(1, min($radius, (int)floor(min($w, $h) / 2)));
        $d = $r * 2;

        $rgba = imagecolorsforindex($canvas, $color);
        $tmp = imagecreatetruecolor($w, $h);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        $clear = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefilledrectangle($tmp, 0, 0, $w - 1, $h - 1, $clear);

        $localCol = imagecolorallocatealpha($tmp, $rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']);
        imagefilledellipse($tmp, $r, $r, $d, $d, $localCol);
        imagefilledellipse($tmp, $w - 1 - $r, $r, $d, $d, $localCol);
        if (($w - 1) - $d > 0) {
            imagefilledrectangle($tmp, $r, 0, $w - 1 - $r, $h - 1, $localCol);
        }

        imagecopy($canvas, $tmp, $left, $top, 0, 0, $w, $h);
    }

    /**
     * Resolves display metadata for an achievement OGP showcase card.
     */
    private function resolveAchievementOgCardData(int $userId, string $badgeKey, int $badgeLevel): array {
        $catalog = AchievementController::getCatalog();
        $earnedBadges = [];
        if ($userId > 0) {
            try {
                $fetched = AchievementController::syncAndFetch($this->db, $userId);
                $earnedBadges = is_array($fetched['earned'] ?? null) ? $fetched['earned'] : [];
            } catch (\Throwable $e) {
                $earnedBadges = [];
            }
        }

        if ($badgeKey !== '' && $badgeLevel > 0 && isset($catalog[$badgeKey]['levels'][$badgeLevel])) {
            $track = $catalog[$badgeKey];
            $levelInfo = $track['levels'][$badgeLevel];
            return [
                'isSpotlight' => true,
                'level' => $badgeLevel,
                'pill' => "LEVEL {$badgeLevel} ACHIEVEMENT",
                'title' => $levelInfo['name'],
                'subtitle' => $track['title'],
                'description' => $levelInfo['desc'],
            ];
        }

        $earnedCount = count($earnedBadges);
        $latestName = $earnedCount > 0 ? trim((string)($earnedBadges[0]['level_name'] ?? '')) : '';
        return [
            'isSpotlight' => false,
            'level' => 0,
            'pill' => 'CHEF ACHIEVEMENTS',
            'title' => $earnedCount > 0
                ? ($earnedCount . ' ' . ($earnedCount === 1 ? 'Badge Earned' : 'Badges Earned'))
                : 'Achievement Showcase',
            'subtitle' => $latestName !== '' ? "Latest: {$latestName}" : 'The Veggie Vault Milestones',
            'description' => 'Start earning achievements at The Veggie Vault!',
        ];
    }

    /**
     * Composites a compact dark-forest & gold/tier achievement showcase card in the
     * upper-right region (x: 785..1155, y: 48..258) clear of the center logo and produce bowls.
     */
    private function compositeAchievementCardOnOgCanvas($canvas, array $cardData): void {
        $left = 785;
        $top = 48;
        $right = 1155;
        $bottom = 258;
        $radius = 22;
        $level = (int)($cardData['level'] ?? 0);

        // Tier-coordinated accent RGB
        $accentRgb = match ($level) {
            1 => [252, 211, 77],   // gold-amber
            2 => [186, 230, 253],  // silver-sky
            3 => [253, 186, 116],  // warm bronze
            4 => [253, 224, 71],   // radiant gold
            default => [134, 239, 172], // sprout emerald (#86efac)
        };

        // Layered soft drop shadow
        for ($i = 4; $i >= 1; $i--) {
            $alpha = 122 - ((5 - $i) * 8);
            $shadowCol = imagecolorallocatealpha($canvas, 5, 18, 12, max(82, min(124, $alpha)));
            $pad = $i * 2;
            $this->drawFilledRoundedRect(
                $canvas,
                $left + 4 - $pad,
                $top + 8 - $pad,
                $right + 4 + $pad,
                $bottom + 8 + $pad,
                $radius + $pad,
                $shadowCol
            );
        }

        // Deep forest translucent body + clean 2px accent border ring (no double-blending)
        $cardBg = imagecolorallocatealpha($canvas, 13, 46, 32, 16);
        $this->drawFilledRoundedRect($canvas, $left, $top, $right, $bottom, $radius, $cardBg);

        $borderCol = imagecolorallocate($canvas, $accentRgb[0], $accentRgb[1], $accentRgb[2]);
        $this->drawStrokedRoundedRectBorder($canvas, $left - 2, $top - 2, $right + 2, $bottom + 2, $radius + 2, 2, $borderCol);

        // Upper header medallion + tier pill
        $trophyCx = $left + 44;
        $trophyCy = $top + 44;
        $this->drawTrophyMedallion($canvas, $trophyCx, $trophyCy, 24, $accentRgb);

        $boldFont = $this->resolveBoldFontPath();
        $regFont = $this->resolveRegularFontPath() ?: $boldFont;

        $pillText = strtoupper((string)($cardData['pill'] ?? 'ACHIEVEMENT'));
        $pillLeft = $trophyCx + 34;
        $pillTop = $trophyCy - 15;
        $pillBottom = $trophyCy + 15;
        $pillRight = min($right - 20, $pillLeft + 245);
        $pillFill = imagecolorallocatealpha($canvas, $accentRgb[0], $accentRgb[1], $accentRgb[2], 102);
        $this->drawFilledCapsule($canvas, $pillLeft, $pillTop, $pillRight, $pillBottom, 15, $pillFill);

        $pillTextCol = imagecolorallocate($canvas, $accentRgb[0], $accentRgb[1], $accentRgb[2]);
        if ($boldFont) {
            $pillSize = 10;
            $bbox = imagettfbbox($pillSize, 0, $boldFont, $pillText);
            $pw = $bbox ? ($bbox[2] - $bbox[0]) : 160;
            $ph = $bbox ? ($bbox[1] - $bbox[7]) : 12;
            $pillCenterX = (int)round(($pillLeft + $pillRight) / 2);
            $tx = (int)round($pillCenterX - ($pw / 2) - ($bbox[0] ?? 0));
            $ty = (int)round($trophyCy - ($ph / 2) - ($bbox[7] ?? 0));
            imagettftext($canvas, $pillSize, 0, $tx, $ty, $pillTextCol, $boldFont, $pillText);
        } else {
            imagestring($canvas, 3, $pillLeft + 12, $trophyCy - 7, $pillText, $pillTextCol);
        }

        $textLeft = $left + 22;
        $maxTextW = ($right - 22) - $textLeft;
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $mutedGreen = imagecolorallocate($canvas, 209, 250, 229);
        $accentCol = imagecolorallocate($canvas, $accentRgb[0], $accentRgb[1], $accentRgb[2]);

        $title = trim((string)($cardData['title'] ?? 'Vault Achievement'));
        $subtitle = trim((string)($cardData['subtitle'] ?? 'The Veggie Vault'));
        $desc = trim((string)($cardData['description'] ?? 'Start earning achievements at The Veggie Vault!'));

        if ($boldFont) {
            $titleSize = $this->measureFittedTtfFontSize($boldFont, $title, $maxTextW, 21, 14);
            imagettftext($canvas, $titleSize, 0, $textLeft, $top + 106, $white, $boldFont, $title);

            $subSize = $this->measureFittedTtfFontSize($boldFont, $subtitle, $maxTextW, 13, 10);
            imagettftext($canvas, $subSize, 0, $textLeft, $top + 134, $accentCol, $boldFont, $subtitle);

            $descSize = 12;
            $lines = $this->wrapTtfTextLines($regFont ?: $boldFont, $descSize, $desc, $maxTextW, 2);
            $lineY = $top + 164;
            foreach ($lines as $line) {
                imagettftext($canvas, $descSize, 0, $textLeft, $lineY, $mutedGreen, $regFont ?: $boldFont, $line);
                $lineY += 20;
            }
        } else {
            imagestring($canvas, 5, $textLeft, $top + 88, substr($title, 0, 32), $white);
            imagestring($canvas, 4, $textLeft, $top + 118, substr($subtitle, 0, 36), $accentCol);
            imagestring($canvas, 3, $textLeft, $top + 148, substr($desc, 0, 44), $mutedGreen);
        }
    }

    /**
     * Draws a crisp circular trophy medallion badge on the achievement card.
     */
    private function drawTrophyMedallion($canvas, int $cx, int $cy, int $radius, array $accentRgb): void {
        $ringCol = imagecolorallocate($canvas, $accentRgb[0], $accentRgb[1], $accentRgb[2]);
        $innerBg = imagecolorallocate($canvas, 22, 101, 52);
        $gold = imagecolorallocate($canvas, 250, 204, 21);

        imagefilledellipse($canvas, $cx, $cy, $radius * 2, $radius * 2, $ringCol);
        imagefilledellipse($canvas, $cx, $cy, ($radius - 3) * 2, ($radius - 3) * 2, $innerBg);

        // Simple trophy silhouette centered inside medallion
        imagefilledrectangle($canvas, $cx - 8, $cy - 9, $cx + 8, $cy - 1, $gold);
        imagefilledellipse($canvas, $cx, $cy - 1, 16, 10, $gold);
        imagefilledrectangle($canvas, $cx - 2, $cy + 3, $cx + 2, $cy + 8, $gold);
        imagefilledrectangle($canvas, $cx - 7, $cy + 8, $cx + 7, $cy + 11, $gold);
    }

    /**
     * Draws a filled rounded rectangle via an offscreen RGBA layer so corners never self-blend.
     */
    private function drawFilledRoundedRect($canvas, int $left, int $top, int $right, int $bottom, int $radius, int $color): void {
        $this->renderRoundedRectToCanvas($canvas, $left, $top, $right, $bottom, $radius, $color, 0);
    }

    /**
     * Draws a crisp rounded-rectangle border ring without filling its interior.
     */
    private function drawStrokedRoundedRectBorder($canvas, int $left, int $top, int $right, int $bottom, int $radius, int $stroke, int $color): void {
        $this->renderRoundedRectToCanvas($canvas, $left, $top, $right, $bottom, $radius, $color, max(1, $stroke));
    }

    /**
     * Renders a filled or stroked rounded rectangle onto an isolated RGBA layer and copies it once.
     */
    private function renderRoundedRectToCanvas($canvas, int $left, int $top, int $right, int $bottom, int $radius, int $color, int $stroke): void {
        $w = max(1, ($right - $left) + 1);
        $h = max(1, ($bottom - $top) + 1);
        $r = max(1, min($radius, (int)floor(min($w, $h) / 2)));

        $rgba = imagecolorsforindex($canvas, $color);
        $tmp = imagecreatetruecolor($w, $h);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        $clear = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefilledrectangle($tmp, 0, 0, $w - 1, $h - 1, $clear);

        $localCol = imagecolorallocatealpha($tmp, $rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']);
        $this->paintRoundedRectOnLayer($tmp, 0, 0, $w - 1, $h - 1, $r, $localCol);

        if ($stroke > 0) {
            $ix2 = ($w - 1) - $stroke;
            $iy2 = ($h - 1) - $stroke;
            if ($ix2 > $stroke && $iy2 > $stroke) {
                $this->paintRoundedRectOnLayer($tmp, $stroke, $stroke, $ix2, $iy2, max(1, $r - $stroke), $clear);
            }
        }

        imagecopy($canvas, $tmp, $left, $top, 0, 0, $w, $h);
    }

    /**
     * Paints a solid rounded rectangle within bounds on an alpha-disabled GD layer.
     */
    private function paintRoundedRectOnLayer($layer, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void {
        $d = $radius * 2;
        imagefilledellipse($layer, $x1 + $radius, $y1 + $radius, $d, $d, $color);
        imagefilledellipse($layer, $x2 - $radius, $y1 + $radius, $d, $d, $color);
        imagefilledellipse($layer, $x1 + $radius, $y2 - $radius, $d, $d, $color);
        imagefilledellipse($layer, $x2 - $radius, $y2 - $radius, $d, $d, $color);
        imagefilledrectangle($layer, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($layer, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
    }

    /**
     * Scales down a TrueType font size until the string fits inside maxWidth.
     */
    private function measureFittedTtfFontSize(string $fontPath, string $text, int $maxWidth, int $maxSize, int $minSize): int {
        for ($size = $maxSize; $size >= $minSize; $size--) {
            $bbox = imagettfbbox($size, 0, $fontPath, $text);
            if ($bbox !== false && ($bbox[2] - $bbox[0]) <= $maxWidth) {
                return $size;
            }
        }
        return $minSize;
    }

    /**
     * Word-wraps text into at most maxLines lines that each fit within maxWidth pixels.
     */
    private function wrapTtfTextLines(string $fontPath, int $fontSize, string $text, int $maxWidth, int $maxLines): array {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : ($current . ' ' . $word);
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $candidate);
            $w = $bbox !== false ? ($bbox[2] - $bbox[0]) : strlen($candidate) * 8;
            if ($w <= $maxWidth || $current === '') {
                $current = $candidate;
            } else {
                $lines[] = $current;
                if (count($lines) >= $maxLines) {
                    $lastIdx = $maxLines - 1;
                    $lines[$lastIdx] = rtrim($lines[$lastIdx], " .,!?") . '...';
                    return $lines;
                }
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return array_slice($lines, 0, $maxLines);
    }

    /**
     * Finds a readable bold TrueType font across Linux and Windows hosts.
     */
    private function resolveBoldFontPath(): ?string {
        $candidates = [
            'C:/Windows/Fonts/segoeuib.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
            'C:/Windows/Fonts/calibrib.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
        ];
        foreach ($candidates as $fontPath) {
            if (is_file($fontPath) && is_readable($fontPath)) {
                return $fontPath;
            }
        }
        return null;
    }

    /**
     * Finds a readable regular-weight TrueType font across Linux and Windows hosts.
     */
    private function resolveRegularFontPath(): ?string {
        $candidates = [
            'C:/Windows/Fonts/segoeui.ttf',
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/calibri.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
        ];
        foreach ($candidates as $fontPath) {
            if (is_file($fontPath) && is_readable($fontPath)) {
                return $fontPath;
            }
        }
        return null;
    }
}
