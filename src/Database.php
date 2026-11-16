<?php
namespace App;

class Database {
    private static $pdo;

    /**
     * Load key-value pairs from the project root .env file safely.
     * Normalizes leading `#` comments to `;` so parse_ini_string() handles both styles cleanly.
     */
    public static function loadEnv(): array {
        $envPath = dirname(__DIR__) . '/.env';
        if (!file_exists($envPath)) {
            return [];
        }

        $raw = @file_get_contents($envPath);
        if ($raw === false) {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", $raw);
        $normalizedLines = [];
        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if ($trimmed !== '' && $trimmed[0] === '#') {
                $normalizedLines[] = ';' . substr($trimmed, 1);
            } else {
                $normalizedLines[] = $line;
            }
        }

        $parsed = @parse_ini_string(implode("\n", $normalizedLines), false, INI_SCANNER_NORMAL);
        return is_array($parsed) ? $parsed : [];
    }

    public static function connect() {
        if (!self::$pdo) {
            // Explicitly map the path relative to src/
            $envPath = dirname(__DIR__) . '/.env';

            // 1. Check if the file actually exists and output the path it tried
            if (!file_exists($envPath)) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    "error" => "Env file missing!",
                    "tried_path" => $envPath,
                    "absolute_path_guess" => realpath(__DIR__ . '/..')
                ]);
                exit;
            }

            // 2. Safely parse the .env file
            $env = self::loadEnv();
            if (empty($env)) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    "error" => "Failed to parse .env file (syntax error or permission issue).",
                    "php_last_error" => error_get_last()
                ]);
                exit;
            }

            $host = $env['DB_HOST'] ?? null; 
            $db   = $env['DB_NAME'] ?? null;
            $user = $env['DB_USER'] ?? null;
            $pass = $env['DB_PASS'] ?? null;
            $charset = 'utf8mb4';

            $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
            
            $options = [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            
            // 3. Catch the raw PDO exception details
            try {
                 self::$pdo = new \PDO($dsn, $user, $pass, $options);
            } catch (\PDOException $e) {
                 error_log("PDO Connection Failed ({$e->getCode()}): " . $e->getMessage());
                 http_response_code(500);
                 header('Content-Type: application/json');
                 echo json_encode([
                     "error" => "Database connection failed. Please try again later."
                 ]);
                 exit;
            }
        }
        
        return self::$pdo;
    }
}