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
        \App\Services\InviteCodeService::ensureSchema($db);
        $stmt = $db->query("SELECT id, code, is_active, max_uses, uses_count, expires_at, created_at FROM invite_codes ORDER BY created_at DESC");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $codes = array_map([\App\Services\InviteCodeService::class, 'formatRow'], $rows);

        echo json_encode(["success" => true, "codes" => $codes]);
    }

    public function generateInviteCode() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403); return;
        }

        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);
        if (!is_array($data)) {
            $data = [];
        }

        $customCode = trim((string)($data['code'] ?? ''));

        // Generate a cheeky random code if you didn't provide a custom one
        if (empty($customCode)) {
            $customCode = 'VAULT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        }

        $maxUses = null;
        $rawMaxUses = $data['max_uses'] ?? null;
        if ($rawMaxUses !== null && $rawMaxUses !== '') {
            $validatedMax = is_bool($rawMaxUses) ? false : filter_var($rawMaxUses, FILTER_VALIDATE_INT);
            if ($validatedMax === false || (int)$validatedMax < 1) {
                http_response_code(400);
                echo json_encode([
                    "success" => false,
                    "message" => "Max charges must be a whole number of at least 1 (or leave blank for unlimited)."
                ]);
                return;
            }
            $maxUses = (int)$validatedMax;
        }

        $expiresAt = null;
        $rawExpires = trim((string)($data['expires_at'] ?? ''));
        if ($rawExpires !== '') {
            $normalized = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawExpires)
                ? ($rawExpires . ' 23:59:59')
                : $rawExpires;
            $ts = strtotime($normalized);
            if ($ts === false) {
                http_response_code(400);
                echo json_encode([
                    "success" => false,
                    "message" => "Please provide a valid expiration date."
                ]);
                return;
            }
            $expiresAt = date('Y-m-d H:i:s', $ts);
        }

        $db = \App\Database::connect();
        \App\Services\InviteCodeService::ensureSchema($db);
        $stmt = $db->prepare(
            "INSERT INTO invite_codes (code, is_active, max_uses, uses_count, expires_at)
             VALUES (:code, 1, :max_uses, 0, :expires_at)"
        );
        $stmt->bindValue(':code', $customCode, PDO::PARAM_STR);
        $stmt->bindValue(':max_uses', $maxUses, $maxUses === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':expires_at', $expiresAt, $expiresAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

        try {
            $stmt->execute();
            echo json_encode([
                "success" => true,
                "code" => $customCode,
                "max_uses" => $maxUses,
                "expires_at" => $expiresAt,
                "message" => "New VIP pass minted!"
            ]);
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
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if (!$codeId) {
            http_response_code(400); return;
        }

        $db = \App\Database::connect();
        \App\Services\InviteCodeService::ensureSchema($db);
        $stmt = $db->prepare("UPDATE invite_codes SET is_active = :isActive WHERE id = :id");
        $stmt->execute([':isActive' => $isActive, ':id' => $codeId]);

        echo json_encode(["success" => true, "message" => "Code status updated."]);
    }

    public function getAdminRecipes() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo json_encode(["error" => "Admin access required"]);
            return;
        }

        $db = \App\Database::connect();
        \App\Services\RecipeVariantService::ensureSchema($db);
        $sql = "
            SELECT
                r.id, r.user_id, u.username AS author_username,
                r.parent_recipe_id, r.root_recipe_id, r.is_masterpiece, r.variant_summary,
                pr.title AS parent_recipe_title,
                r.title, r.description, r.yields, r.prep_time_mins, r.cook_time_mins,
                r.is_wfpb, r.is_oil_free, r.is_gluten_free, r.is_sugar_free, r.is_public,
                r.oil_rationale, r.notes, r.image_source, r.created_at, r.updated_at,
                m.calories, m.protein_g, m.carbs_g, m.fat_g, m.fiber_g
            FROM recipes r
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN recipes pr ON r.parent_recipe_id = pr.id
            LEFT JOIN macros m ON r.id = m.recipe_id
            ORDER BY r.id DESC
        ";
        $recipes = $db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

        $ingRows = $db->query("SELECT recipe_id, quantity, unit, ingredient_name, notes FROM ingredients ORDER BY id ASC")->fetchAll(\PDO::FETCH_ASSOC);
        $ingsByRecipe = [];
        foreach ($ingRows as $row) {
            $rid = (int)$row['recipe_id'];
            unset($row['recipe_id']);
            $ingsByRecipe[$rid][] = $row;
        }

        $instRows = $db->query("SELECT recipe_id, step_number, instruction_text FROM instructions ORDER BY step_number ASC, id ASC")->fetchAll(\PDO::FETCH_ASSOC);
        $instsByRecipe = [];
        foreach ($instRows as $row) {
            $rid = (int)$row['recipe_id'];
            unset($row['recipe_id']);
            $instsByRecipe[$rid][] = $row;
        }

        foreach ($recipes as &$r) {
            $rid = (int)$r['id'];
            $r['id'] = $rid;
            $r['parent_recipe_id'] = !empty($r['parent_recipe_id']) ? (int)$r['parent_recipe_id'] : null;
            $r['root_recipe_id'] = !empty($r['root_recipe_id']) ? (int)$r['root_recipe_id'] : null;
            $r['is_masterpiece'] = (int)($r['is_masterpiece'] ?? 0);
            $r['is_wfpb'] = (int)$r['is_wfpb'];
            $r['is_oil_free'] = (int)$r['is_oil_free'];
            $r['is_gluten_free'] = (int)$r['is_gluten_free'];
            $r['is_sugar_free'] = (int)$r['is_sugar_free'];
            $r['is_public'] = (int)$r['is_public'];
            $r['ingredients'] = $ingsByRecipe[$rid] ?? [];
            $r['instructions'] = $instsByRecipe[$rid] ?? [];
        }

        echo json_encode(["recipes" => $recipes]);
    }

    public function updateAdminRecipes() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo json_encode(["error" => "Admin access required"]);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid JSON payload"]);
            return;
        }

        $actions = $data['actions'] ?? null;
        if (!is_array($actions) && isset($data['recipe_id'], $data['updates'])) {
            $actions = [['recipe_id' => $data['recipe_id'], 'updates' => $data['updates']]];
        }

        if (!is_array($actions) || empty($actions)) {
            http_response_code(400);
            echo json_encode(["error" => "Missing actions array"]);
            return;
        }

        $allowedRecipeCols = [
            'title', 'description', 'yields', 'prep_time_mins', 'cook_time_mins',
            'is_wfpb', 'is_oil_free', 'is_gluten_free', 'is_sugar_free', 'is_public',
            'oil_rationale', 'notes', 'image_source'
        ];
        $boolCols = ['is_wfpb', 'is_oil_free', 'is_gluten_free', 'is_sugar_free', 'is_public'];
        $intCols = ['prep_time_mins', 'cook_time_mins'];
        $allowedMacroCols = ['calories', 'protein_g', 'carbs_g', 'fat_g', 'fiber_g'];

        $db = \App\Database::connect();
        $db->beginTransaction();

        try {
            $results = [];

            foreach ($actions as $act) {
                $recipeId = (int)($act['recipe_id'] ?? 0);
                $updates = $act['updates'] ?? [];
                if ($recipeId <= 0 || !is_array($updates) || empty($updates)) {
                    continue;
                }

                // Verify recipe exists
                $check = $db->prepare("SELECT id, title FROM recipes WHERE id = :id");
                $check->execute([':id' => $recipeId]);
                $existing = $check->fetch(\PDO::FETCH_ASSOC);
                if (!$existing) {
                    $results[] = ['recipe_id' => $recipeId, 'status' => 'not_found'];
                    continue;
                }

                $changedFields = [];

                // 1. Update recipes table fields
                $setClauses = [];
                $params = [':id' => $recipeId];
                foreach ($allowedRecipeCols as $col) {
                    if (array_key_exists($col, $updates)) {
                        $val = $updates[$col];
                        if (in_array($col, $boolCols, true)) {
                            $val = $val ? 1 : 0;
                        } elseif (in_array($col, $intCols, true)) {
                            $val = ($val === null || $val === '') ? null : (int)$val;
                        }
                        $setClauses[] = "$col = :$col";
                        $params[":$col"] = $val;
                        $changedFields[] = $col;
                    }
                }
                if (!empty($setClauses)) {
                    $sql = "UPDATE recipes SET " . implode(', ', $setClauses) . ", updated_at = NOW() WHERE id = :id";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                }

                // 2. Update macros table fields
                $macroSet = [];
                $macroColsPresent = [];
                $macroParams = [':recipe_id' => $recipeId];
                foreach ($allowedMacroCols as $mcol) {
                    if (array_key_exists($mcol, $updates)) {
                        $macroSet[] = "$mcol = :$mcol";
                        $macroColsPresent[] = $mcol;
                        $macroParams[":$mcol"] = ($updates[$mcol] === null || $updates[$mcol] === '') ? null : (float)$updates[$mcol];
                        $changedFields[] = $mcol;
                    }
                }
                if (!empty($macroSet)) {
                    $mCheck = $db->prepare("SELECT id FROM macros WHERE recipe_id = :rid");
                    $mCheck->execute([':rid' => $recipeId]);
                    if ($mCheck->fetch()) {
                        $mSql = "UPDATE macros SET " . implode(', ', $macroSet) . " WHERE recipe_id = :recipe_id";
                        $db->prepare($mSql)->execute($macroParams);
                    } else {
                        $cols = array_merge(['recipe_id'], $macroColsPresent);
                        $phs = array_map(fn($c) => ":$c", $cols);
                        $mSql = "INSERT INTO macros (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $phs) . ")";
                        $db->prepare($mSql)->execute($macroParams);
                    }
                }

                // 3. Replace ingredients if provided
                if (isset($updates['ingredients']) && is_array($updates['ingredients'])) {
                    $db->prepare("DELETE FROM ingredients WHERE recipe_id = :rid")->execute([':rid' => $recipeId]);
                    $ingStmt = $db->prepare(
                        "INSERT INTO ingredients (recipe_id, quantity, unit, ingredient_name, notes)
                         VALUES (:rid, :qty, :unit, :name, :notes)"
                    );
                    foreach ($updates['ingredients'] as $ing) {
                        if (is_string($ing)) {
                            $ingStmt->execute([
                                ':rid' => $recipeId,
                                ':qty' => '',
                                ':unit' => '',
                                ':name' => trim($ing),
                                ':notes' => ''
                            ]);
                        } elseif (is_array($ing)) {
                            $ingStmt->execute([
                                ':rid' => $recipeId,
                                ':qty' => (string)($ing['quantity'] ?? ''),
                                ':unit' => (string)($ing['unit'] ?? ''),
                                ':name' => (string)($ing['ingredient_name'] ?? $ing['name'] ?? ''),
                                ':notes' => (string)($ing['notes'] ?? '')
                            ]);
                        }
                    }
                    $changedFields[] = 'ingredients';
                }

                // 4. Replace instructions if provided
                if (isset($updates['instructions']) && is_array($updates['instructions'])) {
                    $db->prepare("DELETE FROM instructions WHERE recipe_id = :rid")->execute([':rid' => $recipeId]);
                    $instStmt = $db->prepare(
                        "INSERT INTO instructions (recipe_id, step_number, instruction_text)
                         VALUES (:rid, :step, :txt)"
                    );
                    $stepNum = 1;
                    foreach ($updates['instructions'] as $inst) {
                        if (is_string($inst)) {
                            $txt = trim($inst);
                            $step = $stepNum++;
                        } elseif (is_array($inst)) {
                            $txt = trim((string)($inst['instruction_text'] ?? $inst['text'] ?? ''));
                            $step = isset($inst['step_number']) ? (int)$inst['step_number'] : $stepNum++;
                        } else {
                            continue;
                        }
                        if ($txt !== '') {
                            $instStmt->execute([
                                ':rid' => $recipeId,
                                ':step' => $step,
                                ':txt' => $txt
                            ]);
                        }
                    }
                    $changedFields[] = 'instructions';
                }

                $results[] = [
                    'recipe_id' => $recipeId,
                    'title' => $updates['title'] ?? $existing['title'],
                    'status' => 'updated',
                    'changed_fields' => $changedFields
                ];
            }

            $db->commit();
            echo json_encode(["success" => true, "updated" => $results]);
        } catch (\Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(["error" => "Failed to update recipes: " . $e->getMessage()]);
        }
    }

    /**
     * Ensures admin_emma_context table exists for storing an admin's private background document.
     */
    private function ensureAdminEmmaContextTable(\PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_emma_context (
                user_id INT NOT NULL PRIMARY KEY,
                file_name VARCHAR(255) DEFAULT NULL,
                context_text MEDIUMTEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Authenticates an active admin session and returns their user ID.
     */
    private function requireAdminSessionUserId(): int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if (empty($_SESSION['is_admin']) || $userId <= 0) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Admin access required."]);
            exit;
        }
        return $userId;
    }

    /**
     * GET /admin/emma-context
     * Returns the signed-in admin's saved private Emma background context document.
     */
    public function getAdminEmmaContext(): void {
        $userId = $this->requireAdminSessionUserId();
        $db = Database::connect();
        $this->ensureAdminEmmaContextTable($db);

        $stmt = $db->prepare("SELECT file_name, context_text, CHAR_LENGTH(context_text) AS char_count, updated_at FROM admin_emma_context WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(["success" => true, "context" => null]);
            return;
        }

        echo json_encode([
            "success" => true,
            "context" => [
                "file_name" => $row['file_name'] ?: null,
                "context_text" => (string)$row['context_text'],
                "char_count" => (int)$row['char_count'],
                "updated_at" => $row['updated_at'],
            ],
        ]);
    }

    /**
     * PUT /admin/emma-context
     * Creates or replaces the signed-in admin's private Emma context document.
     */
    public function saveAdminEmmaContext(): void {
        $userId = $this->requireAdminSessionUserId();
        $data = json_decode(file_get_contents("php://input"), true) ?: [];

        $fileName = trim((string)($data['file_name'] ?? ''));
        $fileName = $fileName !== '' ? mb_substr($fileName, 0, 255) : null;
        $contextText = trim((string)($data['context_text'] ?? ''));

        if ($contextText === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Context document text cannot be empty."]);
            return;
        }

        if (mb_strlen($contextText) > 100000) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Context document must be 100,000 characters or fewer."]);
            return;
        }

        $db = Database::connect();
        $this->ensureAdminEmmaContextTable($db);

        $stmt = $db->prepare("
            INSERT INTO admin_emma_context (user_id, file_name, context_text)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                file_name = VALUES(file_name),
                context_text = VALUES(context_text)
        ");
        $stmt->execute([$userId, $fileName, $contextText]);

        $readStmt = $db->prepare("SELECT file_name, context_text, CHAR_LENGTH(context_text) AS char_count, updated_at FROM admin_emma_context WHERE user_id = ? LIMIT 1");
        $readStmt->execute([$userId]);
        $saved = $readStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "success" => true,
            "message" => "Emma's admin context document saved!",
            "context" => $saved ? [
                "file_name" => $saved['file_name'] ?: null,
                "context_text" => (string)$saved['context_text'],
                "char_count" => (int)$saved['char_count'],
                "updated_at" => $saved['updated_at'],
            ] : null,
        ]);
    }

    /**
     * DELETE /admin/emma-context
     * Removes the signed-in admin's private Emma context document.
     */
    public function deleteAdminEmmaContext(): void {
        $userId = $this->requireAdminSessionUserId();
        $db = Database::connect();
        $this->ensureAdminEmmaContextTable($db);

        $stmt = $db->prepare("DELETE FROM admin_emma_context WHERE user_id = ?");
        $stmt->execute([$userId]);

        echo json_encode([
            "success" => true,
            "message" => "Admin context document removed.",
        ]);
    }

    /**
     * GET /admin/usage
     * Returns per-user API & token usage statistics for spotting abuse or heavy usage.
     */
    public function getUsageAnalytics(): void {
        $this->requireAdminSessionUserId();
        $db = Database::connect();
        \App\Services\UsageLogService::ensureSchema($db);
        \App\Services\UsageLogService::ensureUserModerationColumns($db);

        $sql = "
            SELECT
                u.id,
                u.username,
                u.email,
                u.account_tier,
                u.is_admin,
                u.is_verified,
                u.is_suspended,
                u.suspended_reason,
                u.suspended_at,
                u.generation_tokens AS tokens_balance,
                u.created_at,
                COALESCE(l.total_requests, 0) AS total_requests,
                COALESCE(l.requests_24h, 0) AS requests_24h,
                COALESCE(l.requests_7d, 0) AS requests_7d,
                COALESCE(l.ai_chat_requests, 0) AS ai_chat_requests,
                COALESCE(l.ai_image_requests, 0) AS ai_image_requests,
                COALESCE(l.ai_live_requests, 0) AS ai_live_requests,
                COALESCE(l.prompt_tokens, 0) AS prompt_tokens,
                COALESCE(l.output_tokens, 0) AS output_tokens,
                COALESCE(l.total_ai_tokens, 0) AS total_ai_tokens,
                COALESCE(l.vault_tokens_spent, 0) AS vault_tokens_spent,
                COALESCE(l.estimated_cost_usd, 0) AS estimated_cost_usd,
                l.last_api_at,
                COALESCE(r.recipes_created, 0) AS recipes_created,
                COALESCE(c.comments_count, 0) AS comments_count
            FROM users u
            LEFT JOIN (
                SELECT
                    user_id,
                    COUNT(*) AS total_requests,
                    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS requests_24h,
                    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS requests_7d,
                    SUM(CASE WHEN endpoint = 'ai_chat' THEN 1 ELSE 0 END) AS ai_chat_requests,
                    SUM(CASE WHEN endpoint = 'ai_image' THEN 1 ELSE 0 END) AS ai_image_requests,
                    SUM(CASE WHEN endpoint = 'ai_live_token' THEN 1 ELSE 0 END) AS ai_live_requests,
                    SUM(prompt_tokens) AS prompt_tokens,
                    SUM(output_tokens) AS output_tokens,
                    SUM(total_tokens) AS total_ai_tokens,
                    SUM(vault_tokens_spent) AS vault_tokens_spent,
                    SUM(estimated_cost_usd) AS estimated_cost_usd,
                    MAX(created_at) AS last_api_at
                FROM api_usage_logs
                GROUP BY user_id
            ) l ON l.user_id = u.id
            LEFT JOIN (
                SELECT user_id, COUNT(*) AS recipes_created
                FROM recipes
                GROUP BY user_id
            ) r ON r.user_id = u.id
            LEFT JOIN (
                SELECT user_id, COUNT(*) AS comments_count
                FROM recipe_comments
                GROUP BY user_id
            ) c ON c.user_id = u.id
            ORDER BY
                COALESCE(l.estimated_cost_usd, 0) DESC,
                COALESCE(l.total_requests, 0) DESC,
                COALESCE(l.vault_tokens_spent, 0) DESC,
                COALESCE(r.recipes_created, 0) DESC,
                u.id ASC
        ";

        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $summary = [
            'total_users' => count($rows),
            'active_api_users' => 0,
            'total_requests' => 0,
            'requests_24h' => 0,
            'total_ai_tokens' => 0,
            'total_vault_tokens_spent' => 0.0,
            'total_estimated_cost_usd' => 0.0,
        ];

        $formatted = [];
        foreach ($rows as $row) {
            $reqCount = (int)$row['total_requests'];
            $req24h = (int)$row['requests_24h'];
            $aiTokens = (int)$row['total_ai_tokens'];
            $vaultSpent = round((float)$row['vault_tokens_spent'], 2);
            $estCost = round((float)$row['estimated_cost_usd'], 6);

            if ($reqCount > 0) {
                $summary['active_api_users']++;
            }
            $summary['total_requests'] += $reqCount;
            $summary['requests_24h'] += $req24h;
            $summary['total_ai_tokens'] += $aiTokens;
            $summary['total_vault_tokens_spent'] += $vaultSpent;
            $summary['total_estimated_cost_usd'] += $estCost;

            $formatted[] = [
                'id' => (int)$row['id'],
                'username' => $row['username'],
                'email' => $row['email'],
                'account_tier' => $row['account_tier'],
                'is_admin' => (bool)$row['is_admin'],
                'is_verified' => (bool)$row['is_verified'],
                'is_suspended' => (bool)($row['is_suspended'] ?? false),
                'suspended_reason' => $row['suspended_reason'] ?? null,
                'suspended_at' => $row['suspended_at'] ?? null,
                'tokens_balance' => round((float)$row['tokens_balance'], 2),
                'created_at' => $row['created_at'],
                'total_requests' => $reqCount,
                'requests_24h' => $req24h,
                'requests_7d' => (int)$row['requests_7d'],
                'ai_chat_requests' => (int)$row['ai_chat_requests'],
                'ai_image_requests' => (int)$row['ai_image_requests'],
                'ai_live_requests' => (int)$row['ai_live_requests'],
                'prompt_tokens' => (int)$row['prompt_tokens'],
                'output_tokens' => (int)$row['output_tokens'],
                'total_ai_tokens' => $aiTokens,
                'vault_tokens_spent' => $vaultSpent,
                'estimated_cost_usd' => $estCost,
                'last_api_at' => $row['last_api_at'],
                'recipes_created' => (int)$row['recipes_created'],
                'comments_count' => (int)$row['comments_count'],
            ];
        }

        $summary['total_vault_tokens_spent'] = round($summary['total_vault_tokens_spent'], 2);
        $summary['total_estimated_cost_usd'] = round($summary['total_estimated_cost_usd'], 6);

        echo json_encode([
            'success' => true,
            'summary' => $summary,
            'users' => $formatted,
        ]);
    }

    /**
     * POST /admin/users/grant-tokens
     * Dispenses or refunds generation tokens to a user found by user_id, username, or email.
     */
    public function grantUserTokens(): void {
        $adminUserId = $this->requireAdminSessionUserId();
        $data = json_decode(file_get_contents("php://input"), true) ?: [];

        $targetUserId = (int)($data['user_id'] ?? 0);
        $identifier = trim((string)($data['identifier'] ?? ''));
        $amount = round((float)($data['amount'] ?? 0), 2);
        $reason = trim((string)($data['reason'] ?? ''));

        if ($amount <= 0 || $amount > 100000) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Token amount must be between 0.01 and 100,000."]);
            return;
        }

        if ($targetUserId <= 0 && $identifier === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Please specify a user by ID, username, or email."]);
            return;
        }

        $db = Database::connect();
        if ($targetUserId > 0) {
            $stmt = $db->prepare("SELECT id, username, email, generation_tokens FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$targetUserId]);
        } else {
            $cleanIdentifier = ltrim($identifier, '@');
            $stmt = $db->prepare("SELECT id, username, email, generation_tokens FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?) LIMIT 1");
            $stmt->execute([$identifier, $cleanIdentifier]);
        }
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "No user found matching that username or email."]);
            return;
        }

        $uid = (int)$target['id'];
        $upd = $db->prepare("UPDATE users SET generation_tokens = generation_tokens + :amt WHERE id = :id");
        $upd->execute([':amt' => $amount, ':id' => $uid]);

        $balStmt = $db->prepare("SELECT generation_tokens FROM users WHERE id = ? LIMIT 1");
        $balStmt->execute([$uid]);
        $newBalance = round((float)$balStmt->fetchColumn(), 2);

        if ($uid === $adminUserId) {
            $_SESSION['generation_tokens'] = $newBalance;
        }

        // Notify the recipient in-app when tokens are granted
        try {
            $msg = "You received +{$amount} Vault token" . ($amount == 1.0 ? '' : 's') . " from an admin"
                . ($reason !== '' ? ": {$reason}" : '.');
            $notifStmt = $db->prepare("
                INSERT INTO notifications (user_id, actor_user_id, actor_username, recipe_id, comment_id, type, message)
                VALUES (:uid, :aid, :aname, 0, NULL, 'admin_token_grant', :msg)
            ");
            $notifStmt->execute([
                ':uid' => $uid,
                ':aid' => $adminUserId,
                ':aname' => $_SESSION['username'] ?? 'Admin',
                ':msg' => mb_substr($msg, 0, 250),
            ]);
        } catch (\Throwable $ignore) {
            // Non-blocking notification
        }

        echo json_encode([
            "success" => true,
            "message" => "Added +{$amount} tokens to @{$target['username']} (New balance: {$newBalance}).",
            "user_id" => $uid,
            "username" => $target['username'],
            "tokens_balance" => $newBalance,
        ]);
    }

    /**
     * PUT /admin/users/suspend
     * Suspends or unsuspends a non-admin user account.
     */
    public function toggleUserSuspension(): void {
        $adminUserId = $this->requireAdminSessionUserId();
        $data = json_decode(file_get_contents("php://input"), true) ?: [];
        $targetUserId = (int)($data['user_id'] ?? $data['id'] ?? $_GET['id'] ?? 0);
        $reason = trim((string)($data['reason'] ?? ''));

        if ($targetUserId <= 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Valid user ID is required."]);
            return;
        }

        if ($targetUserId === $adminUserId) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "You cannot suspend your own admin account."]);
            return;
        }

        $db = Database::connect();
        \App\Services\UsageLogService::ensureUserModerationColumns($db);

        $stmt = $db->prepare("SELECT id, username, is_admin, is_suspended FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$targetUserId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "User account not found."]);
            return;
        }

        if (!empty($target['is_admin'])) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Admin accounts cannot be suspended."]);
            return;
        }

        $nextSuspended = array_key_exists('suspend', $data)
            ? (bool)$data['suspend']
            : empty($target['is_suspended']);

        if ($nextSuspended) {
            $upd = $db->prepare("
                UPDATE users
                SET is_suspended = 1,
                    suspended_reason = :reason,
                    suspended_at = NOW()
                WHERE id = :id
            ");
            $upd->execute([
                ':reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                ':id' => $targetUserId,
            ]);
        } else {
            $upd = $db->prepare("
                UPDATE users
                SET is_suspended = 0,
                    suspended_reason = NULL,
                    suspended_at = NULL
                WHERE id = :id
            ");
            $upd->execute([':id' => $targetUserId]);
        }

        echo json_encode([
            "success" => true,
            "user_id" => $targetUserId,
            "is_suspended" => $nextSuspended,
            "suspended_reason" => $nextSuspended ? ($reason !== '' ? $reason : null) : null,
            "message" => $nextSuspended
                ? "Suspended @" . $target['username'] . "."
                : "Restored @" . $target['username'] . "'s account.",
        ]);
    }

    /**
     * DELETE /admin/users
     * Permanently removes a non-admin user account and associated data.
     */
    public function deleteUserAccount(): void {
        $adminUserId = $this->requireAdminSessionUserId();
        $data = json_decode(file_get_contents("php://input"), true) ?: [];
        $targetUserId = (int)($_GET['id'] ?? $data['user_id'] ?? $data['id'] ?? 0);

        if ($targetUserId <= 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Valid user ID is required."]);
            return;
        }

        if ($targetUserId === $adminUserId) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "You cannot delete your own admin account."]);
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("SELECT id, username, is_admin FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$targetUserId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "User account not found."]);
            return;
        }

        if (!empty($target['is_admin'])) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Admin accounts cannot be deleted from here."]);
            return;
        }

        try {
            $db->beginTransaction();

            $cleanupQueries = [
                "DELETE FROM api_usage_logs WHERE user_id = ?",
                "DELETE FROM notifications WHERE user_id = ? OR actor_user_id = ?",
                "DELETE FROM user_achievements WHERE user_id = ?",
                "DELETE FROM recent_chats WHERE user_id = ?",
                "DELETE FROM meal_plans WHERE user_id = ?",
                "DELETE FROM favorites WHERE user_id = ?",
                "DELETE FROM follows WHERE follower_id = ? OR followed_id = ?",
                "DELETE FROM recipe_comments WHERE user_id = ?",
                "DELETE FROM admin_emma_context WHERE user_id = ?",
                "DELETE FROM recipes WHERE user_id = ?",
            ];

            foreach ($cleanupQueries as $q) {
                try {
                    $del = $db->prepare($q);
                    $paramCount = substr_count($q, '?');
                    $del->execute($paramCount === 2 ? [$targetUserId, $targetUserId] : [$targetUserId]);
                } catch (\Throwable $ignore) {
                    // Skip optional/differently-named tables
                }
            }

            $userDel = $db->prepare("DELETE FROM users WHERE id = ?");
            $userDel->execute([$targetUserId]);
            $db->commit();

            echo json_encode([
                "success" => true,
                "message" => "Removed account @" . $target['username'] . ".",
            ]);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Failed to remove user account: " . $e->getMessage(),
            ]);
        }
    }

}

