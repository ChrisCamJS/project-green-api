<?php

namespace App\Controllers;

use App\Database;
use PDO;

class MealPlanController {

    private const VALID_SLOTS = ['breakfast', 'lunch', 'dinner', 'snack'];

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
        $db->exec("
            CREATE TABLE IF NOT EXISTS meal_plan_entries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                recipe_id INT NOT NULL,
                plan_date DATE NOT NULL,
                meal_slot VARCHAR(32) NOT NULL DEFAULT 'dinner',
                servings DECIMAL(5, 2) NOT NULL DEFAULT 1.00,
                notes VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_date (user_id, plan_date),
                INDEX idx_user_recipe (user_id, recipe_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
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

    private function formatEntryRow(array $row): array {
        return [
            'id' => (int)$row['id'],
            'userId' => (int)$row['user_id'],
            'recipeId' => (int)$row['recipe_id'],
            'planDate' => $row['plan_date'],
            'mealSlot' => $row['meal_slot'],
            'servings' => (float)$row['servings'],
            'notes' => $row['notes'] ?? '',
            'title' => $row['title'],
            'description' => $row['description'] ?? '',
            'imageUrl' => $row['image_url'],
            'imageSource' => $row['image_source'],
            'isPublic' => (bool)$row['is_public'],
            'isDraft' => !(bool)$row['is_public'],
            'prepTime' => (int)($row['prep_time_mins'] ?? 0),
            'cookTime' => (int)($row['cook_time_mins'] ?? 0),
            'yields' => $row['yields'] ?? '',
            'authorId' => !empty($row['recipe_author_id']) ? (int)$row['recipe_author_id'] : null,
            'authorName' => $row['author_name'] ?? 'Emma (AI)',
            'calories' => $row['calories'] !== null ? (int)$row['calories'] : null,
            'protein_g' => $row['protein_g'] !== null ? (int)$row['protein_g'] : null,
            'carbs_g' => $row['carbs_g'] !== null ? (int)$row['carbs_g'] : null,
            'fat_g' => $row['fat_g'] !== null ? (int)$row['fat_g'] : null,
            'fiber_g' => $row['fiber_g'] !== null ? (int)$row['fiber_g'] : null,
        ];
    }

    private function fetchSingleEntry(PDO $db, int $entryId, int $userId): ?array {
        $stmt = $db->prepare("
            SELECT mp.id, mp.user_id, mp.recipe_id, mp.plan_date, mp.meal_slot, mp.servings, mp.notes,
                   r.title, r.description, r.image_url, r.image_source, r.is_public,
                   r.prep_time_mins, r.cook_time_mins, r.yields, r.user_id AS recipe_author_id,
                   u.username AS author_name,
                   m.calories, m.protein_g, m.carbs_g, m.fat_g, m.fiber_g
            FROM meal_plan_entries mp
            JOIN recipes r ON r.id = mp.recipe_id
            LEFT JOIN users u ON u.id = r.user_id
            LEFT JOIN macros m ON m.recipe_id = r.id
            WHERE mp.id = ? AND mp.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$entryId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->formatEntryRow($row) : null;
    }

    /**
     * GET /meal-plans?start=YYYY-MM-DD&end=YYYY-MM-DD
     * Returns all planned meals for the active user within the requested date window.
     */
    public function getMealPlans() {
        $userId = $this->requireAuth();
        $db = Database::connect();
        $this->ensureTableExists($db);

        $start = $_GET['start'] ?? null;
        $end = $_GET['end'] ?? null;

        if (!$this->isValidDate($start)) {
            $start = date('Y-m-01', strtotime('-7 days'));
        }
        if (!$this->isValidDate($end)) {
            $end = date('Y-m-t', strtotime('+14 days'));
        }

        $sql = "
            SELECT mp.id, mp.user_id, mp.recipe_id, mp.plan_date, mp.meal_slot, mp.servings, mp.notes,
                   r.title, r.description, r.image_url, r.image_source, r.is_public,
                   r.prep_time_mins, r.cook_time_mins, r.yields, r.user_id AS recipe_author_id,
                   u.username AS author_name,
                   m.calories, m.protein_g, m.carbs_g, m.fat_g, m.fiber_g
            FROM meal_plan_entries mp
            JOIN recipes r ON r.id = mp.recipe_id
            LEFT JOIN users u ON u.id = r.user_id
            LEFT JOIN macros m ON m.recipe_id = r.id
            WHERE mp.user_id = ?
              AND mp.plan_date BETWEEN ? AND ?
              AND (r.is_public = 1 OR r.user_id = ?)
            ORDER BY mp.plan_date ASC,
                     FIELD(mp.meal_slot, 'breakfast', 'lunch', 'dinner', 'snack') ASC,
                     mp.id ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$userId, $start, $end, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $entries = array_map(fn($row) => $this->formatEntryRow($row), $rows ?: []);

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

        $sql = "
            SELECT r.id, r.user_id, r.title, r.description, r.image_url, r.image_source,
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
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $recipes = array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'userId' => !empty($r['user_id']) ? (int)$r['user_id'] : null,
                'title' => $r['title'],
                'description' => $r['description'] ?? '',
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
            ];
        }, $rows ?: []);

        echo json_encode([
            'success' => true,
            'recipes' => $recipes,
        ]);
    }

    /**
     * POST /meal-plans
     * Schedules a published recipe or the user's own private draft on a date & meal slot.
     */
    public function addMealPlanEntry() {
        $userId = $this->requireAuth();
        $db = Database::connect();
        $this->ensureTableExists($db);

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = [];
        }

        $recipeId = (int)($data['recipe_id'] ?? 0);
        $planDate = trim((string)($data['plan_date'] ?? ''));
        $mealSlot = $this->normalizeSlot($data['meal_slot'] ?? 'dinner');
        $servings = isset($data['servings']) ? max(0.5, min(20.0, (float)$data['servings'])) : 1.0;
        $notes = substr(trim((string)($data['notes'] ?? '')), 0, 255);

        if ($recipeId <= 0 || !$this->isValidDate($planDate)) {
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

        $insStmt = $db->prepare("
            INSERT INTO meal_plan_entries (user_id, recipe_id, plan_date, meal_slot, servings, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insStmt->execute([
            $userId,
            $recipeId,
            $planDate,
            $mealSlot,
            $servings,
            $notes !== '' ? $notes : null,
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
        $servings = isset($data['servings']) ? max(0.5, min(20.0, (float)$data['servings'])) : (float)$existing['servings'];
        $notes = array_key_exists('notes', $data)
            ? substr(trim((string)($data['notes'] ?? '')), 0, 255)
            : (string)($existing['notes'] ?? '');
        $recipeId = isset($data['recipe_id']) ? (int)$data['recipe_id'] : (int)$existing['recipe_id'];

        if ($recipeId !== (int)$existing['recipe_id']) {
            $rStmt = $db->prepare("SELECT id, user_id, is_public FROM recipes WHERE id = ? LIMIT 1");
            $rStmt->execute([$recipeId]);
            $rRow = $rStmt->fetch(PDO::FETCH_ASSOC);
            if (!$rRow || ((int)$rRow['is_public'] !== 1 && (int)$rRow['user_id'] !== $userId)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Selected recipe cannot be added to your meal plan.']);
                return;
            }
        }

        $updStmt = $db->prepare("
            UPDATE meal_plan_entries
            SET recipe_id = ?, plan_date = ?, meal_slot = ?, servings = ?, notes = ?
            WHERE id = ? AND user_id = ?
        ");
        $updStmt->execute([
            $recipeId,
            $planDate,
            $mealSlot,
            $servings,
            $notes !== '' ? $notes : null,
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
}
