<?php
// public/index.php
// $origin = $_SERVER['HTTP_ORIGIN'] ?? 'http://localhost:5173';
// ==============================================================================
// 1. ORIGIN VALIDATION (THE BOUNCER)
// ==============================================================================
// Define the definitive list of VIP domains allowed to access this API.
// We only want the headless React frontend talking to this server.
$allowedOrigins = [
    'https://veggievault.chrisandemmashow.com',
    'https://www.veggievault.chrisandemmashow.com',
    'http://localhost:5173'
];

// Safely grab the incoming origin, or default to an empty string if it doesn't exist
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Check if the incoming request's origin is on our exclusive guest list
if (in_array($origin, $allowedOrigins)) {
    // If they are on the list, echo their specific origin back to allow CORS
    header("Access-Control-Allow-Origin: $origin");
} else {
    // Clean fallback to the primary domain without markdown syntax!
    header("Access-Control-Allow-Origin: https://veggievault.chrisandemmashow.com");
}

// ==============================================================================
// 2. STANDARD CORS & CONTENT PROTOCOLS
// ==============================================================================
// Allow cookies and authorization headers to be passed securely
header("Access-Control-Allow-Credentials: true");

// Set the content type to strictly JSON with UTF-8 encoding
header("Content-Type: application/json; charset=UTF-8");

// Define exactly which HTTP methods are permitted. 
// (If you don't actually need PUT or DELETE, remove them to tighten security further!)
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");

// Restrict allowed headers to only those we explicitly expect
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// ==============================================================================
// 3. EMMA'S EXTRA ARMOUR (SECURITY HEADERS)
// ==============================================================================

// Prevent MIME-sniffing: Forces browsers to respect the Content-Type we set above
header("X-Content-Type-Options: nosniff");

// Stop Clickjacking: Refuses to let this API/site be loaded within an iframe
header("X-Frame-Options: DENY");

// Enable Cross-Site Scripting (XSS) filters built into modern browsers
header("X-XSS-Protection: 1; mode=block");

// Enforce strict HTTPS connections for the next year (max-age is in seconds)
// This ensures the browser will flat out refuse an unencrypted HTTP connection.
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");

// --- Handle Preflight Requests (OPTIONS) ---
// If the browser is just sending a preflight check, exit early so the script 
// doesn't bother querying the MySQL database needlessly.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});

// Load Config & Router
require_once __DIR__ . '/../src/Database.php';
$router = require_once __DIR__ . '/../config/routes.php';

// Dispatch
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Dynamically strip the base subdirectory path from the URI
$scriptName = dirname($_SERVER['SCRIPT_NAME']); 
if (strpos($uri, $scriptName) === 0) {
    $uri = substr($uri, strlen($scriptName));
}

// Ensure the resulting URI always starts with a forward slash
if (empty($uri) || $uri[0] !== '/') {
    $uri = '/' . $uri;
}

$router->dispatch($uri, $_SERVER['REQUEST_METHOD']);