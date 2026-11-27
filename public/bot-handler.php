<?php
/**
 * Social Crawler / Open Graph & Schema.org Preview Handler for Individual Recipes
 *
 * Serves recipe-specific Open Graph, Twitter/X Card, and Recipe JSON-LD metadata
 * to social media crawlers, while redirecting direct bot-handler.php visits to the SPA.
 */

ini_set('display_errors', '0');
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

$profileIdentifier = trim((string)($_GET['profile'] ?? ''));
if ($profileIdentifier !== '') {
    $profileRow = null;
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
                if (ctype_digit($profileIdentifier)) {
                    $stmt = $db->prepare('SELECT id, username, display_name, bio, profile_image FROM users WHERE id = ? LIMIT 1');
                    $stmt->execute([(int)$profileIdentifier]);
                } else {
                    $stmt = $db->prepare('SELECT id, username, display_name, bio, profile_image FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
                    $stmt->execute([$profileIdentifier]);
                }
                $profileRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            } catch (\Throwable $e) {
                $profileRow = null;
            }
            break;
        }
    }

    $safeSlug = rawurlencode($profileRow['username'] ?? $profileIdentifier);
    $profileUrl = 'https://theveggievault.com/profile/' . $safeSlug;
    $displayName = trim((string)($profileRow['display_name'] ?? ''));
    $username = trim((string)($profileRow['username'] ?? $profileIdentifier));
    $ogTitle = ($displayName !== '' ? $displayName . ' (@' . $username . ')' : '@' . $username) . ' — Chef Profile | The Veggie Vault';
    $ogDesc = trim((string)($profileRow['bio'] ?? ''));
    if ($ogDesc === '') {
        $ogDesc = 'Explore published whole-food, oil-free, plant-based recipes by @' . $username . ' on The Veggie Vault.';
    }
    $ogImg = 'https://theveggievault.com/pantry-rebuild-api/public/users/profile-og-image?identifier=' . $safeSlug;

    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $schema = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ProfilePage',
        'url' => $profileUrl,
        'mainEntity' => [
            '@type' => 'Person',
            'name' => $displayName !== '' ? $displayName : $username,
            'alternateName' => '@' . $username,
            'description' => $ogDesc,
            'image' => $ogImg,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    echo "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n";
    echo "<title>" . $esc($ogTitle) . "</title>\n";
    echo "<link rel=\"canonical\" href=\"" . $esc($profileUrl) . "\" />\n";
    echo "<meta name=\"description\" content=\"" . $esc($ogDesc) . "\" />\n";
    echo "<meta property=\"og:type\" content=\"profile\" />\n";
    echo "<meta property=\"og:site_name\" content=\"The Veggie Vault\" />\n";
    echo "<meta property=\"og:title\" content=\"" . $esc($ogTitle) . "\" />\n";
    echo "<meta property=\"og:description\" content=\"" . $esc($ogDesc) . "\" />\n";
    echo "<meta property=\"og:url\" content=\"" . $esc($profileUrl) . "\" />\n";
    echo "<meta property=\"og:image\" content=\"" . $esc($ogImg) . "\" />\n";
    echo "<meta name=\"twitter:card\" content=\"summary_large_image\" />\n";
    echo "<meta name=\"twitter:title\" content=\"" . $esc($ogTitle) . "\" />\n";
    echo "<meta name=\"twitter:description\" content=\"" . $esc($ogDesc) . "\" />\n";
    echo "<meta name=\"twitter:image\" content=\"" . $esc($ogImg) . "\" />\n";
    echo "<script type=\"application/ld+json\">" . $schema . "</script>\n";
    echo "</head>\n<body><p>Redirecting to <a href=\"" . $esc($profileUrl) . "\">" . $esc($ogTitle) . "</a>...</p></body>\n</html>";
    exit;
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
$url = 'https://theveggievault.com/recipe/' . $recipeId;

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
            $ogServiceFile = dirname($dbFile, 2) . '/src/Services/OgImageService.php';
            if (is_file($ogServiceFile)) {
                require_once $ogServiceFile;
            }
            $db = \App\Database::connect();
            if (class_exists('\App\Services\OgImageService')) {
                \App\Services\OgImageService::warmAllOgImages($db);
            }
            $stmt = $db->prepare(
                'SELECT r.id, r.title, r.description, r.image_url, r.image_source, r.yields,
                        r.prep_time_mins, r.cook_time_mins, r.is_oil_free, r.is_gluten_free,
                        r.is_sugar_free, r.average_rating, r.rating_count, r.created_at,
                        r.parent_recipe_id, r.is_masterpiece,
                        u.username AS author_name
                 FROM recipes r
                 LEFT JOIN users u ON u.id = r.user_id
                 WHERE r.id = ? LIMIT 1'
            );
            $stmt->execute([$recipeId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $ingStmt = $db->prepare('SELECT ingredient_name FROM ingredients WHERE recipe_id = ?');
                $ingStmt->execute([$recipeId]);
                $row['ingredients'] = $ingStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

                $instStmt = $db->prepare('SELECT step_number, instruction_text FROM instructions WHERE recipe_id = ? ORDER BY step_number ASC');
                $instStmt->execute([$recipeId]);
                $row['instructions'] = $instStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

                $macroStmt = $db->prepare('SELECT calories, protein_g, carbs_g, fat_g, fiber_g FROM macros WHERE recipe_id = ? LIMIT 1');
                $macroStmt->execute([$recipeId]);
                $row['macros'] = $macroStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

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
    $apiUrl = 'https://theveggievault.com/pantry-rebuild-api/public/recipes/single?id=' . urlencode((string)$recipeId);

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
    $image = 'https://theveggievault.com/og-vault-preview.jpg';
} elseif (preg_match('#^https?://#i', $imagePath)) {
    $image = $imagePath;
} else {
    $normalizedPath = '/' . ltrim($imagePath, '/');
    if (strpos($normalizedPath, '/images/') !== 0 && strpos($normalizedPath, '/uploads/') !== 0) {
        $normalizedPath = '/images' . $normalizedPath;
    }
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', ltrim($normalizedPath, '/'))));
    $image = 'https://theveggievault.com/pantry-rebuild-api/public/' . $encodedPath;
}

$isVariantRecipe = !empty($recipe['parent_recipe_id']) || !empty($recipe['parentRecipeId']);

if ($isVariantRecipe) {
    $image = 'https://theveggievault.com/pantry-rebuild-api/public/recipes/variant-og-image?id=' . $recipeId;
}

if (!empty($recipe) && class_exists('\App\Services\OgImageService')) {
    $staticOgUrl = \App\Services\OgImageService::ensureCachedRecipeOgUrl($recipe);
    if ($staticOgUrl !== null) {
        $image = $staticOgUrl;
    }
}

// 4. Build Schema.org Recipe JSON-LD when recipe details exist
$recipeSchema = null;
if (!empty($recipe['title'])) {
    $prepMins = max(0, (int)($recipe['prep_time_mins'] ?? $recipe['prepTime'] ?? 0));
    $cookMins = max(0, (int)($recipe['cook_time_mins'] ?? $recipe['cookTime'] ?? 0));
    $totalMins = $prepMins + $cookMins;

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Recipe',
        'name' => $title,
        'description' => $description,
        'url' => $url,
        'image' => [$image],
        'author' => [
            '@type' => 'Person',
            'name' => !empty($recipe['authorName']) ? $recipe['authorName'] : ($recipe['author_name'] ?? 'Emma (AI)'),
        ],
        'recipeCategory' => 'Plant-Based',
        'suitableForDiet' => ['https://schema.org/VeganDiet', 'https://schema.org/VegetarianDiet'],
    ];

    if (!empty($recipe['created_at'])) {
        $ts = strtotime((string)$recipe['created_at']);
        if ($ts !== false) {
            $schema['datePublished'] = date('c', $ts);
        }
    }
    if (!empty($recipe['yields'])) {
        $schema['recipeYield'] = (string)$recipe['yields'];
    }
    if ($prepMins > 0) {
        $schema['prepTime'] = 'PT' . $prepMins . 'M';
    }
    if ($cookMins > 0) {
        $schema['cookTime'] = 'PT' . $cookMins . 'M';
    }
    if ($totalMins > 0) {
        $schema['totalTime'] = 'PT' . $totalMins . 'M';
    }

    if (!empty($recipe['is_gluten_free']) || !empty($recipe['isGlutenFree'])) {
        $schema['suitableForDiet'][] = 'https://schema.org/GlutenFreeDiet';
    }

    if (!empty($recipe['ingredients']) && is_array($recipe['ingredients'])) {
        $ingList = [];
        foreach ($recipe['ingredients'] as $ing) {
            $name = is_array($ing) ? trim((string)($ing['ingredient_name'] ?? $ing['name'] ?? '')) : trim((string)$ing);
            if ($name !== '') {
                $ingList[] = $name;
            }
        }
        if (!empty($ingList)) {
            $schema['recipeIngredient'] = $ingList;
        }
    }

    if (!empty($recipe['instructions']) && is_array($recipe['instructions'])) {
        $stepList = [];
        foreach ($recipe['instructions'] as $idx => $step) {
            $text = is_array($step) ? trim((string)($step['instruction_text'] ?? $step['text'] ?? '')) : trim((string)$step);
            if ($text !== '') {
                $stepList[] = [
                    '@type' => 'HowToStep',
                    'position' => is_array($step) && !empty($step['step_number']) ? (int)$step['step_number'] : ($idx + 1),
                    'text' => $text,
                ];
            }
        }
        if (!empty($stepList)) {
            $schema['recipeInstructions'] = $stepList;
        }
    }

    if (!empty($recipe['macros']) && is_array($recipe['macros'])) {
        $m = $recipe['macros'];
        $schema['nutrition'] = array_filter([
            '@type' => 'NutritionInformation',
            'calories' => isset($m['calories']) ? ((float)$m['calories'] . ' calories') : null,
            'proteinContent' => isset($m['protein_g']) ? ((float)$m['protein_g'] . ' g') : null,
            'carbohydrateContent' => isset($m['carbs_g']) ? ((float)$m['carbs_g'] . ' g') : null,
            'fatContent' => isset($m['fat_g']) ? ((float)$m['fat_g'] . ' g') : null,
            'fiberContent' => isset($m['fiber_g']) ? ((float)$m['fiber_g'] . ' g') : null,
        ]);
    }

    $ratingCount = (int)($recipe['rating_count'] ?? $recipe['ratingCount'] ?? 0);
    $avgRating = (float)($recipe['average_rating'] ?? $recipe['averageRating'] ?? 0);
    if ($ratingCount > 0 && $avgRating > 0) {
        $schema['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => round($avgRating, 2),
            'ratingCount' => $ratingCount,
            'bestRating' => 5,
            'worstRating' => 1,
        ];
    }

    $recipeSchema = $schema;
}

$imageExt = strtolower((string)pathinfo((string)(parse_url($image, PHP_URL_PATH) ?: $image), PATHINFO_EXTENSION));
$imageMime = match ($imageExt) {
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    default => 'image/jpeg',
};

$escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
$escapedDescription = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
$escapedImage = htmlspecialchars($image, ENT_QUOTES, 'UTF-8');
$escapedImageType = htmlspecialchars($imageMime, ENT_QUOTES, 'UTF-8');
$escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title><?= $escapedTitle ?> | The Veggie Vault</title>
    <meta name="description" content="<?= $escapedDescription ?>" />
    <meta name="robots" content="index, follow" />
    <link rel="canonical" href="<?= $escapedUrl ?>" />

    <!-- Open Graph for Facebook/WhatsApp/LinkedIn/Discord/Slack -->
    <meta property="og:type" content="article" />
    <meta property="og:site_name" content="The Veggie Vault" />
    <meta property="og:title" content="<?= $escapedTitle ?>" />
    <meta property="og:description" content="<?= $escapedDescription ?>" />
    <meta property="og:image" content="<?= $escapedImage ?>" />
    <meta property="og:image:secure_url" content="<?= $escapedImage ?>" />
    <meta property="og:image:type" content="<?= $escapedImageType ?>" />
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

    <?php if ($recipeSchema): ?>
    <script type="application/ld+json"><?= json_encode($recipeSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endif; ?>
</head>
<body>
    <main>
        <h1><?= $escapedTitle ?></h1>
        <p><?= $escapedDescription ?></p>
        <p><a href="<?= $escapedUrl ?>">View full recipe on The Veggie Vault</a></p>
    </main>
    <script>
        if (window.location.pathname.indexOf('bot-handler.php') !== -1) {
            window.location.replace(<?= json_encode($url) ?>);
        }
    </script>
</body>
</html>
