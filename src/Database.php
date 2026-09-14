<?php
namespace App;

class Database {
    // Hold the single instance of the database connection
    private static $pdo;

    public static function connect() {
        // Only attempt to connect if we don't already have an active PDO instance
        if (!self::$pdo) {
            
            // 1. Determine the exact path to our .env file.
            // Assuming this class is inside an 'App' or 'src' directory, 
            // we traverse up one level to reach the project root.
            $envPath = __DIR__ . '/../.env';

            // 2. Safety first! Check if the file actually exists before reading it.
            // Can't have the API throwing a tantrum if the file goes walkabout.
            if (!file_exists($envPath)) {
                http_response_code(500);
                echo json_encode(["error" => "Configuration missing. Be a dear and check the .env file."]);
                exit;
            }

            // 3. Parse the .env file. 
            // parse_ini_file reads it perfectly and returns an associative array.
            $env = parse_ini_file($envPath);

            // 4. Extract our credentials. 
            // We'll use the null coalescing operator (??) just in case a key is completely missing.
            $host = $env['DB_HOST']; 
            $db   = $env['DB_NAME'];
            $user = $env['DB_USER'];
            $pass = $env['DB_PASS'];
            $charset = 'utf8mb4'; // Standard character set for proper Unicode support

            // 5. Construct the Data Source Name (DSN) string required by PDO
            $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
            
            // 6. Set our essential PDO options
            $options = [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION, // Throw exceptions on SQL errors
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,       // Fetch rows as associative arrays (perfect for JSON)
                \PDO::ATTR_EMULATE_PREPARES   => false,                   // Let MySQL do the real prepared statements
            ];
            
            // 7. Attempt the actual connection
            try {
                 self::$pdo = new \PDO($dsn, $user, $pass, $options);
            } catch (\PDOException $e) {
                 // Catch any connection failures gracefully. 
                 // Note: Never echo $e->getMessage() to the frontend—it spills secrets!
                 http_response_code(500);
                 echo json_encode(["error" => "Database connection failed securely."]);
                 exit;
            }
        }
        
        // Return our glorious, active connection
        return self::$pdo;
    }
}