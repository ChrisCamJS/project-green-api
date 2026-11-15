<?php

namespace App\Controllers;

use App\Database;
use PDO;

class ChatController {
    private $db;

    public function __construct() {
        $this->db = Database::connect();
        $this->ensureSchema();
    }

    private function ensureSchema(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS recent_chats (
                id VARCHAR(64) NOT NULL,
                user_id INT NOT NULL,
                engine_mode VARCHAR(32) NOT NULL DEFAULT 'chat',
                title VARCHAR(150) NOT NULL,
                dietary_prefs LONGTEXT DEFAULT NULL,
                chat_history LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_recent_chats_user_updated (user_id, updated_at),
                KEY idx_recent_chats_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function requireAuth(): int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            http_response_code(401);
            echo json_encode([
                "success" => false,
                "message" => "Please log in to access saved chats."
            ]);
            exit;
        }

        return (int)$userId;
    }

    private function pruneExpired(): void {
        $this->db->exec("DELETE FROM recent_chats WHERE expires_at < NOW()");
    }

    private function formatRow(array $row): array {
        $dietaryPrefs = json_decode($row['dietary_prefs'] ?? '{}', true);
        if (!is_array($dietaryPrefs)) {
            $dietaryPrefs = [];
        }

        $chatHistory = json_decode($row['chat_history'] ?? '[]', true);
        if (!is_array($chatHistory)) {
            $chatHistory = [];
        }

        return [
            'id' => $row['id'],
            'engineMode' => $row['engine_mode'],
            'title' => $row['title'],
            'dietaryPrefs' => $dietaryPrefs,
            'chatHistory' => $chatHistory,
            'createdAt' => strtotime($row['created_at']) * 1000,
            'updatedAt' => strtotime($row['updated_at']) * 1000,
            'expiresAt' => strtotime($row['expires_at']) * 1000,
        ];
    }

    private function fetchUserChats(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT id, engine_mode, title, dietary_prefs, chat_history, created_at, updated_at, expires_at
            FROM recent_chats
            WHERE user_id = :user_id AND expires_at >= NOW()
            ORDER BY updated_at DESC
            LIMIT 50
        ");
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'formatRow'], $rows);
    }

    /**
     * GET /chats
     */
    public function getRecentChats() {
        $userId = $this->requireAuth();
        $this->pruneExpired();

        $chats = $this->fetchUserChats($userId);

        echo json_encode([
            "success" => true,
            "chats" => $chats
        ]);
    }

    /**
     * POST /chats
     */
    public function saveChatSession() {
        $userId = $this->requireAuth();
        $this->pruneExpired();

        $data = json_decode(file_get_contents("php://input"), true);
        if (!is_array($data)) {
            $data = [];
        }

        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            $id = 'chat_' . (string)round(microtime(true) * 1000) . '_' . bin2hex(random_bytes(3));
        }
        $id = substr($id, 0, 64);

        $engineMode = substr(trim((string)($data['engineMode'] ?? 'chat')), 0, 32);
        $title = substr(trim((string)($data['title'] ?? 'Chat with Emma')), 0, 150);
        if ($title === '') {
            $title = 'Chat with Emma';
        }

        $dietaryPrefs = is_array($data['dietaryPrefs'] ?? null) ? $data['dietaryPrefs'] : [];
        $chatHistory = is_array($data['chatHistory'] ?? null) ? $data['chatHistory'] : [];

        $dietaryJson = json_encode($dietaryPrefs);
        $historyJson = json_encode($chatHistory);

        // Verify ownership if ID already exists
        $checkStmt = $this->db->prepare("SELECT user_id FROM recent_chats WHERE id = :id LIMIT 1");
        $checkStmt->execute([':id' => $id]);
        $existingOwner = $checkStmt->fetchColumn();

        if ($existingOwner !== false && (int)$existingOwner !== $userId) {
            // Avoid cross-user ID collision
            $id = 'chat_' . (string)round(microtime(true) * 1000) . '_' . bin2hex(random_bytes(3));
        }

        $stmt = $this->db->prepare("
            INSERT INTO recent_chats (id, user_id, engine_mode, title, dietary_prefs, chat_history, created_at, updated_at, expires_at)
            VALUES (:id, :user_id, :engine_mode, :title, :dietary_prefs, :chat_history, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))
            ON DUPLICATE KEY UPDATE
                engine_mode = VALUES(engine_mode),
                title = VALUES(title),
                dietary_prefs = VALUES(dietary_prefs),
                chat_history = VALUES(chat_history),
                updated_at = NOW(),
                expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
        ");

        $stmt->execute([
            ':id' => $id,
            ':user_id' => $userId,
            ':engine_mode' => $engineMode,
            ':title' => $title,
            ':dietary_prefs' => $dietaryJson,
            ':chat_history' => $historyJson,
        ]);

        $chats = $this->fetchUserChats($userId);

        echo json_encode([
            "success" => true,
            "id" => $id,
            "chats" => $chats
        ]);
    }

    /**
     * DELETE /chats
     */
    public function deleteChatSession() {
        $userId = $this->requireAuth();
        $this->pruneExpired();

        $id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
        $clearAll = isset($_GET['all']) && ($_GET['all'] === '1' || $_GET['all'] === 'true');

        if ($clearAll) {
            $stmt = $this->db->prepare("DELETE FROM recent_chats WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId]);
        } elseif ($id !== '') {
            $stmt = $this->db->prepare("DELETE FROM recent_chats WHERE id = :id AND user_id = :user_id");
            $stmt->execute([':id' => $id, ':user_id' => $userId]);
        } else {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Provide id or all=1 to delete chats."]);
            return;
        }

        $chats = $this->fetchUserChats($userId);

        echo json_encode([
            "success" => true,
            "chats" => $chats
        ]);
    }
}
