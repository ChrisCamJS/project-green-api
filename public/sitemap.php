<?php
/**
 * Dynamic XML Sitemap Generator for The Veggie Vault
 *
 * Served cleanly at /sitemap.xml via .htaccess rewrite (`RewriteRule ^sitemap\.xml$ sitemap.php [L]`)
 * or directly via /sitemap.php.
 */

if (!headers_sent()) {
    header('Content-Type: application/xml; charset=UTF-8');
}

$baseUrl = 'https://veggievault.chrisandemmashow.com';
$today = date('Y-m-d');

$formatLastMod = static function (?string $rawDate) use ($today): string {
    if (!$rawDate) {
        return $today;
    }
    $ts = strtotime($rawDate);
    return $ts !== false ? date('Y-m-d', $ts) : $today;
};

$xmlEscape = static function (string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
};

$urls = [
    ['loc' => $baseUrl . '/', 'lastmod' => $today, 'changefreq' => 'daily', 'priority' => '1.0'],
    ['loc' => $baseUrl . '/wellness', 'lastmod' => $today, 'changefreq' => 'monthly', 'priority' => '0.8'],
    ['loc' => $baseUrl . '/engine', 'lastmod' => $today, 'changefreq' => 'weekly', 'priority' => '0.7'],
    ['loc' => $baseUrl . '/sitemap', 'lastmod' => $today, 'changefreq' => 'daily', 'priority' => '0.6'],
];

$recipeUrls = [];
$chefUrls = [];

$dbCandidates = [
    __DIR__ . '/../src/Database.php',
    __DIR__ . '/pantry-rebuild-api/src/Database.php',
    __DIR__ . '/../pantry-rebuild-api/src/Database.php',
    __DIR__ . '/../../pantry-rebuild-api/src/Database.php',
];

$dbConnected = false;

foreach ($dbCandidates as $dbFile) {
    $envFile = dirname($dbFile, 2) . '/.env';
    if (is_file($dbFile) && is_file($envFile)) {
        try {
            require_once $dbFile;
            $db = \App\Database::connect();
            $dbConnected = true;

            $recipeStmt = $db->query('SELECT id, updated_at, created_at FROM recipes WHERE is_public = 1 ORDER BY id DESC');
            $recipes = $recipeStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($recipes as $recipe) {
                $recipeId = (int)($recipe['id'] ?? 0);
                if ($recipeId <= 0) {
                    continue;
                }
                $recipeUrls[] = [
                    'loc' => $baseUrl . '/recipe/' . $recipeId,
                    'lastmod' => $formatLastMod($recipe['updated_at'] ?? $recipe['created_at'] ?? null),
                    'changefreq' => 'weekly',
                    'priority' => '0.9',
                ];
            }

            $chefStmt = $db->query(
                "SELECT DISTINCT u.username, MAX(COALESCE(r.updated_at, r.created_at)) AS last_mod
                 FROM users u
                 JOIN recipes r ON r.user_id = u.id
                 WHERE r.is_public = 1 AND u.username IS NOT NULL AND u.username <> ''
                 GROUP BY u.username
                 ORDER BY u.username ASC"
            );
            $chefs = $chefStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($chefs as $chef) {
                $username = trim((string)($chef['username'] ?? ''));
                if ($username === '') {
                    continue;
                }
                $chefUrls[] = [
                    'loc' => $baseUrl . '/profile/' . rawurlencode($username),
                    'lastmod' => $formatLastMod($chef['last_mod'] ?? null),
                    'changefreq' => 'weekly',
                    'priority' => '0.6',
                ];
            }
        } catch (\Throwable $e) {
            $dbConnected = false;
        }
        break;
    }
}

// Fallback to public API if direct DB access isn't co-located
if (!$dbConnected && function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . '/pantry-rebuild-api/public/recipes');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
        $decoded = json_decode($response, true);
        $list = is_array($decoded) ? ($decoded['recipes'] ?? $decoded) : [];
        $seenAuthors = [];
        if (is_array($list)) {
            foreach ($list as $recipe) {
                $recipeId = (int)($recipe['id'] ?? 0);
                if ($recipeId <= 0) {
                    continue;
                }
                $recipeUrls[] = [
                    'loc' => $baseUrl . '/recipe/' . $recipeId,
                    'lastmod' => $formatLastMod($recipe['updatedAt'] ?? $recipe['createdAt'] ?? null),
                    'changefreq' => 'weekly',
                    'priority' => '0.9',
                ];

                $author = trim((string)($recipe['authorName'] ?? ''));
                if ($author !== '' && $author !== 'Emma (AI)' && !isset($seenAuthors[$author])) {
                    $seenAuthors[$author] = true;
                    $chefUrls[] = [
                        'loc' => $baseUrl . '/profile/' . rawurlencode($author),
                        'lastmod' => $today,
                        'changefreq' => 'weekly',
                        'priority' => '0.6',
                    ];
                }
            }
        }
    }
}

$allUrls = array_merge($urls, $recipeUrls, $chefUrls);

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($allUrls as $entry) {
    echo "  <url>\n";
    echo '    <loc>' . $xmlEscape($entry['loc']) . "</loc>\n";
    echo '    <lastmod>' . $xmlEscape($entry['lastmod']) . "</lastmod>\n";
    echo '    <changefreq>' . $xmlEscape($entry['changefreq']) . "</changefreq>\n";
    echo '    <priority>' . $xmlEscape($entry['priority']) . "</priority>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";
