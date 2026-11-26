<?php

namespace App\Services;

use App\Database;

/**
 * Sends transactional HTML emails via authenticated SMTP (configured in .env)
 * so SPF/DKIM/Return-Path align with the sender domain and avoid "via ..." banners.
 * Falls back to PHP mail() with -f envelope sender if SMTP is not configured.
 */
class MailService {
    public static function sendHtml(string $to, string $subject, string $htmlBody, ?string $replyTo = null): bool {
        $env = Database::loadEnv();
        $fromEmail = trim((string)($env['MAIL_FROM_ADDRESS'] ?? 'chris@theveggievault.com'));
        $fromName = trim((string)($env['MAIL_FROM_NAME'] ?? 'The Veggie Vault'));
        $replyToAddr = trim((string)($replyTo ?: $fromEmail));

        $smtpHost = trim((string)($env['SMTP_HOST'] ?? ''));
        $smtpPort = (int)($env['SMTP_PORT'] ?? 465);
        $smtpUser = trim((string)($env['SMTP_USER'] ?? ''));
        $smtpPass = (string)($env['SMTP_PASS'] ?? '');
        $smtpEncryption = strtolower(trim((string)($env['SMTP_ENCRYPTION'] ?? ($smtpPort === 587 ? 'tls' : 'ssl'))));

        if ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') {
            $sentViaSmtp = self::sendViaSmtp(
                $smtpHost,
                $smtpPort,
                $smtpEncryption,
                $smtpUser,
                $smtpPass,
                $fromEmail,
                $fromName,
                $to,
                $subject,
                $htmlBody,
                $replyToAddr
            );
            if ($sentViaSmtp) {
                return true;
            }
        }

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . self::formatAddress($fromName, $fromEmail) . "\r\n";
        $headers .= "Reply-To: {$replyToAddr}\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        $extraParams = filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ? "-f{$fromEmail}" : '';
        $sent = $extraParams !== ''
            ? @mail($to, $subject, $htmlBody, $headers, $extraParams)
            : @mail($to, $subject, $htmlBody, $headers);

        return (bool)$sent;
    }

    private static function formatAddress(string $name, string $email): string {
        $cleanName = str_replace(["\r", "\n", '"'], '', $name);
        return $cleanName !== '' ? "\"{$cleanName}\" <{$email}>" : $email;
    }

    private static function encodeHeaderValue(string $value): string {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private static function readSmtpResponse($socket): array {
        $lines = '';
        $code = 0;
        while (($line = fgets($socket, 515)) !== false) {
            $lines .= $line;
            if (strlen($line) >= 3) {
                $code = (int)substr($line, 0, 3);
            }
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return ['code' => $code, 'raw' => $lines];
    }

    private static function sendSmtpCommand($socket, string $command, array $expectedCodes): bool {
        if ($command !== '') {
            fwrite($socket, $command . "\r\n");
        }
        $res = self::readSmtpResponse($socket);
        return in_array($res['code'], $expectedCodes, true);
    }

    private static function sendViaSmtp(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        string $fromEmail,
        string $fromName,
        string $to,
        string $subject,
        string $htmlBody,
        string $replyTo
    ): bool {
        $transport = $encryption === 'ssl' ? "ssl://{$host}:{$port}" : "tcp://{$host}:{$port}";
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($transport, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            error_log("MailService SMTP connect failed ({$transport}): [{$errno}] {$errstr}");
            return false;
        }

        stream_set_timeout($socket, 15);

        try {
            $greeting = self::readSmtpResponse($socket);
            if ($greeting['code'] !== 220) {
                throw new \RuntimeException('Unexpected SMTP greeting: ' . trim($greeting['raw']));
            }

            $ehloDomain = parse_url((string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: 'theveggievault.com';
            if (!self::sendSmtpCommand($socket, "EHLO {$ehloDomain}", [250])) {
                if (!self::sendSmtpCommand($socket, "HELO {$ehloDomain}", [250])) {
                    throw new \RuntimeException('SMTP EHLO/HELO failed');
                }
            }

            if ($encryption === 'tls') {
                if (!self::sendSmtpCommand($socket, 'STARTTLS', [220])) {
                    throw new \RuntimeException('SMTP STARTTLS failed');
                }
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Failed to enable TLS encryption');
                }
                if (!self::sendSmtpCommand($socket, "EHLO {$ehloDomain}", [250])) {
                    throw new \RuntimeException('SMTP EHLO after STARTTLS failed');
                }
            }

            if (!self::sendSmtpCommand($socket, 'AUTH LOGIN', [334])) {
                throw new \RuntimeException('SMTP AUTH LOGIN rejected');
            }
            if (!self::sendSmtpCommand($socket, base64_encode($username), [334])) {
                throw new \RuntimeException('SMTP username rejected');
            }
            if (!self::sendSmtpCommand($socket, base64_encode($password), [235])) {
                throw new \RuntimeException('SMTP password rejected');
            }

            if (!self::sendSmtpCommand($socket, "MAIL FROM:<{$fromEmail}>", [250])) {
                throw new \RuntimeException('SMTP MAIL FROM rejected');
            }
            if (!self::sendSmtpCommand($socket, "RCPT TO:<{$to}>", [250, 251])) {
                throw new \RuntimeException('SMTP RCPT TO rejected');
            }
            if (!self::sendSmtpCommand($socket, 'DATA', [354])) {
                throw new \RuntimeException('SMTP DATA command rejected');
            }

            $domainPart = substr(strrchr($fromEmail, '@') ?: '@theveggievault.com', 1);
            $messageId = '<' . bin2hex(random_bytes(12)) . '@' . $domainPart . '>';
            $encodedSubject = self::encodeHeaderValue($subject);

            $rawMessage = implode("\r\n", [
                'Date: ' . gmdate('D, d M Y H:i:s +0000'),
                'Message-ID: ' . $messageId,
                'From: ' . self::formatAddress($fromName, $fromEmail),
                'Reply-To: ' . $replyTo,
                'To: ' . $to,
                'Subject: ' . $encodedSubject,
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                '',
                '',
            ]);

            $normalizedBody = str_replace(["\r\n", "\r"], "\n", $htmlBody);
            $normalizedBody = str_replace("\n", "\r\n", $normalizedBody);
            // Dot-stuff lines starting with a period per RFC 5321
            $normalizedBody = preg_replace('/^\./m', '..', $normalizedBody);

            fwrite($socket, $rawMessage . $normalizedBody . "\r\n.\r\n");
            $dataRes = self::readSmtpResponse($socket);
            if ($dataRes['code'] !== 250) {
                throw new \RuntimeException('SMTP message body rejected: ' . trim($dataRes['raw']));
            }

            self::sendSmtpCommand($socket, 'QUIT', [221, 250]);
            fclose($socket);
            return true;
        } catch (\Throwable $e) {
            error_log('MailService SMTP error: ' . $e->getMessage());
            @fclose($socket);
            return false;
        }
    }
}
