<?php

namespace App\Controllers;

use App\Database;
use PDO;

class AuthController {

    private function resolveFrontendBaseUrl(): string {
        $origin = rtrim((string)($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $isApiLocal = $host === '' || str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');

        // 1. Prefer a non-localhost browser origin (e.g. https://veggievault.chrisandemmashow.com)
        if ($origin && preg_match('#^https?://#i', $origin)) {
            $isOriginLocal = str_contains($origin, 'localhost') || str_contains($origin, '127.0.0.1');
            if (!$isOriginLocal || $isApiLocal) {
                return $origin;
            }
        }

        // 2. Check .env FRONTEND_URL, but never emit localhost when running on a live server
        $env = \App\Database::loadEnv();
        if (!empty($env['FRONTEND_URL'])) {
            $envUrl = rtrim((string)$env['FRONTEND_URL'], '/');
            $isEnvLocal = str_contains($envUrl, 'localhost') || str_contains($envUrl, '127.0.0.1');
            if (!$isEnvLocal || $isApiLocal) {
                return $envUrl;
            }
        }

        // 3. Safe production fallback
        return $isApiLocal ? 'http://localhost:5173' : 'https://veggievault.chrisandemmashow.com';
    }

    private function sendVerificationEmail(string $email, string $username, string $token): array {
        $baseUrl = $this->resolveFrontendBaseUrl();
        $verifyLink = $baseUrl . '/verify-email?token=' . urlencode($token);
        $isLocal = str_contains($baseUrl, 'localhost') || str_contains($baseUrl, '127.0.0.1');

        $subject = "The Veggie Vault - Verify Your Email Address";
        $message = "
        <html>
        <head><title>Verify Your Email</title></head>
        <body style='font-family: Arial, sans-serif; color: #333;'>
          <h2>Welcome to The Veggie Vault, " . htmlspecialchars($username) . "!</h2>
          <p>Thanks for registering. Before you can enter the vault, please verify your email address by clicking the button below:</p>
          <p>
            <a href='" . htmlspecialchars($verifyLink) . "' style='padding: 12px 20px; background-color: #2e7d32; color: #fff; text-decoration: none; border-radius: 6px; display: inline-block; font-weight: bold;'>
              Verify My Email
            </a>
          </p>
          <p style='margin-top: 16px; font-size: 13px; color: #666;'>Or copy and paste this link into your browser:<br>" . htmlspecialchars($verifyLink) . "</p>
          <p>Cheers,<br>Emma &amp; The Veggie Vault</p>
        </body>
        </html>
        ";

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: The Veggie Vault <noreply@vault.chrisandemmashow.com>\r\n";
        $headers .= "Reply-To: noreply@vault.chrisandemmashow.com\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        $mailSent = @mail($email, $subject, $message, $headers);

        return [
            'sent' => (bool)$mailSent,
            'verifyUrl' => $verifyLink,
            'isLocal' => $isLocal,
        ];
    }

    public function register() {
        $env = \App\Database::loadEnv();
        $beta_invite_code = $env['BETA_INVITE_CODE'] ?? null;
        $requireInviteCode = !isset($env['REQUIRE_INVITE_CODE']) || strtolower((string)$env['REQUIRE_INVITE_CODE']) !== 'false';

        $db = \App\Database::connect();
        
        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);

        if (!is_array($data)) {
            $data = [];
        }

        $email = trim($data['email'] ?? '');
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $confirm_password = $data['confirmPassword'] ?? null;
        $invite_code = trim($data['inviteCode'] ?? '');

        // 1. Check for missing fields
        if (empty($email) || empty($username) || empty($password) || ($requireInviteCode && empty($invite_code))) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Don't be lazy, mate. Fill in all the fields!"]);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "That doesn't look like a valid email address, love."]);
            return;
        }

        if (strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Your password needs to be at least 8 characters long to keep the vault secure."]);
            return;
        }

        if ($confirm_password !== null && $password !== $confirm_password) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Those passwords don't match, mate. Give it another go."]);
            return;
        }

        // 2. The Dynamic Bouncer Check (Database invite_codes table + optional .env fallback)
        if ($requireInviteCode) {
            $codeSql = "SELECT id FROM invite_codes WHERE code = :code AND is_active = 1 LIMIT 1";
            $codeStmt = $db->prepare($codeSql);
            $codeStmt->execute([':code' => $invite_code]);
            $validDbCode = (bool)$codeStmt->fetch();
            $validEnvCode = ($beta_invite_code && hash_equals($beta_invite_code, $invite_code));

            if (!$validDbCode && !$validEnvCode) {
                http_response_code(403);
                echo json_encode(["success" => false, "message" => "Access denied. That invite code is either invalid, expired, or totally made up."]);
                return;
            }
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

        // 4. Hash the password, generate verification token, and insert the new user (unverified until email confirmed)
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $verificationToken = bin2hex(random_bytes(32));
        
        // Setting default tier to 'premium' and tokens to 10 for new testers
        $insertSql = "INSERT INTO users (username, email, password_hash, account_tier, generation_tokens, is_verified, verification_token)
                      VALUES (:username, :email, :hash, 'premium', 10, 0, :verification_token)";
        $insertStmt = $db->prepare($insertSql);
        
        $success = $insertStmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':hash' => $password_hash,
            ':verification_token' => $verificationToken
        ]);

        if ($success) {
            $emailResult = $this->sendVerificationEmail($email, $username, $verificationToken);

            $response = [
                "success" => true,
                "requiresVerification" => true,
                "email" => $email,
                "message" => $emailResult['sent']
                    ? "Account created! Please check your email and click the verification link to unlock your account."
                    : "Account created! Email verification is required before logging in."
            ];

            if ($emailResult['isLocal'] && !$emailResult['sent']) {
                $response['devVerificationUrl'] = $emailResult['verifyUrl'];
            }

            echo json_encode($response);
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
        $sql = "SELECT id, username, email, password_hash, is_admin, is_verified, account_tier, generation_tokens,
                       display_name, location, bio, profile_image, favorite_cuisine, dietary_focus, allergies, website_url, pronouns,
                       height_ft, height_in, weight_lbs, age, gender, activity_level, bmi, bmr, tdee,
                       calorie_target, macro_plan, custom_protein_pct, custom_carbs_pct, custom_fat_pct,
                       protein_target_g, carbs_target_g, fat_target_g
                FROM users WHERE email = :email LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // verify the user exists and the password matches the hash
        if ($user && password_verify($password, $user['password_hash'])) {
            if (isset($user['is_verified']) && (int)$user['is_verified'] === 0) {
                http_response_code(403);
                echo json_encode([
                    "success" => false,
                    "requiresVerification" => true,
                    "email" => $user['email'],
                    "message" => "Please verify your email address before logging in. Check your inbox (and spam folder) for the verification link."
                ]);
                return;
            }
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

    public function verifyEmail() {
        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true) ?? [];
        $token = trim((string)($data['token'] ?? $_GET['token'] ?? ''));

        if (empty($token)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Verification token is required."]);
            return;
        }

        $db = \App\Database::connect();
        $stmt = $db->prepare("
            SELECT id, username, email, is_admin, account_tier, generation_tokens,
                   display_name, location, bio, profile_image, favorite_cuisine, dietary_focus, allergies, website_url, pronouns,
                   height_ft, height_in, weight_lbs, age, gender, activity_level, bmi, bmr, tdee,
                   calorie_target, macro_plan, custom_protein_pct, custom_carbs_pct, custom_fat_pct,
                   protein_target_g, carbs_target_g, fat_target_g
            FROM users
            WHERE verification_token = :token
            LIMIT 1
        ");
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (!empty($_SESSION['user_id'])) {
                $sessStmt = $db->prepare("
                    SELECT id, username, email, is_admin, is_verified, account_tier, generation_tokens,
                           display_name, location, bio, profile_image, favorite_cuisine, dietary_focus, allergies, website_url, pronouns,
                           height_ft, height_in, weight_lbs, age, gender, activity_level, bmi, bmr, tdee,
                           calorie_target, macro_plan, custom_protein_pct, custom_carbs_pct, custom_fat_pct,
                           protein_target_g, carbs_target_g, fat_target_g
                    FROM users
                    WHERE id = :id AND is_verified = 1
                    LIMIT 1
                ");
                $sessStmt->execute([':id' => $_SESSION['user_id']]);
                $verifiedUser = $sessStmt->fetch(PDO::FETCH_ASSOC);
                if ($verifiedUser) {
                    echo json_encode([
                        "success" => true,
                        "message" => "Email verified! Welcome to The Veggie Vault.",
                        "user" => $verifiedUser
                    ]);
                    return;
                }
            }

            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid or already-used verification link."]);
            return;
        }

        $upd = $db->prepare("UPDATE users SET is_verified = 1, verification_token = NULL WHERE id = :id");
        $upd->execute([':id' => $user['id']]);
        $user['is_verified'] = 1;

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['is_admin'] = $user['is_admin'];
        $_SESSION['account_tier'] = $user['account_tier'];
        $_SESSION['generation_tokens'] = $user['generation_tokens'];

        echo json_encode([
            "success" => true,
            "message" => "Email verified! Welcome to The Veggie Vault.",
            "user" => $user
        ]);
    }

    public function resendVerification() {
        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true) ?? [];
        $email = trim((string)($data['email'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Please provide a valid email address."]);
            return;
        }

        $db = \App\Database::connect();
        $stmt = $db->prepare("SELECT id, username, email, is_verified, verification_token FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || (int)$user['is_verified'] === 1) {
            echo json_encode(["success" => true, "message" => "If an unverified account exists for that email, a new verification link has been sent."]);
            return;
        }

        $token = $user['verification_token'] ?: bin2hex(random_bytes(32));
        if (empty($user['verification_token'])) {
            $db->prepare("UPDATE users SET verification_token = :t WHERE id = :id")->execute([':t' => $token, ':id' => $user['id']]);
        }

        $emailResult = $this->sendVerificationEmail($user['email'], $user['username'], $token);
        $response = [
            "success" => true,
            "message" => $emailResult['sent']
                ? "Verification email resent! Please check your inbox and spam folder."
                : "Verification token refreshed."
        ];
        if ($emailResult['isLocal'] && !$emailResult['sent']) {
            $response['devVerificationUrl'] = $emailResult['verifyUrl'];
        }

        echo json_encode($response);
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