<?php
/**
 * Social Crawler / Open Graph, Twitter Card & Schema.org BlogPosting Pre-Renderer for Recipe Stories
 *
 * Served via .htaccess rewrite for `/stories/:slug` when social media crawlers
 * (facebookexternalhit, Twitterbot, Discordbot, WhatsApp, Slackbot, LinkedInBot, Pinterest, etc.)
 * request a story URL.
 */

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

$rawSlug = trim((string)($_GET['slug'] ?? ''));
$slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawSlug);

if ($slug === '') {
    http_response_code(400);
    exit('Invalid Story Slug');
}

// Detect active host (theveggievault.com primary domain)
$host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^a-zA-Z0-9.\-:]/', '', (string)$_SERVER['HTTP_HOST']) : '';
if ($host === '' || stripos($host, 'localhost') !== false || stripos($host, '127.0.0.1') !== false) {
    $siteBaseUrl = 'https://theveggievault.com';
} else {
    $siteBaseUrl = 'https://' . $host;
}

$canonicalUrl = $siteBaseUrl . '/stories/' . rawurlencode($slug);
$apiBaseUrl = 'https://theveggievault.com/pantry-rebuild-api/public';

// 1. Try querying the MySQL database directly when co-located with pantry-rebuild-api
$story = null;
$dbCandidates = [
    __DIR__ . '/../src/Database.php',
    __DIR__ . '/pantry-rebuild-api/src/Database.php',
    __DIR__ . '/../pantry-rebuild-api/src/Database.php',
    __DIR__ . '/../../pantry-rebuild-api/src/Database.php',
];

foreach ($dbCandidates as $dbFile) {
    $envFile = dirname($dbFile, 2) . '/.env';
    if (is_file($dbFile) && is_file($envFile)) {
        try {
            require_once $dbFile;
            $db = \App\Database::connect();
            $stmt = $db->prepare("
                SELECT s.id, s.recipe_id, s.user_id, s.title, s.slug, s.excerpt,
                       s.cover_image, s.content_html, s.read_time_minutes, s.status,
                       s.created_at, s.updated_at,
                       u.username AS author_name,
                       u.display_name AS author_display_name,
                       r.title AS recipe_title,
                       r.image_url AS recipe_image_url,
                       r.image_source AS recipe_image_source
                FROM recipe_stories s
                LEFT JOIN users u ON u.id = s.user_id
                LEFT JOIN recipes r ON r.id = s.recipe_id
                WHERE s.slug = ? AND s.status = 'published'
                LIMIT 1
            ");
            $stmt->execute([$slug]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $story = $row;
            }
        } catch (\Throwable $e) {
            $story = null;
        }
        break;
    }
}

// 2. Fallback to the public PHP API endpoint if direct DB lookup did not resolve
if (!$story && function_exists('curl_init')) {
    $apiUrl = $apiBaseUrl . '/stories/single?slug=' . rawurlencode($slug);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
        $decoded = json_decode($response, true);
        if (is_array($decoded) && !empty($decoded['story']) && is_array($decoded['story'])) {
            $story = $decoded['story'];
        }
    }
}

/**
 * Resolves a relative or absolute image path into a full HTTPS URL for Open Graph / Twitter cards.
 */
$resolveAbsoluteImage = static function (?string $rawPath) use ($apiBaseUrl, $siteBaseUrl): string {
    $path = trim((string)$rawPath);
    if ($path === '' || strpos($path, 'default-veggie-vault-placeholder') !== false) {
        return $siteBaseUrl . '/og-vault-preview.jpeg';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $normalizedPath = '/' . ltrim($path, '/');
    if (strpos($normalizedPath, '/images/') !== 0 && strpos($normalizedPath, '/uploads/') !== 0) {
        $normalizedPath = '/images' . $normalizedPath;
    }
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', ltrim($normalizedPath, '/'))));
    return $apiBaseUrl . '/' . $encodedPath;
};

$storyTitle = !empty($story['title']) ? trim((string)$story['title']) : 'Community Cooking Story | The Veggie Vault';
$storyExcerpt = !empty($story['excerpt'])
    ? trim((string)preg_replace('/\s+/', ' ', strip_tags((string)$story['excerpt'])))
    : 'Read this whole-food, plant-based cooking story on The Veggie Vault.';

$rawCover = trim((string)($story['cover_image'] ?? $story['coverImage'] ?? ''));
$rawRecipeImg = trim((string)($story['recipe_image_url'] ?? $story['recipeImageUrl'] ?? ($story['recipe']['imageUrl'] ?? '')));
$selectedImage = $rawCover !== '' ? $rawCover : $rawRecipeImg;
$ogImage = $resolveAbsoluteImage($selectedImage);

$authorDisplay = trim((string)($story['author_display_name'] ?? $story['authorDisplayName'] ?? ''));
$authorUsername = trim((string)($story['author_name'] ?? $story['authorName'] ?? 'Vault Chef'));
$authorName = $authorDisplay !== '' ? $authorDisplay : $authorUsername;
$authorProfileUrl = $siteBaseUrl . '/profile/' . rawurlencode($authorUsername);

$createdAtRaw = (string)($story['created_at'] ?? $story['createdAt'] ?? '');
$updatedAtRaw = (string)($story['updated_at'] ?? $story['updatedAt'] ?? $createdAtRaw);
$publishedIso = ($createdAtRaw !== '' && strtotime($createdAtRaw) !== false)
    ? date('c', strtotime($createdAtRaw))
    : date('c');
$modifiedIso = ($updatedAtRaw !== '' && strtotime($updatedAtRaw) !== false)
    ? date('c', strtotime($updatedAtRaw))
    : $publishedIso;

$readTimeMinutes = max(1, (int)($story['read_time_minutes'] ?? $story['readTimeMinutes'] ?? 1));
$recipeId = (int)($story['recipe_id'] ?? $story['recipeId'] ?? ($story['recipe']['id'] ?? 0));
$recipeTitle = trim((string)($story['recipe_title'] ?? $story['recipeTitle'] ?? ($story['recipe']['title'] ?? '')));

// Build Schema.org BlogPosting JSON-LD
$blogSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'BlogPosting',
    'mainEntityOfPage' => [
        '@type' => 'WebPage',
        '@id' => $canonicalUrl,
    ],
    'headline' => $storyTitle,
    'description' => $storyExcerpt,
    'image' => [$ogImage],
    'url' => $canonicalUrl,
    'datePublished' => $publishedIso,
    'dateModified' => $modifiedIso,
    'timeRequired' => 'PT' . $readTimeMinutes . 'M',
    'author' => [
        '@type' => 'Person',
        'name' => $authorName,
        'url' => $authorProfileUrl,
    ],
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'The Veggie Vault',
        'url' => $siteBaseUrl,
        'logo' => [
            '@type' => 'ImageObject',
            'url' => $siteBaseUrl . '/og-vault-preview.jpeg',
        ],
    ],
];

if ($recipeId > 0 && $recipeTitle !== '') {
    $blogSchema['about'] = [
        '@type' => 'Recipe',
        'name' => $recipeTitle,
        'url' => $siteBaseUrl . '/recipe/' . $recipeId,
    ];
}

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$escapedTitle = $esc($storyTitle);
$escapedDescription = $esc($storyExcerpt);
$escapedImage = $esc($ogImage);
$escapedUrl = $esc($canonicalUrl);
$escapedAuthor = $esc($authorName);
$escapedPublishedIso = $esc($publishedIso);
$escapedModifiedIso = $esc($modifiedIso);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title><?= $escapedTitle ?> | The Veggie Vault</title>
    <meta name="description" content="<?= $escapedDescription ?>" />
    <meta name="author" content="<?= $escapedAuthor ?>" />
    <meta name="robots" content="index, follow, max-image-preview:large" />
    <link rel="canonical" href="<?= $escapedUrl ?>" />

    <!-- Open Graph (Facebook, Discord, WhatsApp, Slack, LinkedIn, Pinterest) -->
    <meta property="og:type" content="article" />
    <meta property="og:site_name" content="The Veggie Vault" />
    <meta property="og:title" content="<?= $escapedTitle ?>" />
    <meta property="og:description" content="<?= $escapedDescription ?>" />
    <meta property="og:url" content="<?= $escapedUrl ?>" />
    <meta property="og:image" content="<?= $escapedImage ?>" />
    <meta property="og:image:secure_url" content="<?= $escapedImage ?>" />
    <meta property="og:image:alt" content="<?= $escapedTitle ?>" />
    <meta property="og:image:width" content="1200" />
    <meta property="og:image:height" content="630" />
    <meta property="article:published_time" content="<?= $escapedPublishedIso ?>" />
    <meta property="article:modified_time" content="<?= $escapedModifiedIso ?>" />
    <meta property="article:author" content="<?= $escapedAuthor ?>" />
    <meta property="article:section" content="Plant-Based Recipes &amp; Stories" />

    <!-- Twitter / X Cards -->
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@VeggieVault" />
    <meta name="twitter:title" content="<?= $escapedTitle ?>" />
    <meta name="twitter:description" content="<?= $escapedDescription ?>" />
    <meta name="twitter:image" content="<?= $escapedImage ?>" />
    <meta name="twitter:image:alt" content="<?= $escapedTitle ?>" />

    <!-- Schema.org BlogPosting JSON-LD -->
    <script type="application/ld+json"><?= json_encode($blogSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>
    <main>
        <article>
            <h1><?= $escapedTitle ?></h1>
            <p>By <?= $escapedAuthor ?> · <?= (int)$readTimeMinutes ?> min read</p>
            <p><?= $escapedDescription ?></p>
            <?php if ($recipeId > 0 && $recipeTitle !== ''): ?>
            <p>Inspired by Recipe: <a href="<?= $esc($siteBaseUrl . '/recipe/' . $recipeId) ?>"><?= $esc($recipeTitle) ?></a></p>
            <?php endif; ?>
            <p><a href="<?= $escapedUrl ?>">Read the full story on The Veggie Vault</a></p>
        </article>
    </main>
    <script>
        if (window.location.pathname.indexOf('story-meta.php') !== -1) {
            window.location.replace(<?= json_encode($canonicalUrl) ?>);
        }
    </script>
</body>
</html>
