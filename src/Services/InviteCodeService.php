<?php

namespace App\Services;

use PDO;

class InviteCodeService {
    private static bool $schemaChecked = false;

    public static function ensureSchema(PDO $db): void {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $cols = $db->query("SHOW COLUMNS FROM invite_codes")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('max_uses', $cols, true)) {
                $db->exec("ALTER TABLE invite_codes ADD COLUMN max_uses INT NULL DEFAULT NULL AFTER is_active");
            }
            if (!in_array('uses_count', $cols, true)) {
                $db->exec("ALTER TABLE invite_codes ADD COLUMN uses_count INT NOT NULL DEFAULT 0 AFTER max_uses");
            }
            if (!in_array('expires_at', $cols, true)) {
                $db->exec("ALTER TABLE invite_codes ADD COLUMN expires_at DATETIME NULL DEFAULT NULL AFTER uses_count");
            }
        } catch (\Throwable $e) {
            error_log('InviteCodeService schema check warning: ' . $e->getMessage());
        }
    }

    public static function formatRow(array $row): array {
        $id = (int)($row['id'] ?? 0);
        $code = (string)($row['code'] ?? '');
        $isActive = (int)($row['is_active'] ?? 0) === 1 ? 1 : 0;
        $maxUses = ($row['max_uses'] !== null && $row['max_uses'] !== '')
            ? (int)$row['max_uses']
            : null;
        $usesCount = (int)($row['uses_count'] ?? 0);
        $expiresAt = !empty($row['expires_at']) ? (string)$row['expires_at'] : null;
        $createdAt = (string)($row['created_at'] ?? '');

        $expiresTimestamp = $expiresAt !== null ? strtotime($expiresAt) : false;
        $isExpired = $expiresTimestamp !== false && $expiresTimestamp <= time();
        $isExhausted = $maxUses !== null && $usesCount >= $maxUses;
        $remainingUses = $maxUses !== null ? max(0, $maxUses - $usesCount) : null;

        if ($isExhausted) {
            $effectiveStatus = 'exhausted';
        } elseif ($isExpired) {
            $effectiveStatus = 'expired';
        } elseif ($isActive !== 1) {
            $effectiveStatus = 'revoked';
        } else {
            $effectiveStatus = 'active';
        }

        return [
            'id' => $id,
            'code' => $code,
            'is_active' => $isActive,
            'max_uses' => $maxUses,
            'uses_count' => $usesCount,
            'expires_at' => $expiresAt,
            'created_at' => $createdAt,
            'is_expired' => $isExpired,
            'is_exhausted' => $isExhausted,
            'remaining_uses' => $remainingUses,
            'effective_status' => $effectiveStatus,
        ];
    }
}
