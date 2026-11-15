<?php
namespace App\Controllers;

use App\Database;

class NotificationController {

    private function requireAuthUserId() {
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

    public function getNotifications() {
        $userId = $this->requireAuthUserId();
        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                n.id,
                n.user_id,
                n.actor_user_id,
                n.actor_username,
                n.recipe_id,
                n.comment_id,
                n.type,
                n.message,
                n.is_read,
                n.created_at,
                r.title AS recipe_title
            FROM notifications n
            LEFT JOIN recipes r ON n.recipe_id = r.id
            WHERE n.user_id = :uid
            ORDER BY n.created_at DESC, n.id DESC
            LIMIT 40
        ");
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['recipe_id'] = (int)$row['recipe_id'];
            $row['comment_id'] = $row['comment_id'] !== null ? (int)$row['comment_id'] : null;
            $row['is_read'] = (int)$row['is_read'];
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0");
        $countStmt->execute([':uid' => $userId]);
        $unreadCount = (int)$countStmt->fetchColumn();

        echo json_encode([
            "notifications" => $rows,
            "unread_count" => $unreadCount
        ]);
    }

    public function markRead() {
        $userId = $this->requireAuthUserId();
        $data = json_decode(file_get_contents("php://input"), true) ?? [];
        $db = Database::connect();

        if (!empty($data['all'])) {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :uid AND is_read = 0");
            $stmt->execute([':uid' => $userId]);
        } elseif (!empty($data['id'])) {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => (int)$data['id'], ':uid' => $userId]);
        } else {
            http_response_code(400);
            echo json_encode(["error" => "Missing notification id or all flag"]);
            return;
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0");
        $countStmt->execute([':uid' => $userId]);
        $unreadCount = (int)$countStmt->fetchColumn();

        echo json_encode([
            "success" => true,
            "unread_count" => $unreadCount
        ]);
    }

    public function deleteNotification() {
        $userId = $this->requireAuthUserId();
        $db = Database::connect();

        if (!empty($_GET['all'])) {
            $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = :uid");
            $stmt->execute([':uid' => $userId]);
        } elseif (!empty($_GET['id'])) {
            $stmt = $db->prepare("DELETE FROM notifications WHERE id = :id AND user_id = :uid");
            $stmt->execute([':id' => (int)$_GET['id'], ':uid' => $userId]);
        } else {
            http_response_code(400);
            echo json_encode(["error" => "Missing notification id or all flag"]);
            return;
        }

        echo json_encode(["success" => true]);
    }
}
