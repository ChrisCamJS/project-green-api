<?php
namespace App;

class Database {
    private static $pdo;

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

            // 2. Safely attempt to parse the ini file
            $env = @parse_ini_file($envPath);
            if ($env === false) {
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
                 http_response_code(500);
                 header('Content-Type: application/json');
                 echo json_encode([
                     "error" => "PDO Connection Failed",
                     "message" => $e->getMessage(),
                     "code" => $e->getCode(),
                     "dsn" => "mysql:host=$host;dbname=$db;charset=$charset"
                 ]);
                 exit;
            }
        }
        
        return self::$pdo;
    }
}