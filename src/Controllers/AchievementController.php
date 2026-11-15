<?php
namespace App\Controllers;

use App\Database;
use PDO;

class AchievementController {

    public static function getCatalog(): array {
        return [
            'masterpieces' => [
                'title' => 'Masterpiece Chef',
                'icon' => '🍳',
                'unit' => 'published recipes',
                'levels' => [
                    1 => ['name' => 'First Spark', 'target' => 1, 'desc' => 'Publish your first recipe'],
                    2 => ['name' => 'Vault Artisan', 'target' => 10, 'desc' => 'Publish 10 recipes'],
                    3 => ['name' => 'Culinary Maestro', 'target' => 25, 'desc' => 'Publish 25 recipes'],
                    4 => ['name' => 'Plant-Based Legend', 'target' => 50, 'desc' => 'Publish 50 recipes'],
                ],
            ],
            'comments' => [
                'title' => 'Community Chatterbox',
                'icon' => '💬',
                'unit' => 'comments posted',
                'levels' => [
                    1 => ['name' => 'Icebreaker', 'target' => 1, 'desc' => 'Post your first recipe comment'],
                    2 => ['name' => 'Friendly Critic', 'target' => 10, 'desc' => 'Comment on 10 recipes'],
                    3 => ['name' => 'Vault Conversationalist', 'target' => 25, 'desc' => 'Post 25 comments'],
                    4 => ['name' => 'Community Heartbeat', 'target' => 50, 'desc' => 'Post 50 comments'],
                ],
            ],
            'meals_planned' => [
                'title' => 'Meal Prep Strategist',
                'icon' => '📅',
                'unit' => 'meals planned',
                'levels' => [
                    1 => ['name' => 'Planner Apprentice', 'target' => 5, 'desc' => 'Plan 5 meals on the calendar'],
                    2 => ['name' => 'Weekly Architect', 'target' => 20, 'desc' => 'Plan 20 meals'],
                    3 => ['name' => 'Nutrition Tactician', 'target' => 50, 'desc' => 'Plan 50 meals'],
                    4 => ['name' => 'Master Meal Planner', 'target' => 100, 'desc' => 'Plan 100 meals'],
                ],
            ],
            'fitness_sessions' => [
                'title' => 'Active Explorer',
                'icon' => '🏃',
                'unit' => 'workouts logged',
                'levels' => [
                    1 => ['name' => 'First Stride', 'target' => 1, 'desc' => 'Log your first fitness activity'],
                    2 => ['name' => 'Momentum Builder', 'target' => 10, 'desc' => 'Log 10 fitness sessions'],
                    3 => ['name' => 'Endurance Regular', 'target' => 30, 'desc' => 'Log 30 fitness sessions'],
                    4 => ['name' => 'Unstoppable Mover', 'target' => 75, 'desc' => 'Log 75 fitness sessions'],
                ],
            ],
            'fitness_minutes' => [
                'title' => 'Calorie Crusher',
                'icon' => '🔥',
                'unit' => 'active minutes',
                'levels' => [
                    1 => ['name' => 'Hour of Power', 'target' => 60, 'desc' => 'Log 60 total active minutes'],
                    2 => ['name' => '5-Hour Club', 'target' => 300, 'desc' => 'Log 300 total active minutes'],
                    3 => ['name' => '1,000-Minute Milestone', 'target' => 1000, 'desc' => 'Log 1,000 total active minutes'],
                    4 => ['name' => 'Iron Will', 'target' => 2500, 'desc' => 'Log 2,500 total active minutes'],
                ],
            ],
            'following' => [
                'title' => 'Vault Supporter',
                'icon' => '🤝',
                'unit' => 'chefs followed',
                'levels' => [
                    1 => ['name' => 'First Follow', 'target' => 1, 'desc' => 'Follow your first chef'],
                    2 => ['name' => 'Kitchen Circle', 'target' => 5, 'desc' => 'Follow 5 chefs'],
                    3 => ['name' => 'Community Connector', 'target' => 15, 'desc' => 'Follow 15 chefs'],
                    4 => ['name' => 'Vault Ambassador', 'target' => 30, 'desc' => 'Follow 30 chefs'],
                ],
            ],
        ];
    }

    public static function syncAndFetch(PDO $db, int $userId): array {
        if ($userId <= 0) {
            return ['earned' => [], 'tracks' => []];
        }

        $counts = [
            'masterpieces' => 0,
            'comments' => 0,
            'meals_planned' => 0,
            'fitness_sessions' => 0,
            'fitness_minutes' => 0,
            'following' => 0,
        ];

        try {
            $s = $db->prepare("SELECT COUNT(*) FROM recipes WHERE user_id = ? AND is_public = 1");
            $s->execute([$userId]);
            $counts['masterpieces'] = (int)$s->fetchColumn();

            $s = $db->prepare("SELECT COUNT(*) FROM recipe_comments WHERE user_id = ?");
            $s->execute([$userId]);
            $counts['comments'] = (int)$s->fetchColumn();

            $s = $db->prepare("SELECT COUNT(*) FROM meal_plan_entries WHERE user_id = ?");
            $s->execute([$userId]);
            $counts['meals_planned'] = (int)$s->fetchColumn();

            $s = $db->prepare("SELECT COUNT(*), COALESCE(SUM(duration_mins), 0) FROM fitness_activities WHERE user_id = ?");
            $s->execute([$userId]);
            $fitRow = $s->fetch(PDO::FETCH_NUM);
            $counts['fitness_sessions'] = (int)($fitRow[0] ?? 0);
            $counts['fitness_minutes'] = (int)($fitRow[1] ?? 0);

            $s = $db->prepare("SELECT COUNT(*) FROM user_follows WHERE follower_id = ?");
            $s->execute([$userId]);
            $counts['following'] = (int)$s->fetchColumn();
        } catch (\Exception $e) {
            // Gracefully handle if a table isn't present yet
        }

        $catalog = self::getCatalog();

        try {
            $ins = $db->prepare("INSERT IGNORE INTO user_achievements (user_id, achievement_key, level) VALUES (?, ?, ?)");
            foreach ($catalog as $key => $meta) {
                $val = $counts[$key] ?? 0;
                foreach ($meta['levels'] as $lvl => $lvlMeta) {
                    if ($val >= $lvlMeta['target']) {
                        $ins->execute([$userId, $key, (int)$lvl]);
                    }
                }
            }

            $q = $db->prepare("SELECT achievement_key, level, earned_at FROM user_achievements WHERE user_id = ? ORDER BY earned_at DESC, level DESC");
            $q->execute([$userId]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $rows = [];
        }

        $earnedMap = [];
        $earned = [];
        foreach ($rows as $r) {
            $k = $r['achievement_key'];
            $lvl = (int)$r['level'];
            if (!isset($catalog[$k]['levels'][$lvl])) continue;
            $earnedMap[$k][$lvl] = $r['earned_at'];
            $earned[] = [
                'key' => $k,
                'level' => $lvl,
                'title' => $catalog[$k]['title'],
                'level_name' => $catalog[$k]['levels'][$lvl]['name'],
                'icon' => $catalog[$k]['icon'],
                'description' => $catalog[$k]['levels'][$lvl]['desc'],
                'target' => $catalog[$k]['levels'][$lvl]['target'],
                'earned_at' => $r['earned_at'],
            ];
        }

        $tracks = [];
        foreach ($catalog as $k => $meta) {
            $val = $counts[$k] ?? 0;
            $highestLevel = 0;
            $highestEarnedAt = null;
            $nextLevel = null;
            $nextTarget = null;
            $nextName = null;

            foreach ($meta['levels'] as $lvl => $lvlMeta) {
                if (!empty($earnedMap[$k][$lvl])) {
                    $highestLevel = $lvl;
                    $highestEarnedAt = $earnedMap[$k][$lvl];
                } elseif ($nextLevel === null) {
                    $nextLevel = $lvl;
                    $nextTarget = $lvlMeta['target'];
                    $nextName = $lvlMeta['name'];
                }
            }

            $tracks[] = [
                'key' => $k,
                'title' => $meta['title'],
                'icon' => $meta['icon'],
                'unit' => $meta['unit'],
                'current_value' => $val,
                'highest_level' => $highestLevel,
                'max_level' => count($meta['levels']),
                'highest_level_name' => $highestLevel > 0 ? $meta['levels'][$highestLevel]['name'] : null,
                'highest_earned_at' => $highestEarnedAt,
                'next_level' => $nextLevel,
                'next_target' => $nextTarget,
                'next_level_name' => $nextName,
            ];
        }

        return [
            'earned' => $earned,
            'tracks' => $tracks,
            'total_badges' => count($earned),
        ];
    }

    public function getMyAchievements() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            http_response_code(401);
            echo json_encode(["error" => "Authentication required"]);
            return;
        }
        $db = Database::connect();
        echo json_encode(self::syncAndFetch($db, $userId));
    }
}
