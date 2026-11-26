<?php

namespace App\Controllers;

use App\Database;
use App\Services\OgImageService;
use App\Services\ProfanityFilterService;
use App\Services\StoryService;
use PDO;

class StoryController {
    private PDO $db;
    private StoryService $storyService;

    public function __construct() {
        $this->db = Database::connect();
        StoryService::ensureSchema($this->db);
        $this->storyService = new StoryService($this->db);
    }

    /**
     * Returns the current logged-in user ID (or 0 if guest).
     */
    private function getOptionalUserId(): int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    }

    /**
     * Requires an active authenticated session.
     *
     * @return array{id: int, username: string, is_admin: bool}
     */
    private function requireAuth(): array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. Please log into the Vault first.',
            ]);
            exit;
        }

        return [
            'id' => $userId,
            'username' => (string)($_SESSION['username'] ?? 'Vault Member'),
            'is_admin' => !empty($_SESSION['is_admin']),
        ];
    }

    /**
     * Formats a raw DB row from `recipe_stories` into a consistent API payload.
     */
    private function formatStoryRow(array $row, bool $includeBody = false): array {
        $coverImage = !empty($row['cover_image']) ? (string)$row['cover_image'] : null;
        $recipeImage = !empty($row['recipe_image_url']) ? (string)$row['recipe_image_url'] : null;
        $effectiveCover = $coverImage ?: $recipeImage;

        $payload = [
            'id' => (int)$row['id'],
            'recipe_id' => (int)$row['recipe_id'],
            'recipeId' => (int)$row['recipe_id'],
            'user_id' => (int)$row['user_id'],
            'userId' => (int)$row['user_id'],
            'authorId' => (int)$row['user_id'],
            'authorName' => $row['author_name'] ?? 'Vault Chef',
            'authorDisplayName' => $row['author_display_name'] ?? null,
            'authorAvatar' => $row['author_avatar'] ?? null,
            'authorBio' => $row['author_bio'] ?? null,
            'title' => (string)$row['title'],
            'slug' => (string)$row['slug'],
            'excerpt' => (string)$row['excerpt'],
            'cover_image' => $coverImage,
            'coverImage' => $coverImage,
            'effectiveCoverImage' => $effectiveCover,
            'read_time_minutes' => max(1, (int)($row['read_time_minutes'] ?? 1)),
            'readTimeMinutes' => max(1, (int)($row['read_time_minutes'] ?? 1)),
            'status' => (string)($row['status'] ?? 'published'),
            'created_at' => $row['created_at'],
            'createdAt' => $row['created_at'],
            'updated_at' => $row['updated_at'] ?? $row['created_at'],
            'updatedAt' => $row['updated_at'] ?? $row['created_at'],
            'recipeTitle' => $row['recipe_title'] ?? null,
            'recipeImageUrl' => $recipeImage,
            'recipeAuthorId' => !empty($row['recipe_author_id']) ? (int)$row['recipe_author_id'] : null,
            'recipeAuthorName' => $row['recipe_author_name'] ?? 'Emma (AI)',
            'likeCount' => (int)($row['like_count'] ?? 0),
            'isLiked' => !empty($row['is_liked']),
        ];

        if ($includeBody) {
            $payload['content_html'] = (string)($row['content_html'] ?? '');
            $payload['contentHtml'] = (string)($row['content_html'] ?? '');
        }

        return $payload;
    }

    /**
     * GET /stories
     * Paginated list of stories filtered by `recipe_id` or `user_id` (or all published community stories).
     */
    public function getStories(): void {
        $currentUserId = $this->getOptionalUserId();
        $recipeId = isset($_GET['recipe_id']) ? (int)$_GET['recipe_id'] : 0;
        $targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(24, max(1, (int)($_GET['per_page'] ?? 6)));
        $offset = ($page - 1) * $perPage;
        $includeDrafts = !empty($_GET['include_drafts']);

        $where = [];
        $params = [];

        if ($recipeId > 0) {
            $where[] = 's.recipe_id = :recipe_id';
            $params[':recipe_id'] = $recipeId;
        }

        if ($targetUserId > 0) {
            $where[] = 's.user_id = :target_user_id';
            $params[':target_user_id'] = $targetUserId;
        }

        if ($includeDrafts && $currentUserId > 0 && $targetUserId === $currentUserId) {
            // Author viewing their own profile stories (both published & drafts)
            $where[] = "(s.status = 'published' OR s.user_id = :own_uid)";
            $params[':own_uid'] = $currentUserId;
        } else {
            $where[] = "s.status = 'published'";
        }

        $whereClause = count($where) > 0 ? ('WHERE ' . implode(' AND ', $where)) : "WHERE s.status = 'published'";

        try {
            $countSql = "SELECT COUNT(*) FROM recipe_stories s $whereClause";
            $countStmt = $this->db->prepare($countSql);
            foreach ($params as $k => $v) {
                $countStmt->bindValue($k, $v, PDO::PARAM_INT);
            }
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($total / $perPage));

            $sql = "SELECT s.id, s.recipe_id, s.user_id, s.title, s.slug, s.excerpt,
                           s.cover_image, s.read_time_minutes, s.status, s.created_at, s.updated_at,
                           u.username AS author_name,
                           u.display_name AS author_display_name,
                           u.profile_image AS author_avatar,
                           r.title AS recipe_title,
                           r.image_url AS recipe_image_url,
                           r.user_id AS recipe_author_id,
                           ru.username AS recipe_author_name,
                           (SELECT COUNT(*) FROM story_likes sl WHERE sl.story_id = s.id) AS like_count,
                           CASE WHEN usl.id IS NOT NULL THEN 1 ELSE 0 END AS is_liked
                    FROM recipe_stories s
                    LEFT JOIN users u ON u.id = s.user_id
                    LEFT JOIN recipes r ON r.id = s.recipe_id
                    LEFT JOIN users ru ON ru.id = r.user_id
                    LEFT JOIN story_likes usl ON usl.story_id = s.id AND usl.user_id = :like_uid
                    $whereClause
                    ORDER BY s.created_at DESC, s.id DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':like_uid', $currentUserId, PDO::PARAM_INT);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v, PDO::PARAM_INT);
            }
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $stories = array_map(fn($row) => $this->formatStoryRow($row, false), $rows);

            echo json_encode([
                'success' => true,
                'stories' => $stories,
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'totalPages' => $totalPages,
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load stories: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /stories/single
     * Fetches a single story by `?slug=...` or `?id=...`, including the linked recipe reference card.
     */
    public function getStoryBySlug(): void {
        $currentUserId = $this->getOptionalUserId();
        $isAdmin = !empty($_SESSION['is_admin']);
        $slug = trim((string)($_GET['slug'] ?? ''));
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($slug === '' && $id <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'A story slug or ID is required.',
            ]);
            return;
        }

        try {
            $whereCol = $slug !== '' ? 's.slug = :lookup' : 's.id = :lookup';
            $lookupVal = $slug !== '' ? $slug : $id;

            $sql = "SELECT s.*,
                           u.username AS author_name,
                           u.display_name AS author_display_name,
                           u.profile_image AS author_avatar,
                           u.bio AS author_bio,
                           r.title AS recipe_title,
                           r.description AS recipe_description,
                           r.image_url AS recipe_image_url,
                           r.image_source AS recipe_image_source,
                           r.yields AS recipe_yields,
                           r.prep_time_mins AS recipe_prep_time,
                           r.cook_time_mins AS recipe_cook_time,
                           r.is_oil_free AS recipe_is_oil_free,
                           r.is_gluten_free AS recipe_is_gluten_free,
                           r.is_sugar_free AS recipe_is_sugar_free,
                           r.is_masterpiece AS recipe_is_masterpiece,
                           r.parent_recipe_id AS recipe_parent_id,
                           r.variant_summary AS recipe_variant_summary,
                           r.average_rating AS recipe_average_rating,
                           r.rating_count AS recipe_rating_count,
                           r.user_id AS recipe_author_id,
                           ru.username AS recipe_author_name,
                           pr.title AS parent_recipe_title,
                           m.calories AS recipe_calories,
                           m.protein_g AS recipe_protein_g,
                           m.carbs_g AS recipe_carbs_g,
                           m.fat_g AS recipe_fat_g,
                           m.fiber_g AS recipe_fiber_g,
                           (SELECT COUNT(*) FROM story_likes sl WHERE sl.story_id = s.id) AS like_count,
                           CASE WHEN usl.id IS NOT NULL THEN 1 ELSE 0 END AS is_liked
                    FROM recipe_stories s
                    LEFT JOIN users u ON u.id = s.user_id
                    LEFT JOIN recipes r ON r.id = s.recipe_id
                    LEFT JOIN users ru ON ru.id = r.user_id
                    LEFT JOIN recipes pr ON pr.id = r.parent_recipe_id
                    LEFT JOIN macros m ON m.recipe_id = r.id
                    LEFT JOIN story_likes usl ON usl.story_id = s.id AND usl.user_id = :like_uid
                    WHERE $whereCol
                    LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':like_uid', $currentUserId, PDO::PARAM_INT);
            if ($slug !== '') {
                $stmt->bindValue(':lookup', $lookupVal, PDO::PARAM_STR);
            } else {
                $stmt->bindValue(':lookup', (int)$lookupVal, PDO::PARAM_INT);
            }
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Story not found in the Vault.',
                ]);
                return;
            }

            $isOwner = $currentUserId > 0 && (int)$row['user_id'] === $currentUserId;
            if (($row['status'] ?? 'published') !== 'published' && !$isOwner && !$isAdmin) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'This story is currently saved as a private draft.',
                ]);
                return;
            }

            $story = $this->formatStoryRow($row, true);
            $story['recipe'] = [
                'id' => (int)$row['recipe_id'],
                'title' => $row['recipe_title'] ?? 'Linked Recipe',
                'description' => $row['recipe_description'] ?? '',
                'imageUrl' => $row['recipe_image_url'] ?? null,
                'image_url' => $row['recipe_image_url'] ?? null,
                'imageSource' => $row['recipe_image_source'] ?? 'none',
                'yields' => $row['recipe_yields'] ?? null,
                'prepTime' => (int)($row['recipe_prep_time'] ?? 0),
                'cookTime' => (int)($row['recipe_cook_time'] ?? 0),
                'isOilFree' => (bool)($row['recipe_is_oil_free'] ?? 1),
                'isGlutenFree' => (bool)($row['recipe_is_gluten_free'] ?? 0),
                'isSugarFree' => (bool)($row['recipe_is_sugar_free'] ?? 1),
                'isMasterpiece' => (bool)($row['recipe_is_masterpiece'] ?? 0),
                'parentRecipeId' => !empty($row['recipe_parent_id']) ? (int)$row['recipe_parent_id'] : null,
                'parentRecipeTitle' => $row['parent_recipe_title'] ?? null,
                'variantSummary' => $row['recipe_variant_summary'] ?? null,
                'authorId' => !empty($row['recipe_author_id']) ? (int)$row['recipe_author_id'] : null,
                'authorName' => $row['recipe_author_name'] ?? 'Emma (AI)',
                'averageRating' => (float)($row['recipe_average_rating'] ?? 0),
                'ratingCount' => (int)($row['recipe_rating_count'] ?? 0),
                'calories' => $row['recipe_calories'] !== null ? (float)$row['recipe_calories'] : null,
                'protein_g' => $row['recipe_protein_g'] !== null ? (float)$row['recipe_protein_g'] : null,
                'carbs_g' => $row['recipe_carbs_g'] !== null ? (float)$row['recipe_carbs_g'] : null,
                'fat_g' => $row['recipe_fat_g'] !== null ? (float)$row['recipe_fat_g'] : null,
                'fiber_g' => $row['recipe_fiber_g'] !== null ? (float)$row['recipe_fiber_g'] : null,
            ];

            // Fetch up to 3 other published stories about the same recipe
            $moreStmt = $this->db->prepare("
                SELECT s.id, s.recipe_id, s.user_id, s.title, s.slug, s.excerpt,
                       s.cover_image, s.read_time_minutes, s.status, s.created_at, s.updated_at,
                       u.username AS author_name,
                       u.display_name AS author_display_name,
                       u.profile_image AS author_avatar,
                       r.title AS recipe_title,
                       r.image_url AS recipe_image_url,
                       r.user_id AS recipe_author_id,
                       ru.username AS recipe_author_name,
                       (SELECT COUNT(*) FROM story_likes sl WHERE sl.story_id = s.id) AS like_count,
                       0 AS is_liked
                FROM recipe_stories s
                LEFT JOIN users u ON u.id = s.user_id
                LEFT JOIN recipes r ON r.id = s.recipe_id
                LEFT JOIN users ru ON ru.id = r.user_id
                WHERE s.recipe_id = :rid AND s.id <> :sid AND s.status = 'published'
                ORDER BY s.created_at DESC
                LIMIT 3
            ");
            $moreStmt->execute([
                ':rid' => (int)$row['recipe_id'],
                ':sid' => (int)$row['id'],
            ]);
            $moreRows = $moreStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $story['relatedStories'] = array_map(fn($r) => $this->formatStoryRow($r, false), $moreRows);

            echo json_encode([
                'success' => true,
                'story' => $story,
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load story: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /stories/upload-image
     * Uploads a cover image or inline body photo for a Story (MIME check + EXIF strip + Gemini Vision moderation).
     */
    public function uploadStoryImage(): void {
        try {
            $currentUser = $this->requireAuth();
            $userId = (int)$currentUser['id'];

            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $rawImage = trim((string)($data['image'] ?? ''));
            $role = ($data['role'] ?? 'inline') === 'cover' ? 'cover' : 'inline';

            $res = $this->storyService->processAndSaveStoryImage($rawImage, $userId, $role);
            if (!$res['success']) {
                http_response_code((int)($res['status'] ?? 400));
                echo json_encode([
                    'success' => false,
                    'message' => $res['message'] ?? 'Could not upload story image.',
                ]);
                return;
            }

            echo json_encode([
                'success' => true,
                'imageUrl' => $res['imageUrl'],
            ]);
        } catch (\Throwable $e) {
            error_log('StoryController::uploadStoryImage error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Could not process image upload: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /stories
     * Creates and publishes (or saves as draft) a new Recipe Story.
     */
    public function createStory(): void {
        $currentUser = $this->requireAuth();
        $userId = (int)$currentUser['id'];
        $authorUsername = (string)$currentUser['username'];

        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $recipeId = (int)($data['recipe_id'] ?? $data['recipeId'] ?? 0);
        $title = trim((string)preg_replace('/\s+/u', ' ', (string)($data['title'] ?? '')));
        $customExcerpt = trim((string)($data['excerpt'] ?? ''));
        $rawContentHtml = (string)($data['content_html'] ?? $data['contentHtml'] ?? '');
        $rawCoverImage = trim((string)($data['cover_image'] ?? $data['coverImage'] ?? ''));
        $status = ($data['status'] ?? 'published') === 'draft' ? 'draft' : 'published';

        if ($recipeId <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'A valid linked recipe is required to publish a story.',
            ]);
            return;
        }

        if ($title === '' || mb_strlen($title, 'UTF-8') < 4) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Please give your story a headline (at least 4 characters).',
            ]);
            return;
        }

        if (mb_strlen($title, 'UTF-8') > 200) {
            $title = mb_substr($title, 0, 200, 'UTF-8');
        }

        // Verify linked recipe exists
        $recipeStmt = $this->db->prepare("SELECT id, user_id, title, image_url, is_public FROM recipes WHERE id = ? LIMIT 1");
        $recipeStmt->execute([$recipeId]);
        $recipe = $recipeStmt->fetch(PDO::FETCH_ASSOC);

        if (!$recipe) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'The linked recipe could not be found in the Vault.',
            ]);
            return;
        }

        // Sanitize HTML body against stored XSS
        $sanitizedHtml = $this->storyService->sanitizeContentHtml($rawContentHtml);
        $plainText = $this->storyService->extractPlainText($sanitizedHtml);

        if (mb_strlen($plainText, 'UTF-8') < 20) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Your story body is a bit too short—please share at least a sentence or two about your cooking experience!',
            ]);
            return;
        }

        // Run profanity filter on headline, custom excerpt, and plain-text story content
        $profanity = new ProfanityFilterService();
        $profanity->assertClean($title, 'story title');
        if ($customExcerpt !== '') {
            $profanity->assertClean($customExcerpt, 'story excerpt');
        }
        $profanity->assertClean($plainText, 'story content');

        // Handle optional cover image upload or pre-uploaded path
        $coverImageUrl = null;
        if ($rawCoverImage !== '') {
            $imgRes = $this->storyService->processAndSaveStoryImage($rawCoverImage, $userId, 'cover');
            if (!$imgRes['success']) {
                http_response_code((int)($imgRes['status'] ?? 400));
                echo json_encode([
                    'success' => false,
                    'message' => $imgRes['message'] ?? 'Invalid story cover image.',
                ]);
                return;
            }
            $coverImageUrl = $imgRes['imageUrl'];
        }

        $excerpt = $this->storyService->buildExcerpt($sanitizedHtml, $customExcerpt);
        $readTimeMinutes = $this->storyService->calculateReadTimeMinutes($sanitizedHtml);
        $slug = $this->storyService->generateUniqueSlug($title, (string)($recipe['title'] ?? ''));

        try {
            $insertStmt = $this->db->prepare("
                INSERT INTO recipe_stories
                    (recipe_id, user_id, title, slug, excerpt, cover_image, content_html, read_time_minutes, status)
                VALUES
                    (:recipe_id, :user_id, :title, :slug, :excerpt, :cover_image, :content_html, :read_time_minutes, :status)
            ");
            $insertStmt->execute([
                ':recipe_id' => $recipeId,
                ':user_id' => $userId,
                ':title' => $title,
                ':slug' => $slug,
                ':excerpt' => $excerpt,
                ':cover_image' => $coverImageUrl,
                ':content_html' => $sanitizedHtml,
                ':read_time_minutes' => $readTimeMinutes,
                ':status' => $status,
            ]);

            $storyId = (int)$this->db->lastInsertId();

            // Notify the original recipe creator when a story is published about their recipe (skip self-notifications)
            $recipeOwnerId = !empty($recipe['user_id']) ? (int)$recipe['user_id'] : 0;
            if ($status === 'published' && $recipeOwnerId > 0 && $recipeOwnerId !== $userId) {
                $this->notifyRecipeCreatorOfStory(
                    $recipeOwnerId,
                    $userId,
                    $authorUsername,
                    $recipeId,
                    (string)$recipe['title'],
                    $title,
                    $slug
                );
            }

            echo json_encode([
                'success' => true,
                'message' => $status === 'published'
                    ? 'Your story has been published to The Veggie Vault!'
                    : 'Your story draft has been saved.',
                'storyId' => $storyId,
                'slug' => $slug,
                'story' => [
                    'id' => $storyId,
                    'recipeId' => $recipeId,
                    'userId' => $userId,
                    'authorName' => $authorUsername,
                    'title' => $title,
                    'slug' => $slug,
                    'excerpt' => $excerpt,
                    'coverImage' => $coverImageUrl,
                    'effectiveCoverImage' => $coverImageUrl ?: ($recipe['image_url'] ?? null),
                    'contentHtml' => $sanitizedHtml,
                    'readTimeMinutes' => $readTimeMinutes,
                    'status' => $status,
                    'createdAt' => date('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to save story: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * PUT /stories
     * Updates an existing Story owned by the current user (or admin).
     */
    public function updateStory(): void {
        $currentUser = $this->requireAuth();
        $userId = (int)$currentUser['id'];
        $isAdmin = $currentUser['is_admin'];

        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $storyId = (int)($data['id'] ?? $data['story_id'] ?? 0);

        if ($storyId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Story ID is required.']);
            return;
        }

        $existingStmt = $this->db->prepare("
            SELECT s.*, r.title AS recipe_title, r.user_id AS recipe_owner_id, r.image_url AS recipe_image_url
            FROM recipe_stories s
            LEFT JOIN recipes r ON r.id = s.recipe_id
            WHERE s.id = ?
            LIMIT 1
        ");
        $existingStmt->execute([$storyId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Story not found.']);
            return;
        }

        if ((int)$existing['user_id'] !== $userId && !$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You can only edit your own stories.']);
            return;
        }

        $title = array_key_exists('title', $data)
            ? trim((string)preg_replace('/\s+/u', ' ', (string)$data['title']))
            : (string)$existing['title'];

        if ($title === '' || mb_strlen($title, 'UTF-8') < 4) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Story title must be at least 4 characters.']);
            return;
        }
        if (mb_strlen($title, 'UTF-8') > 200) {
            $title = mb_substr($title, 0, 200, 'UTF-8');
        }

        $rawContentHtml = array_key_exists('content_html', $data) || array_key_exists('contentHtml', $data)
            ? (string)($data['content_html'] ?? $data['contentHtml'] ?? '')
            : (string)$existing['content_html'];

        $sanitizedHtml = $this->storyService->sanitizeContentHtml($rawContentHtml);
        $plainText = $this->storyService->extractPlainText($sanitizedHtml);

        if (mb_strlen($plainText, 'UTF-8') < 20) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Story body must be at least 20 characters long.',
            ]);
            return;
        }

        $customExcerpt = array_key_exists('excerpt', $data)
            ? trim((string)$data['excerpt'])
            : (string)$existing['excerpt'];

        $profanity = new ProfanityFilterService();
        $profanity->assertClean($title, 'story title');
        if ($customExcerpt !== '') {
            $profanity->assertClean($customExcerpt, 'story excerpt');
        }
        $profanity->assertClean($plainText, 'story content');

        $coverImageUrl = $existing['cover_image'];
        if (array_key_exists('cover_image', $data) || array_key_exists('coverImage', $data)) {
            $rawCover = trim((string)($data['cover_image'] ?? $data['coverImage'] ?? ''));
            if ($rawCover === '') {
                $coverImageUrl = null;
            } else {
                $imgRes = $this->storyService->processAndSaveStoryImage($rawCover, $userId, 'cover');
                if (!$imgRes['success']) {
                    http_response_code((int)($imgRes['status'] ?? 400));
                    echo json_encode([
                        'success' => false,
                        'message' => $imgRes['message'] ?? 'Invalid story cover image.',
                    ]);
                    return;
                }
                $coverImageUrl = $imgRes['imageUrl'];
            }
        }

        $status = array_key_exists('status', $data)
            ? ($data['status'] === 'draft' ? 'draft' : 'published')
            : (string)$existing['status'];

        $excerpt = $this->storyService->buildExcerpt($sanitizedHtml, $customExcerpt);
        $readTimeMinutes = $this->storyService->calculateReadTimeMinutes($sanitizedHtml);

        try {
            $updateStmt = $this->db->prepare("
                UPDATE recipe_stories
                SET title = :title,
                    excerpt = :excerpt,
                    cover_image = :cover_image,
                    content_html = :content_html,
                    read_time_minutes = :read_time_minutes,
                    status = :status
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':title' => $title,
                ':excerpt' => $excerpt,
                ':cover_image' => $coverImageUrl,
                ':content_html' => $sanitizedHtml,
                ':read_time_minutes' => $readTimeMinutes,
                ':status' => $status,
                ':id' => $storyId,
            ]);

            // If newly published from a draft, notify recipe creator
            $recipeOwnerId = !empty($existing['recipe_owner_id']) ? (int)$existing['recipe_owner_id'] : 0;
            if (
                $existing['status'] !== 'published'
                && $status === 'published'
                && $recipeOwnerId > 0
                && $recipeOwnerId !== (int)$existing['user_id']
            ) {
                $this->notifyRecipeCreatorOfStory(
                    $recipeOwnerId,
                    (int)$existing['user_id'],
                    (string)$currentUser['username'],
                    (int)$existing['recipe_id'],
                    (string)($existing['recipe_title'] ?? 'your recipe'),
                    $title,
                    (string)$existing['slug']
                );
            }

            echo json_encode([
                'success' => true,
                'message' => 'Story updated!',
                'slug' => $existing['slug'],
                'story' => [
                    'id' => $storyId,
                    'recipeId' => (int)$existing['recipe_id'],
                    'userId' => (int)$existing['user_id'],
                    'title' => $title,
                    'slug' => (string)$existing['slug'],
                    'excerpt' => $excerpt,
                    'coverImage' => $coverImageUrl,
                    'effectiveCoverImage' => $coverImageUrl ?: ($existing['recipe_image_url'] ?? null),
                    'contentHtml' => $sanitizedHtml,
                    'readTimeMinutes' => $readTimeMinutes,
                    'status' => $status,
                ],
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update story: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * DELETE /stories
     * Deletes a Story owned by the current user (or admin).
     */
    public function deleteStory(): void {
        $currentUser = $this->requireAuth();
        $userId = (int)$currentUser['id'];
        $isAdmin = $currentUser['is_admin'];

        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $storyId = (int)($_GET['id'] ?? $data['id'] ?? 0);

        if ($storyId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Story ID is required.']);
            return;
        }

        $stmt = $this->db->prepare("SELECT id, user_id FROM recipe_stories WHERE id = ? LIMIT 1");
        $stmt->execute([$storyId]);
        $story = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$story) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Story not found.']);
            return;
        }

        if ((int)$story['user_id'] !== $userId && !$isAdmin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You can only delete your own stories.']);
            return;
        }

        $delStmt = $this->db->prepare("DELETE FROM recipe_stories WHERE id = ?");
        $delStmt->execute([$storyId]);

        echo json_encode([
            'success' => true,
            'message' => 'Story deleted from the Vault.',
        ]);
    }

    /**
     * POST /stories/like
     * Toggles a like on a published story for the logged-in user.
     */
    public function toggleStoryLike(): void {
        $currentUser = $this->requireAuth();
        $userId = (int)$currentUser['id'];

        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $storyId = (int)($data['story_id'] ?? $data['storyId'] ?? 0);

        if ($storyId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Story ID is required.']);
            return;
        }

        $checkStmt = $this->db->prepare("SELECT id FROM story_likes WHERE story_id = ? AND user_id = ? LIMIT 1");
        $checkStmt->execute([$storyId, $userId]);
        $existingLikeId = $checkStmt->fetchColumn();

        if ($existingLikeId) {
            $delStmt = $this->db->prepare("DELETE FROM story_likes WHERE id = ?");
            $delStmt->execute([(int)$existingLikeId]);
            $isLiked = false;
        } else {
            $insStmt = $this->db->prepare("INSERT IGNORE INTO story_likes (story_id, user_id) VALUES (?, ?)");
            $insStmt->execute([$storyId, $userId]);
            $isLiked = true;
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM story_likes WHERE story_id = ?");
        $countStmt->execute([$storyId]);
        $likeCount = (int)$countStmt->fetchColumn();

        echo json_encode([
            'success' => true,
            'isLiked' => $isLiked,
            'likeCount' => $likeCount,
        ]);
    }

    /**
     * GET /admin/stories
     * Admin-only list of all stories across all users (published and draft) for the Admin Vault.
     */
    public function getAdminStories(): void {
        $currentUser = $this->requireAuth();
        if (empty($currentUser['is_admin'])) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Admin access required.',
            ]);
            return;
        }

        try {
            $sql = "SELECT s.id, s.recipe_id, s.user_id, s.title, s.slug, s.excerpt,
                           s.cover_image, s.read_time_minutes, s.status, s.created_at, s.updated_at,
                           u.username AS author_name,
                           u.display_name AS author_display_name,
                           u.profile_image AS author_avatar,
                           r.title AS recipe_title,
                           r.image_url AS recipe_image_url,
                           r.user_id AS recipe_author_id,
                           ru.username AS recipe_author_name,
                           (SELECT COUNT(*) FROM story_likes sl WHERE sl.story_id = s.id) AS like_count,
                           0 AS is_liked
                    FROM recipe_stories s
                    LEFT JOIN users u ON u.id = s.user_id
                    LEFT JOIN recipes r ON r.id = s.recipe_id
                    LEFT JOIN users ru ON ru.id = r.user_id
                    ORDER BY s.created_at DESC, s.id DESC";

            $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $stories = array_map(fn($row) => $this->formatStoryRow($row, false), $rows);

            echo json_encode([
                'success' => true,
                'stories' => $stories,
            ]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load admin stories: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Inserts a notification for the recipe creator when a community member publishes a Story about their recipe.
     */
    private function notifyRecipeCreatorOfStory(
        int $recipeOwnerId,
        int $authorUserId,
        string $authorUsername,
        int $recipeId,
        string $recipeTitle,
        string $storyTitle,
        string $storySlug
    ): void {
        try {
            $msg = sprintf(
                '%s published a Story ("%s") about your recipe "%s"',
                $authorUsername,
                $storyTitle,
                $recipeTitle
            );
            $notifStmt = $this->db->prepare("
                INSERT INTO notifications
                    (user_id, actor_user_id, actor_username, recipe_id, comment_id, story_slug, type, message, is_read)
                VALUES
                    (:user_id, :actor_user_id, :actor_username, :recipe_id, NULL, :story_slug, 'recipe_story', :message, 0)
            ");
            $notifStmt->execute([
                ':user_id' => $recipeOwnerId,
                ':actor_user_id' => $authorUserId,
                ':actor_username' => $authorUsername,
                ':recipe_id' => $recipeId,
                ':story_slug' => $storySlug,
                ':message' => $msg,
            ]);
        } catch (\Throwable $e) {
            error_log('Failed to insert recipe_story notification: ' . $e->getMessage());
        }
    }

    /**
     * GET /stories/og-image?slug=<slug>
     * Renders a 1200x630 JPEG Open Graph preview using the story's selected cover/recipe image,
     * overlaid with a "VEGGIE VAULT STORIES" badge and the story creator's circular avatar + handle.
     */
    public function getStoryOgImage(): void {
        $rawSlug = trim((string)($_GET['slug'] ?? ''));
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawSlug);
        if ($slug === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid story slug.']);
            return;
        }

        if (!extension_loaded('gd')) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'GD extension unavailable.']);
            return;
        }

        $stmt = $this->db->prepare("
            SELECT s.id, s.cover_image,
                   u.username AS author_name,
                   u.display_name AS author_display_name,
                   u.profile_image AS author_avatar,
                   r.image_url AS recipe_image_url,
                   r.image_source AS recipe_image_source
            FROM recipe_stories s
            LEFT JOIN users u ON u.id = s.user_id
            LEFT JOIN recipes r ON r.id = s.recipe_id
            WHERE s.slug = ? AND s.status = 'published'
            LIMIT 1
        ");
        $stmt->execute([$slug]);
        $story = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$story) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Story not found.']);
            return;
        }

        $rawCover = trim((string)($story['cover_image'] ?? ''));
        $rawRecipeImg = trim((string)($story['recipe_image_url'] ?? ''));
        $recipeSource = trim((string)($story['recipe_image_source'] ?? ''));
        $selectedImg = $rawCover !== ''
            ? $rawCover
            : ($recipeSource !== 'none' ? $rawRecipeImg : '');

        $fallbackPath = OgImageService::resolveBaseOgImagePath();
        $src = OgImageService::loadImageResource($selectedImg, $fallbackPath);
        $canvas = $src ? OgImageService::createCoverCanvas($src, 1200, 630) : null;
        if (!$canvas) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Story OG image unavailable.']);
            return;
        }

        $this->drawStoriesBadgeOnOgCanvas($canvas);
        $this->drawStoryCreatorOverlayOnOgCanvas($canvas, $story);

        OgImageService::outputJpeg($canvas, 88);
    }

    /**
     * Draws the "VEGGIE VAULT STORIES" badge in the top-left corner of the story OG image.
     */
    private function drawStoriesBadgeOnOgCanvas($canvas): void {
        $label = 'VEGGIE VAULT STORIES';
        $fontPath = OgImageService::resolveBoldFontPath();
        $fontSize = 19;
        $padX = 26;
        $padY = 15;
        $iconSpace = 30;

        if ($fontPath) {
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $label);
            $textW = $bbox !== false ? ($bbox[2] - $bbox[0]) : strlen($label) * 14;
            $textH = $bbox !== false ? ($bbox[1] - $bbox[7]) : 22;
        } else {
            $textW = imagefontwidth(5) * strlen($label);
            $textH = imagefontheight(5);
        }

        $pillW = (int)$textW + $iconSpace + ($padX * 2);
        $pillH = max(52, (int)$textH + ($padY * 2));
        $left = 36;
        $top = 36;
        $right = $left + $pillW;
        $bottom = $top + $pillH;
        $radius = (int)floor($pillH / 2);

        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 62);
        OgImageService::drawCapsule($canvas, $left + 3, $top + 6, $right + 3, $bottom + 6, $radius, $shadow);

        $borderColor = imagecolorallocate($canvas, 134, 239, 172); // #86efac
        $fillColor = imagecolorallocate($canvas, 20, 83, 45);      // #14532d
        $textColor = imagecolorallocate($canvas, 240, 253, 244);   // #f0fdf4

        OgImageService::drawCapsule($canvas, $left - 3, $top - 3, $right + 3, $bottom + 3, $radius + 3, $borderColor);
        OgImageService::drawCapsule($canvas, $left, $top, $right, $bottom, $radius, $fillColor);

        // Simple open-book icon on the left of the badge
        $bx = $left + $padX + 2;
        $by = (int)round(($top + $bottom) / 2);
        $leftPage = [$bx, $by - 8, $bx + 9, $by - 6, $bx + 9, $by + 9, $bx, $by + 7];
        $rightPage = [$bx + 11, $by - 6, $bx + 20, $by - 8, $bx + 20, $by + 7, $bx + 11, $by + 9];
        imagefilledpolygon($canvas, $leftPage, $textColor);
        imagefilledpolygon($canvas, $rightPage, $textColor);

        $textStartX = $left + $padX + $iconSpace;
        if ($fontPath && isset($bbox) && $bbox !== false) {
            $tx = $textStartX - $bbox[0];
            $ty = (int)round(($top + $bottom) / 2 - ($textH / 2) - $bbox[7]);
            imagettftext($canvas, $fontSize, 0, $tx, $ty, $textColor, $fontPath, $label);
        } else {
            $ty = (int)round(($top + $bottom) / 2 - ($textH / 2));
            imagestring($canvas, 5, $textStartX, $ty, $label, $textColor);
        }
    }

    /**
     * Draws a compact bottom-left creator overlay (circular avatar + handle pill).
     */
    private function drawStoryCreatorOverlayOnOgCanvas($canvas, array $story): void {
        $avatarDiameter = 84;
        $avatarRadius = (int)($avatarDiameter / 2);
        $whiteRingR = $avatarRadius + 3;
        $greenRingR = $whiteRingR + 3;
        $cx = 36 + $greenRingR;
        $cy = 630 - 36 - $greenRingR;

        for ($i = 4; $i >= 1; $i--) {
            $alpha = 127 - (int)round((5 - $i) * 9);
            $shadowCol = imagecolorallocatealpha($canvas, 8, 22, 16, max(72, min(124, $alpha)));
            $r = $greenRingR + ($i * 2);
            imagefilledellipse($canvas, $cx + 3, $cy + 5, $r * 2, $r * 2, $shadowCol);
        }

        imagefilledellipse($canvas, $cx, $cy, $greenRingR * 2, $greenRingR * 2, imagecolorallocate($canvas, 134, 239, 172));
        imagefilledellipse($canvas, $cx, $cy, $whiteRingR * 2, $whiteRingR * 2, imagecolorallocate($canvas, 255, 255, 255));

        $avatarSrc = OgImageService::loadImageResource($story['author_avatar'] ?? null);
        if (!$avatarSrc) {
            $labelSource = trim((string)($story['author_display_name'] ?: $story['author_name'] ?: 'V'));
            $initial = strtoupper(substr($labelSource, 0, 1));
            $avatarSrc = OgImageService::createInitialAvatar($initial !== '' ? $initial : 'V', 256);
        }

        if ($avatarSrc) {
            $circular = OgImageService::createCircularAvatar($avatarSrc, $avatarDiameter);
            if ($circular) {
                imagecopy($canvas, $circular, $cx - $avatarRadius, $cy - $avatarRadius, 0, 0, $avatarDiameter, $avatarDiameter);
            }
        }

        $username = trim((string)($story['author_name'] ?? ''));
        if ($username !== '') {
            $handleText = '@' . $username;
            if (strlen($handleText) > 24) {
                $handleText = substr($handleText, 0, 23) . '...';
            }

            $fontPath = OgImageService::resolveBoldFontPath();
            $fontSize = 17;
            $padX = 20;
            $padY = 11;
            if ($fontPath) {
                $bbox = imagettfbbox($fontSize, 0, $fontPath, $handleText);
                $textW = $bbox !== false ? ($bbox[2] - $bbox[0]) : strlen($handleText) * 11;
                $textH = $bbox !== false ? ($bbox[1] - $bbox[7]) : 18;
            } else {
                $textW = imagefontwidth(5) * strlen($handleText);
                $textH = imagefontheight(5);
            }

            $pillH = max(40, (int)$textH + ($padY * 2));
            $pillW = max(110, (int)$textW + ($padX * 2));
            $pillLeft = $cx + $greenRingR + 14;
            $pillRight = $pillLeft + $pillW;
            $pillTop = $cy - (int)round($pillH / 2);
            $pillBottom = $pillTop + $pillH;
            $radius = (int)floor($pillH / 2);

            $pillShadow = imagecolorallocatealpha($canvas, 0, 0, 0, 72);
            OgImageService::drawCapsule($canvas, $pillLeft + 2, $pillTop + 4, $pillRight + 2, $pillBottom + 4, $radius, $pillShadow);
            OgImageService::drawCapsule($canvas, $pillLeft - 2, $pillTop - 2, $pillRight + 2, $pillBottom + 2, $radius + 2, imagecolorallocate($canvas, 134, 239, 172));
            OgImageService::drawCapsule($canvas, $pillLeft, $pillTop, $pillRight, $pillBottom, $radius, imagecolorallocatealpha($canvas, 17, 56, 40, 12));

            $white = imagecolorallocate($canvas, 255, 255, 255);
            if ($fontPath && isset($bbox) && $bbox !== false) {
                $tx = $pillLeft + $padX - $bbox[0];
                $ty = (int)round($cy - ($textH / 2) - $bbox[7]);
                imagettftext($canvas, $fontSize, 0, $tx, $ty, $white, $fontPath, $handleText);
            } else {
                $tx = $pillLeft + $padX;
                $ty = (int)round($cy - ($textH / 2));
                imagestring($canvas, 5, $tx, $ty, $handleText, $white);
            }
        }
    }
}
