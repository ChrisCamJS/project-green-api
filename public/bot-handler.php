<?php
// Sanitize the incoming recipe ID
$recipeId = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$recipeId) {
    http_response_code(400);
    exit('Invalid Recipe ID');
}

// Fetch the recipe data from your PHP API
// Adjust the query parameter if your API uses something other than '?id='
$apiUrl = "https://api.veggievault.chrisandemmashow.com/recipe/" . $recipeId;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
// Adding a timeout so bots don't hang if the API is sleepy
curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
$response = curl_exec($ch);
curl_close($ch);

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
    $image = (strpos($imagePath, 'http') === 0) ? $imagePath : "https://api.veggievault.chrisandemmashow.com" . $imagePath;
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
    <!-- Humans should never see this, but just in case, we redirect them to the real React route -->
    <script>window.location.replace("<?= $url ?>");</script>
</body>
</html>