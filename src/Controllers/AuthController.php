<?php

namespace App\Controllers;

use App\Database;
use PDO;

class AuthController {

   public function login() {
        // start the session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $db = Database::connect(); // Relying on your trusty PDO connection setup

        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);

        if (!is_array($data)) {
            $data = [];
        }

        // Swapping out the username check for email here
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(["message" => "Don't be cheeky! Provide both an Email and a Password."]);
            return;
        }

        // Updated query to check against the email column instead of username
        $sql = "SELECT id, username, email, password_hash, is_admin, account_tier, generation_tokens FROM users WHERE email = :email LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // verify the user exists and the password matches the hash
        if ($user && password_verify($password, $user['password_hash'])) {
            // strip the hash before sending the data back to the front-end (safety first!)
            unset($user['password_hash']);

            // Save user info in the session (keeping username for your recipe cards!)
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email']; 
            $_SESSION['is_admin'] = $user['is_admin'];
            $_SESSION['account_tier'] = $user['account_tier'];
            $_SESSION['generation_tokens'] = $user['generation_tokens'];

            // We send the whole $user array back
            echo json_encode([
                "success" => true,
                "message" => "Welcome to the vault",
                "user" => $user 
            ]);
        }
        else {
            // Keep the error vague for security
            http_response_code(401);
            echo json_encode(["success" => false, "message" => "Invalid credentials, love."]);
        }
    }
    public function logout() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();
        echo json_encode(["success" => true, "message" => "Logged out successfully."]);
    }
/**
     * Deduct generation tokens from the active user's account.
     * Supports fractional token costs (e.g., 0.1 for Deep Dives).
     */
    public function deductToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $userId = $_SESSION['user_id'] ?? null;

        if (!$userId) {
            http_response_code(401);
            echo json_encode(["success" => false, "message" => "Who are you? Please log in."]);
            return;
        }

        // --- NEW: Catch the dynamic cost from React! ---
        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);
        
        // Default to 1 token if no cost is explicitly sent
        $cost = isset($data['cost']) ? (float)$data['cost'] : 1.0;

        // Cheeky safety check to prevent negative costs (users paying themselves!)
        if ($cost <= 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid token amount."]);
            return;
        }

        $db = Database::connect();

        // First, check their current balance
        $checkSql = "SELECT generation_tokens FROM users WHERE id = :id";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([':id' => $userId]);
        $currentTokens = (float)$checkStmt->fetchColumn();

        // Make sure they have enough for THIS specific action
        if ($currentTokens < $cost) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Oh dear! Your token stash is a bit too light for this. Time to top up!"]);
            return;
        }

        // Deduct the exact cost
        $updateSql = "UPDATE users SET generation_tokens = generation_tokens - :cost WHERE id = :id";
        $updateStmt = $db->prepare($updateSql);
        $success = $updateStmt->execute([':cost' => $cost, ':id' => $userId]);

        if ($success) {
            // Calculate the new balance to send back to React
            $newBalance = $currentTokens - $cost;
            
            // Keep the PHP session data accurate too
            $_SESSION['generation_tokens'] = $newBalance;
            
            echo json_encode(["success" => true, "tokensRemaining" => $newBalance, "message" => "Tokens deducted."]);
        } else {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Failed to deduct token. Lucky you!"]);
        }
    }

    // public function emergencyReset() {
    //     // Hardcode the target credentials here to keep it strictly locked down
    //     $target_username = 'sandeejames2005@yahoo.com'; 
    //     $new_password = 'Vegan@50309';

    //     // Connect to MySQL
    //     $db = \App\Database::connect();

    //     // Generate the required Bcrypt hash
    //     $hash = password_hash($new_password, PASSWORD_DEFAULT);
        
    //     $sql = "UPDATE users SET password_hash = :hash WHERE username = :username";
    //     $stmt = $db->prepare($sql);
    //     $success = $stmt->execute([':hash' => $hash, ':username' => $target_username]);

    //     // Send a proper JSON response back to the browser
    //     if ($success && $stmt->rowCount() > 0) {
    //         echo json_encode(["success" => true, "message" => "Sorted! The password for {$target_username} has been securely updated. Now delete this method!"]);
    //     } else {
    //         http_response_code(400);
    //         echo json_encode(["success" => false, "message" => "Bollocks. User not found, or the hash is exactly the same."]);
    //     }
    // }
}