<?php

file_put_contents(__DIR__ . '/debug.log', "ID received: " . var_export($recipeId, true) . " | User-Agent: " . $_SERVER['HTTP_USER_AGENT'] . "\n", FILE_APPEND);
// Sanitize the incoming recipe ID
$recipeId = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$recipeId) {
    http_response_code(400);
    exit('Invalid Recipe ID');
}

// Build the canonical human-facing URL for the recipe
$url = "https://veggievault.chrisandemmashow.com/recipe/" . $recipeId;

// Fetch the recipe data from your PHP API
$apiUrl = "https://veggievault.chrisandemmashow.com/recipe/" . $recipeId;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
$response = curl_exec($ch);
curl_close($ch);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// TEMP DEBUG: Log this to a file or write it out if testing manually
// file_put_contents(__DIR__ . '/debug_bot.log', "HTTP: $httpCode | Response: $response\n", FILE_APPEND);

$recipe = json_decode($response, true);

// Fallback defaults if the API fails or recipe isn't found
$title = $recipe['title'] ?? 'The Veggie Vault | Plant-Based Recipes';
$description = $recipe['description'] ?? 'Discover, generate, and track wholesome, oil-free, plant-based culinary creations.';

// Extract the image path from the API response
$imagePath = $recipe['imageUrl'] ?? $recipe['image_url'] ?? '';

// If no path exists, fall back to the default preview
if (empty($imagePath)) {
    $image = 'https://veggievault.chrisandemmashow.com/og-vault-preview.jpeg';
} else {
    // If the path doesn't start with 'http', prepend the API domain to make it an absolute URL
    $image = (strpos($imagePath, 'http') === 0) ? $imagePath : "https://veggievault.chrisandemmashow.com/pantry-rebuild-api/public/images" . $imagePath;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title) ?></title>
    
    <!-- Open Graph for Facebook/WhatsApp/LinkedIn -->
    <meta property="og:type" content="article" />
    <meta property="og:title" content="<?= htmlspecialchars($title) ?>" />
    <meta property="og:description" content="<?= htmlspecialchars($description) ?>" />
    <meta property="og:image" content="<?= htmlspecialchars($image) ?>" />
    <meta property="og:image:width" content="1200" />
    <meta property="og:image:height" content="630" />
    <meta property="og:url" content="<?= htmlspecialchars($url) ?>" />
    <meta property="og:site_name" content="The Veggie Vault" />
    
    <!-- Twitter Cards for X -->
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="<?= htmlspecialchars($title) ?>" />
    <meta name="twitter:description" content="<?= htmlspecialchars($description) ?>" />
    <meta name="twitter:image" content="<?= htmlspecialchars($image) ?>" />
    <meta name="twitter:site" content="@VeggieVault" />
</head>
<body>
    <!-- Redirect human visitors to the real React route -->
    <script>window.location.replace("<?= $url ?>");</script>
</body>
</html>