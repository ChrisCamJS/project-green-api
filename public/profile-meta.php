<?php
/**
 * public/profile-meta.php
 * Lightweight HTML response serving dynamic Open Graph and Twitter Card tags
 * for social media bots crawling /profile/:identifier or /achievements/shared/:identifier.
 * Pointing og:image / twitter:image to /users/profile-og-image composites the chef's
 * circular avatar on top of og-vault-preview.jpeg (1200x630).
 */

$mode = ($_GET['mode'] ?? 'profile') === 'achievements' ? 'achievements' : 'profile';
$rawIdentifier = trim((string)($_GET['identifier'] ?? ''));
$identifier = ltrim($rawIdentifier, '@');
$badgeKey = trim((string)($_GET['badge_key'] ?? ''));
$badgeLevel = isset($_GET['level']) && ctype_digit((string)$_GET['level']) ? (int)$_GET['level'] : 0;

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ||
    (($_SERVER['SERVER_PORT'] ?? '') === '443')
);
$scheme = $isHttps ? 'https' : 'http';
$rawHost = $_SERVER['HTTP_HOST'] ?? 'veggievault.chrisandemmashow.com';
$host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $rawHost) ?: 'veggievault.chrisandemmashow.com';
$siteBaseUrl = "{$scheme}://{$host}";

$isLocalHost = str_contains($host, 'localhost') || str_starts_with($host, '127.0.0.1');
$apiBaseUrl = $isLocalHost
    ? "{$scheme}://{$host}/pantry-rebuild-api/public"
    : 'https://veggievault.chrisandemmashow.com/pantry-rebuild-api/public';

/**
 * Queries co-located MySQL database directly when the API .env + Database.php sit alongside the frontend.
 */
function fetchProfileFromLocalDb(string $identifier): ?array {
    if ($identifier === '') {
        return null;
    }

    $candidates = [
        dirname(__DIR__),
        __DIR__ . '/pantry-rebuild-api',
        dirname(__DIR__) . '/pantry-rebuild-api',
        dirname(__DIR__, 2) . '/pantry-rebuild-api',
    ];

    $apiRoot = null;
    foreach ($candidates as $dir) {
        if (is_file($dir . '/.env') && is_file($dir . '/src/Database.php')) {
            $apiRoot = $dir;
            break;
        }
    }

    if (!$apiRoot) {
        return null;
    }

    try {
        require_once $apiRoot . '/src/Database.php';
        $pdo = \App\Database::connect();
        if (ctype_digit($identifier)) {
            $stmt = $pdo->prepare(
                "SELECT id, username, display_name, bio, profile_image,
                        (SELECT COUNT(*) FROM recipes r WHERE r.user_id = users.id AND r.is_public = 1) AS recipe_count
                 FROM users WHERE id = ? LIMIT 1"
            );
            $stmt->execute([(int)$identifier]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT id, username, display_name, bio, profile_image,
                        (SELECT COUNT(*) FROM recipes r WHERE r.user_id = users.id AND r.is_public = 1) AS recipe_count
                 FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1"
            );
            $stmt->execute([$identifier]);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Fetches full profile payload (including earned achievements) from the public API.
 */
function fetchProfileFromApi(string $apiBaseUrl, string $identifier): ?array {
    if ($identifier === '' || !function_exists('curl_init')) {
        return null;
    }
    $url = rtrim($apiBaseUrl, '/') . '/users/profile?identifier=' . rawurlencode($identifier);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($raw) || $raw === '' || $status < 200 || $status >= 300) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded['profile'] ?? null) ? $decoded['profile'] : null;
}

$profile = null;
if ($mode === 'achievements') {
    $profile = fetchProfileFromApi($apiBaseUrl, $identifier) ?: fetchProfileFromLocalDb($identifier);
} else {
    $profile = fetchProfileFromLocalDb($identifier) ?: fetchProfileFromApi($apiBaseUrl, $identifier);
}

$username = trim((string)($profile['username'] ?? $identifier));
$displayName = trim((string)($profile['display_name'] ?? '')) ?: ($username !== '' ? $username : 'Vault Chef');
$recipeCount = (int)($profile['recipe_count'] ?? 0);

$cleanPath = $mode === 'achievements'
    ? '/achievements/shared/' . rawurlencode($identifier) . ($badgeKey !== '' && $badgeLevel > 0 ? '/' . rawurlencode($badgeKey) . '/' . $badgeLevel : '')
    : '/profile/' . rawurlencode($identifier);
$canonicalUrl = $siteBaseUrl . $cleanPath;

$earnedBadges = is_array($profile['achievements']['earned'] ?? null) ? $profile['achievements']['earned'] : [];
$spotlightBadge = null;
if ($mode === 'achievements' && $badgeKey !== '' && $badgeLevel > 0) {
    foreach ($earnedBadges as $b) {
        if (($b['key'] ?? '') === $badgeKey && (int)($b['level'] ?? 0) === $badgeLevel) {
            $spotlightBadge = $b;
            break;
        }
    }
}

if ($mode === 'achievements') {
    if ($spotlightBadge) {
        $title = "{$displayName} Unlocked \"{$spotlightBadge['level_name']}\" | The Veggie Vault";
        $description = "{$displayName} (@{$username}) earned Level {$spotlightBadge['level']} \"{$spotlightBadge['level_name']}\" ({$spotlightBadge['description']}). Start earning achievements at The Veggie Vault!";
    } else {
        $earnedCount = count($earnedBadges);
        $title = "{$displayName}'s Achievements & Badges | The Veggie Vault";
        $badgePhrase = $earnedCount > 0 ? "{$earnedCount} Whole-Food Plant-Based chef badges" : 'Whole-Food Plant-Based chef badges';
        $description = "{$displayName} (@{$username}) has earned {$badgePhrase}. Start earning achievements at The Veggie Vault!";
    }
} else {
    $title = $username !== ''
        ? "{$displayName} (@{$username}) — Chef Profile | The Veggie Vault"
        : 'Chef Profile | The Veggie Vault';
    $rawBio = trim((string)($profile['bio'] ?? ''));
    if ($rawBio !== '') {
        $normalized = preg_replace('/\s+/', ' ', strip_tags($rawBio));
        $description = function_exists('mb_substr') ? mb_substr($normalized, 0, 160) : substr($normalized, 0, 160);
    } else {
        $description = "Explore {$recipeCount} published whole-food plant-based recipes shared by {$displayName} on The Veggie Vault.";
    }
}

$ogImageQuery = 'identifier=' . rawurlencode($username !== '' ? $username : $identifier);
if ($mode === 'achievements') {
    $ogImageQuery .= '&mode=achievements';
    if ($badgeKey !== '' && $badgeLevel > 0) {
        $ogImageQuery .= '&badge_key=' . rawurlencode($badgeKey) . '&level=' . $badgeLevel;
    }
}

$ogImage = ($profile || $identifier !== '')
    ? rtrim($apiBaseUrl, '/') . '/users/profile-og-image?' . $ogImageQuery
    : "{$siteBaseUrl}/og-vault-preview.jpeg";
$imageAlt = "{$displayName} (@{$username}) on The Veggie Vault";

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'ProfilePage',
    'url' => $canonicalUrl,
    'mainEntity' => [
        '@type' => 'Person',
        'name' => $displayName,
        'alternateName' => $username !== '' ? "@{$username}" : 'Chef',
        'description' => $description,
        'image' => $ogImage,
    ],
];

$e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= $e($title) ?></title>
    <meta name="description" content="<?= $e($description) ?>" />
    <link rel="canonical" href="<?= $e($canonicalUrl) ?>" />

    <meta property="og:type" content="profile" />
    <meta property="og:site_name" content="The Veggie Vault" />
    <meta property="og:url" content="<?= $e($canonicalUrl) ?>" />
    <meta property="og:title" content="<?= $e($title) ?>" />
    <meta property="og:description" content="<?= $e($description) ?>" />
    <meta property="og:image" content="<?= $e($ogImage) ?>" />
    <meta property="og:image:secure_url" content="<?= $e($ogImage) ?>" />
    <meta property="og:image:type" content="image/jpeg" />
    <meta property="og:image:width" content="1200" />
    <meta property="og:image:height" content="630" />
    <meta property="og:image:alt" content="<?= $e($imageAlt) ?>" />
<?php if ($username !== ''): ?>
    <meta property="profile:username" content="<?= $e($username) ?>" />
<?php endif; ?>

    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="<?= $e($title) ?>" />
    <meta name="twitter:description" content="<?= $e($description) ?>" />
    <meta name="twitter:image" content="<?= $e($ogImage) ?>" />
    <meta name="twitter:image:alt" content="<?= $e($imageAlt) ?>" />

    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>
    <h1><?= $e($displayName) ?></h1>
    <p><?= $e($description) ?></p>
    <p><a href="<?= $e($canonicalUrl) ?>">View on The Veggie Vault</a></p>
</body>
</html>
