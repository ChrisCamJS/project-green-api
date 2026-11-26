<?php

namespace App\Controllers;

class ContactController {

    public function submit() {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $data = [];
        }

        // Silent honeypot check for automated bot submissions
        if (trim((string)($data['website'] ?? '')) !== '') {
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Thanks for reaching out! Your message has been sent.',
            ]);
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $lastSentAt = (int)($_SESSION['last_contact_sent_at'] ?? 0);
        if ($lastSentAt > 0 && (time() - $lastSentAt) < 30) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => 'Please wait a few seconds before sending another message.',
            ]);
            return;
        }

        $name = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $reason = trim((string)($data['reason'] ?? ''));

        $strLength = static function (string $value): int {
            return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        };

        if ($name === '' || preg_match('/[\r\n]/', $name)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Please enter a valid name.',
            ]);
            return;
        }

        if ($strLength($name) > 120) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Name must be 120 characters or fewer.',
            ]);
            return;
        }

        if (
            $email === ''
            || strlen($email) > 254
            || preg_match('/[\r\n]/', $email)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Please provide a valid email address.',
            ]);
            return;
        }

        $reasonLen = $strLength($reason);
        if ($reasonLen < 5) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Please enter at least 5 characters explaining your reason for contacting us.',
            ]);
            return;
        }

        if ($reasonLen > 3000) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Message must be 3000 characters or fewer.',
            ]);
            return;
        }

        $safeName = trim(preg_replace('/[\r\n]+/', ' ', $name));
        $escapedName = htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8');
        $escapedEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $escapedReason = nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'));
        $sentTimestamp = gmdate('Y-m-d H:i:s') . ' UTC';

        $to = 'chris@theveggievault.com';
        $subject = 'The Veggie Vault Contact Form — ' . $safeName;
        $message = "
        <html>
        <head><title>Contact Form Submission</title></head>
        <body style='font-family: Arial, sans-serif; color: #1e293b; line-height: 1.6;'>
          <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #cbd5e1; border-radius: 10px;'>
            <h2 style='margin-top: 0; color: #2e7d32;'>New Contact Form Message</h2>
            <p><strong>Name:</strong> {$escapedName}</p>
            <p><strong>Email:</strong> {$escapedEmail}</p>
            <p><strong>Submitted:</strong> {$sentTimestamp}</p>
            <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 16px 0;' />
            <p><strong>Reason for Contact:</strong></p>
            <div style='background: #f8fafc; padding: 14px; border-radius: 8px; border: 1px solid #e2e8f0;'>
              {$escapedReason}
            </div>
          </div>
        </body>
        </html>
        ";

        $mailSent = \App\Services\MailService::sendHtml($to, $subject, $message, $email);

        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $isLocal = $host === '' || str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');

        if ($mailSent) {
            $_SESSION['last_contact_sent_at'] = time();
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Thanks for reaching out! Your message has been sent.',
            ]);
            return;
        }

        if ($isLocal) {
            error_log(sprintf(
                '[VeggieVault Contact Dev] To: %s | From: %s <%s> | Reason: %s',
                $to,
                $safeName,
                $email,
                str_replace(["\r", "\n"], ' ', substr($reason, 0, 300))
            ));
            $_SESSION['last_contact_sent_at'] = time();
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'devMode' => true,
                'message' => 'Message received! (Local dev environment has no mail server configured; in production this emails chris@theveggievault.com directly.)',
            ]);
            return;
        }

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => "We couldn't send your message right now. Please try again later or email chris@theveggievault.com directly.",
        ]);
    }
}
