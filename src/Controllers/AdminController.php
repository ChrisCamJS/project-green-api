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

    public function getAdminRecipes() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo json_encode(["error" => "Admin access required"]);
            return;
        }

        $db = \App\Database::connect();
        $sql = "
            SELECT
                r.id, r.user_id, u.username AS author_username,
                r.title, r.description, r.yields, r.prep_time_mins, r.cook_time_mins,
                r.is_wfpb, r.is_oil_free, r.is_gluten_free, r.is_sugar_free, r.is_public,
                r.oil_rationale, r.notes, r.image_source, r.created_at, r.updated_at,
                m.calories, m.protein_g, m.carbs_g, m.fat_g, m.fiber_g
            FROM recipes r
            LEFT JOIN users u ON r.user_id = u.id
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

}

