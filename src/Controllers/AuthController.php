<?php

namespace App\Controllers;

use App\Database;
use PDO;

class AuthController {

public function register() {
        // Find and parse the .env file safely
        $envPath = __DIR__ . '/../.env';
        if (!file_exists($envPath)) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Server configuration missing. Be a dear and check the .env file."]);
            return;
        }
        
        $env = parse_ini_file($envPath);
        $beta_invite_code = $env['BETA_INVITE_CODE'] ?? null;

        if (!$beta_invite_code) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Beta invite code is not configured on the server."]);
            return;
        }

        $db = \App\Database::connect();
        
        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);

        if (!is_array($data)) {
            $data = [];
        }

        $email = trim($data['email'] ?? '');
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $invite_code = trim($data['inviteCode'] ?? '');

        // 1. Check for missing fields
        if (empty($email) || empty($username) || empty($password) || empty($invite_code)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Don't be lazy, mate. Fill in all the fields!"]);
            return;
        }

        // 2. The Dynamic Bouncer Check
        $codeSql = "SELECT id FROM invite_codes WHERE code = :code AND is_active = 1 LIMIT 1";
        $codeStmt = $db->prepare($codeSql);
        $codeStmt->execute([':code' => $invite_code]);

        if (!$codeStmt->fetch()) {
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Access denied. That invite code is either invalid, expired, or totally made up."]);
            return;
        }

        // 3. Check for existing mates (Email or Username)
        $checkSql = "SELECT id FROM users WHERE email = :email OR username = :username LIMIT 1";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([':email' => $email, ':username' => $username]);
        
        if ($checkStmt->fetch()) {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "Someone already snagged that email or username. Try again."]);
            return;
        }

        // 4. Hash the password and insert the new user
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Setting default tokens to 10 for new testers
        $insertSql = "INSERT INTO users (username, email, password_hash, account_tier, generation_tokens) VALUES (:username, :email, :hash, 'free', 10)";
        $insertStmt = $db->prepare($insertSql);
        
        $success = $insertStmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':hash' => $password_hash
        ]);

        if ($success) {
            $newUserId = $db->lastInsertId();
            
            // Instantly log the new mate in!
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            $_SESSION['user_id'] = $newUserId;
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;
            $_SESSION['is_admin'] = 0; // Default for new testers
            $_SESSION['account_tier'] = 'free';
            $_SESSION['generation_tokens'] = 10;
            
            // Build the user array to match your login endpoint's output exactly
            $user = [
                'id' => $newUserId,
                'username' => $username,
                'email' => $email,
                'is_admin' => 0,
                'account_tier' => 'free',
                'generation_tokens' => 10
            ];

            echo json_encode([
                "success" => true, 
                "message" => "Brilliant! You're on the list. Rolling out the red carpet...",
                "user" => $user
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "Something went horribly wrong in the database."]);
        }
    }

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

    public function requestPasswordReset() {
        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);
        $email = trim($data['email'] ?? '');

        if (empty($email)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Provide an email address, mate."]);
            return;
        }

        $db = \App\Database::connect();
        $stmt = $db->prepare("SELECT id, username FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate a secure 64-character hex token
            $token = bin2hex(random_bytes(32));
            // Set expiry to 1 hour from now
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $updateStmt = $db->prepare("UPDATE users SET reset_token = :token, reset_token_expires = :expires WHERE id = :id");
            $updateStmt->execute([':token' => $token, ':expires' => $expires, ':id' => $user['id']]);

            // Construct the exact reset link for your frontend route
            $resetLink = "https://vault.chrisandemmashow.com/reset-password?token=" . $token;

            // Prepare the email
            $to = $email;
            $subject = "Veggie Vault - Password Reset Request";
            
            // A bit of cheeky HTML for the email body to keep things looking professional
            $message = "
            <html>
            <head>
              <title>Password Reset</title>
            </head>
            <body style='font-family: Arial, sans-serif; color: #333;'>
              <h2>Oi, " . htmlspecialchars($user['username']) . "!</h2>
              <p>Someone (hopefully you) requested a password reset for your Veggie Vault account.</p>
              <p>Click the link below to set a new password. You've got exactly one hour before this link self-destructs:</p>
              <p><a href='" . $resetLink . "' style='padding: 10px 15px; background-color: #28a745; color: #fff; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;'>Reset My Password</a></p>
              <p style='margin-top: 20px;'>If you didn't request this, just ignore it. Your vault remains securely locked.</p>
              <br>
              <p>Cheers,<br>Emma Advanced</p>
            </body>
            </html>
            ";

            // Headers to make it look like a proper HTML email and avoid the spam bin
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            // Ensure this domain matches the server sending the email
            $headers .= "From: Veggie Vault <noreply@vault.chrisandemmashow.com>\r\n";
            $headers .= "Reply-To: noreply@vault.chrisandemmashow.com\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();

            // Fire the email
            $mailSent = mail($to, $subject, $message, $headers);

            if ($mailSent) {
                echo json_encode(["success" => true, "message" => "Reset link dispatched! Tell them to check their inbox (and the spam folder, just in case)."]);
            } else {
                http_response_code(500);
                echo json_encode(["success" => false, "message" => "Right, the server threw a wobbly and couldn't send the email."]);
            }

        } else {
            // Always return success even if email isn't found to prevent user enumeration attacks
            echo json_encode(["success" => true, "message" => "If that email exists, a reset link has been dispatched."]);
        }
    }

    public function resetPassword() {
        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);
        $token = $data['token'] ?? '';
        $newPassword = $data['password'] ?? '';

        if (empty($token) || empty($newPassword)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Token and new password are required."]);
            return;
        }

        $db = \App\Database::connect();
        
        // Find the user with this token, ensuring it hasn't expired
        $stmt = $db->prepare("SELECT id FROM users WHERE reset_token = :token AND reset_token_expires > NOW() LIMIT 1");
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid or expired reset token. Too slow!"]);
            return;
        }

        // Hash the new password and clear the token out so it can't be reused
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $db->prepare("UPDATE users SET password_hash = :hash, reset_token = NULL, reset_token_expires = NULL WHERE id = :id");
        $updateStmt->execute([':hash' => $hash, ':id' => $user['id']]);

        echo json_encode(["success" => true, "message" => "Password updated successfully! You may now log in."]);
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