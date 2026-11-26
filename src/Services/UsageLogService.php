<?php

namespace App\Services;

use PDO;

class UsageLogService {
    private static bool $schemaEnsured = false;
    private static bool $moderationColumnsEnsured = false;

    public static function ensureUserModerationColumns(PDO $db): void {
        if (self::$moderationColumnsEnsured) {
            return;
        }
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM users LIKE 'is_suspended'")->fetch();
            if (!$colCheck) {
                $db->exec("ALTER TABLE users ADD COLUMN is_suspended TINYINT(1) NOT NULL DEFAULT 0");
            }
            $reasonCheck = $db->query("SHOW COLUMNS FROM users LIKE 'suspended_reason'")->fetch();
            if (!$reasonCheck) {
                $db->exec("ALTER TABLE users ADD COLUMN suspended_reason VARCHAR(255) DEFAULT NULL");
            }
            $atCheck = $db->query("SHOW COLUMNS FROM users LIKE 'suspended_at'")->fetch();
            if (!$atCheck) {
                $db->exec("ALTER TABLE users ADD COLUMN suspended_at DATETIME DEFAULT NULL");
            }
        } catch (\Throwable $e) {
            // Ignore if columns already exist or migration races
        }
        self::$moderationColumnsEnsured = true;
    }

    public static function isUserSuspended(PDO $db, int $userId): bool {
        if ($userId <= 0) {
            return false;
        }
        try {
            self::ensureUserModerationColumns($db);
            $stmt = $db->prepare("SELECT is_suspended FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function ensureSchema(PDO $db): void {
        if (self::$schemaEnsured) {
            return;
        }
        $db->exec("
            CREATE TABLE IF NOT EXISTS api_usage_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                endpoint VARCHAR(64) NOT NULL,
                model VARCHAR(80) DEFAULT NULL,
                prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
                vault_tokens_spent DECIMAL(10,4) NOT NULL DEFAULT 0,
                estimated_cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_api_usage_user_created (user_id, created_at),
                INDEX idx_api_usage_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$schemaEnsured = true;
    }

    public static function estimateGeminiTextCostUsd(int $promptTokens, int $outputTokens): float {
        return ($promptTokens * 0.10 / 1000000) + ($outputTokens * 0.40 / 1000000);
    }

    public static function estimateGeminiImageCostUsd(int $promptTokens = 0, int $outputTokens = 0): float {
        return 0.02 + self::estimateGeminiTextCostUsd($promptTokens, $outputTokens);
    }

    public static function logUsage(PDO $db, int $userId, string $endpoint, array $opts = []): void {
        if ($userId <= 0 || $endpoint === '') {
            return;
        }
        try {
            self::ensureSchema($db);
            $promptTokens = max(0, (int)($opts['prompt_tokens'] ?? 0));
            $outputTokens = max(0, (int)($opts['output_tokens'] ?? 0));
            $totalTokens = max(0, (int)($opts['total_tokens'] ?? ($promptTokens + $outputTokens)));
            $vaultTokens = max(0.0, (float)($opts['vault_tokens_spent'] ?? 0));
            $costUsd = max(0.0, (float)($opts['estimated_cost_usd'] ?? 0));
            $model = isset($opts['model']) ? substr((string)$opts['model'], 0, 80) : null;

            $stmt = $db->prepare("
                INSERT INTO api_usage_logs
                    (user_id, endpoint, model, prompt_tokens, output_tokens, total_tokens, vault_tokens_spent, estimated_cost_usd)
                VALUES
                    (:uid, :ep, :model, :pt, :ot, :tt, :vt, :cost)
            ");
            $stmt->execute([
                ':uid' => $userId,
                ':ep' => substr($endpoint, 0, 64),
                ':model' => $model,
                ':pt' => $promptTokens,
                ':ot' => $outputTokens,
                ':tt' => $totalTokens,
                ':vt' => $vaultTokens,
                ':cost' => $costUsd,
            ]);
        } catch (\Throwable $e) {
            // Never break user requests if usage logging fails
        }
    }
}
