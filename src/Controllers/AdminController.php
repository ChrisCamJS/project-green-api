<?php

namespace App\Controllers;

use App\Database;
use PDO;

class AdminController {
    public function getInviteCodes() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Nice try. Admins only."]);
            return;
        }

        $db = \App\Database::connect();
        $stmt = $db->query("SELECT id, code, is_active, created_at FROM invite_codes ORDER BY created_at DESC");
        $codes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        echo json_encode(["success" => true, "codes" => $codes]);
    }

    public function generateInviteCode() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403); return;
        }

        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);
        $customCode = trim($data['code'] ?? '');

        // Generate a cheeky random code if you didn't provide a custom one
        if (empty($customCode)) {
            $customCode = 'VAULT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        }

        $db = \App\Database::connect();
        $stmt = $db->prepare("INSERT INTO invite_codes (code, is_active) VALUES (:code, 1)");
        
        try {
            $stmt->execute([':code' => $customCode]);
            echo json_encode(["success" => true, "code" => $customCode, "message" => "New VIP pass minted!"]);
        } catch (\PDOException $e) {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "Code already exists. Try another one."]);
        }
    }

    public function toggleInviteCode() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403); return;
        }

        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);
        $codeId = $data['id'] ?? null;
        $isActive = $data['is_active'] ? 1 : 0;

        if (!$codeId) {
            http_response_code(400); return;
        }

        $db = \App\Database::connect();
        $stmt = $db->prepare("UPDATE invite_codes SET is_active = :isActive WHERE id = :id");
        $stmt->execute([':isActive' => $isActive, ':id' => $codeId]);

        echo json_encode(["success" => true, "message" => "Code status updated."]);
    }

}

