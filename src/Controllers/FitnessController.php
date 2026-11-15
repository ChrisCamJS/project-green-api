<?php
namespace App\Controllers;

use App\Database;
use PDO;

class FitnessController {

    private const MET_VALUES = [
        'running' => 9.8,
        'cycling' => 7.5,
        'walking' => 3.8,
        'hiking' => 6.0,
        'swimming' => 7.0,
        'other' => 5.5,
    ];

    private function requireAuthUserId(): int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            echo json_encode(["error" => "Authentication required"]);
            exit;
        }
        return (int)$userId;
    }

    private function estimateCalories(PDO $db, int $userId, string $type, int $durationMins): int {
        $stmt = $db->prepare("SELECT weight_lbs FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $weightLbs = (float)$stmt->fetchColumn();
        if ($weightLbs <= 0) {
            $weightLbs = 155.0; // standard adult fallback
        }
        $weightKg = $weightLbs * 0.45359237;
        $met = self::MET_VALUES[$type] ?? self::MET_VALUES['other'];
        return (int)round($met * $weightKg * ($durationMins / 60.0));
    }

    public function getActivities() {
        $userId = $this->requireAuthUserId();
        $start = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
        $end = $_GET['end'] ?? date('Y-m-d', strtotime('+14 days'));

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT id, user_id, activity_date, activity_type, custom_label, duration_mins, calories_burned, created_at
            FROM fitness_activities
            WHERE user_id = :uid AND activity_date BETWEEN :start AND :end
            ORDER BY activity_date ASC, id ASC
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':start' => $start,
            ':end' => $end,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['duration_mins'] = (int)$r['duration_mins'];
            $r['calories_burned'] = (int)$r['calories_burned'];
        }

        echo json_encode(["activities" => $rows]);
    }

    public function addActivity() {
        $userId = $this->requireAuthUserId();
        $data = json_decode(file_get_contents("php://input"), true) ?? [];

        $activityDate = trim((string)($data['activity_date'] ?? ''));
        $activityType = strtolower(trim((string)($data['activity_type'] ?? '')));
        $customLabel = trim((string)($data['custom_label'] ?? ''));
        $durationMins = (int)($data['duration_mins'] ?? 0);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $activityDate)) {
            http_response_code(400);
            echo json_encode(["error" => "Valid activity_date (YYYY-MM-DD) is required."]);
            return;
        }
        if (!array_key_exists($activityType, self::MET_VALUES)) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid activity_type."]);
            return;
        }
        if ($activityType === 'other' && $customLabel === '') {
            http_response_code(400);
            echo json_encode(["error" => "Please enter the activity name for Other."]);
            return;
        }
        if ($durationMins <= 0 || $durationMins > 1440) {
            http_response_code(400);
            echo json_encode(["error" => "Duration must be between 1 and 1440 minutes."]);
            return;
        }

        $db = Database::connect();
        $calories = $this->estimateCalories($db, $userId, $activityType, $durationMins);

        $stmt = $db->prepare("
            INSERT INTO fitness_activities (user_id, activity_date, activity_type, custom_label, duration_mins, calories_burned)
            VALUES (:uid, :adate, :atype, :clabel, :mins, :kcal)
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':adate' => $activityDate,
            ':atype' => $activityType,
            ':clabel' => $activityType === 'other' ? mb_substr($customLabel, 0, 100) : null,
            ':mins' => $durationMins,
            ':kcal' => $calories,
        ]);

        $newId = (int)$db->lastInsertId();
        AchievementController::syncAndFetch($db, $userId);

        echo json_encode([
            "success" => true,
            "activity" => [
                "id" => $newId,
                "user_id" => $userId,
                "activity_date" => $activityDate,
                "activity_type" => $activityType,
                "custom_label" => $activityType === 'other' ? mb_substr($customLabel, 0, 100) : null,
                "duration_mins" => $durationMins,
                "calories_burned" => $calories,
            ]
        ]);
    }

    public function deleteActivity() {
        $userId = $this->requireAuthUserId();
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(["error" => "Missing activity id"]);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("DELETE FROM fitness_activities WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $userId]);

        echo json_encode(["success" => true]);
    }
}
