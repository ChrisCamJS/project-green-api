<?php
/**
 * Social Crawler / Open Graph Preview Handler for Individual Recipes
 *
 * Serves recipe-specific Open Graph and Twitter/X Card meta tags to social
 * media crawlers, while redirecting any accidental human visitors to the SPA.
 */

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

// Sanitize the incoming recipe ID (supporting both HTTP GET and CLI test invocation)
$rawId = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if ($rawId === null || $rawId === false || $rawId === '') {
    $rawId = $_GET['id'] ?? '';
}
$recipeId = (int)preg_replace('/[^0-9]/', '', (string)$rawId);

if ($recipeId <= 0) {
    http_response_code(400);
    exit('Invalid Recipe ID');
}

// Build the canonical human-facing URL for the recipe
$url = 'https://veggievault.chrisandemmashow.com/recipe/' . $recipeId;

/**
 * Normalizes and truncates recipe descriptions for Open Graph / Twitter cards.
 */
function cleanMetaDescription(string $text): string
{
    if (stripos($text, "### Emma's Deep Dive") !== false) {
        $parts = preg_split("/###\s*Emma'?s Deep Dive/i", $text, 2);
        $text = $parts[0] ?? $text;
    }

    // Strip markdown links, headings, list markers, and emphasis tokens
    $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $text);
    $text = preg_replace('/^#{1,6}\s*/m', '', (string)$text);
    $text = preg_replace('/^\s*[-*+]\s+/m', '', (string)$text);
    $text = str_replace(['**', '__', '~~', '`', '*'], '', (string)$text);
    $text = preg_replace('/(?<!\w)_+|_+(?!\w)/', '', (string)$text);
    $text = trim((string)preg_replace('/\s+/', ' ', strip_tags((string)$text)));

    if ($text === '') {
        return 'Discover, generate, and track wholesome, oil-free, plant-based culinary creations.';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr') && function_exists('mb_strrpos')) {
        if (mb_strlen($text, 'UTF-8') > 220) {
            $truncated = mb_substr($text, 0, 217, 'UTF-8');
            $lastSpace = mb_strrpos($truncated, ' ', 0, 'UTF-8');
            if ($lastSpace !== false && $lastSpace > 150) {
                $truncated = mb_substr($truncated, 0, $lastSpace, 'UTF-8');
            }
            return rtrim($truncated, " \t\n\r\0\x0B.,;:-") . '...';
        }
    } elseif (strlen($text) > 220) {
        $truncated = substr($text, 0, 217);
        $lastSpace = strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > 150) {
            $truncated = substr($truncated, 0, $lastSpace);
        }
        return rtrim($truncated, " \t\n\r\0\x0B.,;:-") . '...';
    }

    return $text;
}

// 1. Try querying the database directly when co-located with pantry-rebuild-api
$recipe = null;
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
            $stmt = $db->prepare('SELECT title, description, image_url, image_source FROM recipes WHERE id = ? LIMIT 1');
            $stmt->execute([$recipeId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $recipe = $row;
            }
        } catch (\Throwable $e) {
            $recipe = null;
        }
        break;
    }
}

// 2. Fallback to the public PHP API endpoint if direct DB lookup did not resolve
if (!$recipe && function_exists('curl_init')) {
    $apiUrl = 'https://veggievault.chrisandemmashow.com/pantry-rebuild-api/public/recipes/single?id=' . urlencode((string)$recipeId);

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
        if (is_array($decoded) && !isset($decoded['error'])) {
            $recipe = $decoded;
        }
    }
}

// 3. Extract metadata with safe defaults
$title = !empty($recipe['title']) ? trim((string)$recipe['title']) : 'The Veggie Vault | Plant-Based Recipes';
$rawDescription = !empty($recipe['description'])
    ? (string)$recipe['description']
    : 'Discover, generate, and track wholesome, oil-free, plant-based culinary creations.';
$description = cleanMetaDescription($rawDescription);

$imagePath = trim((string)($recipe['imageUrl'] ?? $recipe['image_url'] ?? ''));
$imageSource = trim((string)($recipe['image_source'] ?? $recipe['imageSource'] ?? ''));

if (
    $imagePath === '' ||
    strpos($imagePath, 'default-veggie-vault-placeholder') !== false ||
    $imageSource === 'none'
) {
    $image = 'https://veggievault.chrisandemmashow.com/og-vault-preview.jpeg';
} elseif (preg_match('#^https?://#i', $imagePath)) {
    $image = $imagePath;
} else {
    $normalizedPath = '/' . ltrim($imagePath, '/');
    if (strpos($normalizedPath, '/images/') !== 0 && strpos($normalizedPath, '/uploads/') !== 0) {
        $normalizedPath = '/images' . $normalizedPath;
    }
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', ltrim($normalizedPath, '/'))));
    $image = 'https://veggievault.chrisandemmashow.com/pantry-rebuild-api/public/' . $encodedPath;
}

$escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
$escapedDescription = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
$escapedImage = htmlspecialchars($image, ENT_QUOTES, 'UTF-8');
$escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title><?= $escapedTitle ?> | The Veggie Vault</title>

    <!-- Open Graph for Facebook/WhatsApp/LinkedIn/Discord/Slack -->
    <meta property="og:type" content="article" />
    <meta property="og:site_name" content="The Veggie Vault" />
    <meta property="og:title" content="<?= $escapedTitle ?>" />
    <meta property="og:description" content="<?= $escapedDescription ?>" />
    <meta property="og:image" content="<?= $escapedImage ?>" />
    <meta property="og:image:secure_url" content="<?= $escapedImage ?>" />
    <meta property="og:image:alt" content="<?= $escapedTitle ?>" />
    <meta property="og:image:width" content="1200" />
    <meta property="og:image:height" content="630" />
    <meta property="og:url" content="<?= $escapedUrl ?>" />

    <!-- Twitter / X Cards -->
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@VeggieVault" />
    <meta name="twitter:title" content="<?= $escapedTitle ?>" />
    <meta name="twitter:description" content="<?= $escapedDescription ?>" />
    <meta name="twitter:image" content="<?= $escapedImage ?>" />
    <meta name="twitter:image:alt" content="<?= $escapedTitle ?>" />
</head>
<body>
    <!-- Redirect human visitors to the real React route -->
    <p>Redirecting to <a href="<?= $escapedUrl ?>"><?= $escapedTitle ?></a>...</p>
    <script>window.location.replace(<?= json_encode($url) ?>);</script>
</body>
</html>
