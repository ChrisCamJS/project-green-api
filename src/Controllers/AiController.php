<?php

namespace App\Controllers;

use App\Database;

class AiController {
    private const CHAT_MODEL = 'gemini-3.5-flash-lite';
    private const IMAGE_MODEL = 'gemini-3.1-flash-lite-image';
    private const GEMINI_BASE = 'https://generativelanguage.googleapis.com';

    private function requireAuth(): int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authentication required.']);
            exit;
        }
        return (int)$_SESSION['user_id'];
    }

    /**
     * Loads the signed-in admin's confidential background context document for Emma, if configured.
     * Returns empty string for non-admin sessions or when no context document is stored.
     */
    private function getActiveAdminContextPromptBlock(): string {
        if (empty($_SESSION['is_admin']) || empty($_SESSION['user_id'])) {
            return '';
        }

        $adminId = (int)$_SESSION['user_id'];
        try {
            $db = Database::connect();
            $stmt = $db->prepare("SELECT file_name, context_text FROM admin_emma_context WHERE user_id = ? LIMIT 1");
            $stmt->execute([$adminId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return '';
            }

            $rawText = trim((string)($row['context_text'] ?? ''));
            if ($rawText === '') {
                return '';
            }

            $clamped = mb_substr($rawText, 0, 60000);
            $sourceLabel = trim((string)($row['file_name'] ?? '')) ?: 'Uploaded Admin Document';

            return "CONFIDENTIAL ADMIN BACKGROUND CONTEXT (ONLY FOR SPEAKING WITH THIS ADMIN USER — \"{$sourceLabel}\"):\n"
                . "You are currently speaking directly with the Vault admin/creator. Keep the following personal/brand/operational context in mind in all replies:\n\n"
                . $clamped . "\n\n"
                . "PRIVACY & BOUNDARY RULE: Use this context naturally to tailor advice, tone, continuity, and preferences when conversing with the admin, "
                . "but NEVER include private personal details from this document inside any generated public recipe title, recipe description, "
                . "ingredients/instructions saved to the community database, or public nutritional Deep Dive analysis.";
        } catch (\Throwable $e) {
            // Silently ignore if admin_emma_context table does not yet exist
            return '';
        }
    }

    private function getGeminiApiKey(): string {
        $env = Database::loadEnv();
        $key = trim((string)($env['GEMINI_API_KEY'] ?? ''));
        if ($key === '') {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'AI service is not configured on the server.']);
            exit;
        }
        return $key;
    }

    private function resolveCaBundlePath(): ?string {
        $configured = trim((string)ini_get('curl.cainfo')) ?: trim((string)ini_get('openssl.cafile'));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        $phpDir = dirname(PHP_BINARY);
        $candidates = [
            $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem',
            'C:\\Program Files\\Ampps\\php82\\extras\\ssl\\cacert.pem',
            'C:\\Program Files\\Ampps\\php\\extras\\ssl\\cacert.pem',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function postGeminiJson(string $url, array $payload, string $apiKey, int $timeout = 60): array {
        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];

        $caBundle = $this->resolveCaBundlePath();
        if ($caBundle !== null) {
            $curlOpts[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($ch, $curlOpts);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            error_log('Gemini proxy curl error: ' . $curlErr);
            return ['ok' => false, 'status' => 502, 'data' => ['error' => ['message' => 'Failed to reach AI service.']]];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log('Gemini proxy invalid JSON response: HTTP ' . $httpCode);
            return ['ok' => false, 'status' => 502, 'data' => ['error' => ['message' => 'Invalid response from AI service.']]];
        }

        return [
            'ok' => ($httpCode >= 200 && $httpCode < 300),
            'status' => $httpCode > 0 ? $httpCode : 502,
            'data' => $decoded,
        ];
    }

    /**
     * POST /ai/chat
     * Proxies text chat requests to Gemini without exposing the API key to the browser.
     */
    public function chat(): void {
        $this->requireAuth();
        $apiKey = $this->getGeminiApiKey();

        $rawInput = file_get_contents('php://input');
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if (($rawInput === '' || $rawInput === false) && $contentLength > 0) {
            http_response_code(413);
            echo json_encode([
                'success' => false,
                'message' => 'Your message or attached image exceeds the server upload limit. Please try a smaller image.',
            ]);
            return;
        }

        $input = json_decode((string)$rawInput, true) ?: [];
        $contents = $input['contents'] ?? null;
        $systemInstruction = $input['systemInstruction'] ?? null;

        if (!is_array($contents) || empty($contents)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Chat contents are required.']);
            return;
        }

        $payload = ['contents' => $contents];
        if (is_array($systemInstruction) && !empty($systemInstruction)) {
            $payload['systemInstruction'] = $systemInstruction;
        }
        $adminContextBlock = $this->getActiveAdminContextPromptBlock();
        if ($adminContextBlock !== '') {
            if (!isset($payload['systemInstruction']) || !is_array($payload['systemInstruction'])) {
                $payload['systemInstruction'] = ['parts' => []];
            }
            if (!isset($payload['systemInstruction']['parts']) || !is_array($payload['systemInstruction']['parts'])) {
                $payload['systemInstruction']['parts'] = [];
            }
            $payload['systemInstruction']['parts'][] = ['text' => $adminContextBlock];
        }

        $url = self::GEMINI_BASE . '/v1beta/models/' . self::CHAT_MODEL . ':generateContent';
        $res = $this->postGeminiJson($url, $payload, $apiKey, 60);

        if (!$res['ok']) {
            http_response_code($res['status']);
            $errMsg = $res['data']['error']['message'] ?? 'AI chat request failed.';
            echo json_encode(['success' => false, 'message' => $errMsg]);
            return;
        }

        $text = $res['data']['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text)) {
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => 'Unexpected response format from AI service.']);
            return;
        }

        echo json_encode(['success' => true, 'text' => $text]);
    }

    /**
     * POST /ai/image
     * Proxies recipe image generation requests to Gemini without exposing the API key.
     */
    public function generateImage(): void {
        $this->requireAuth();
        $apiKey = $this->getGeminiApiKey();

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $prompt = trim((string)($input['prompt'] ?? ''));

        if ($prompt === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Image prompt is required.']);
            return;
        }

        $noPeopleClause = 'Focus strictly on the prepared food, dishware, and tabletop styling. Strictly NO people, persons, human figures, chefs, grandmothers, grandpas, family members, hands, faces, silhouettes, or background characters — food and table setting only in the scene.';
        if (!stripos($prompt, 'no people') && !stripos($prompt, 'food only') && !stripos($prompt, 'food and table setting only')) {
            $prompt .= ' ' . $noPeopleClause;
        }

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'responseModalities' => ['IMAGE'],
                'imageConfig' => [
                    'aspectRatio' => '16:9',
                    'imageSize' => '1K',
                ],
            ],
        ];

        $url = self::GEMINI_BASE . '/v1beta/models/' . self::IMAGE_MODEL . ':generateContent';
        $res = $this->postGeminiJson($url, $payload, $apiKey, 90);

        if (!$res['ok']) {
            http_response_code($res['status']);
            $errMsg = $res['data']['error']['message'] ?? 'AI image generation failed.';
            echo json_encode(['success' => false, 'message' => $errMsg]);
            return;
        }

        $parts = $res['data']['candidates'][0]['content']['parts'] ?? [];
        $imageBase64 = null;
        foreach ($parts as $part) {
            if (!empty($part['inlineData']['data'])) {
                $imageBase64 = $part['inlineData']['data'];
                break;
            }
        }

        echo json_encode([
            'success' => true,
            'imageBase64' => $imageBase64,
        ]);
    }

    /**
     * POST /ai/live-token
     * Mints a short-lived, single-use Gemini Live API ephemeral token so the master API key
     * never leaves the server.
     */
    public function createLiveToken(): void {
        $this->requireAuth();
        $apiKey = $this->getGeminiApiKey();

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expireTime = $now->modify('+30 minutes')->format('Y-m-d\TH:i:s\Z');
        $newSessionExpireTime = $now->modify('+2 minutes')->format('Y-m-d\TH:i:s\Z');

        $payload = [
            'uses' => 1,
            'expireTime' => $expireTime,
            'newSessionExpireTime' => $newSessionExpireTime,
        ];

        $url = self::GEMINI_BASE . '/v1alpha/auth_tokens';
        $res = $this->postGeminiJson($url, $payload, $apiKey, 20);

        if (!$res['ok'] || empty($res['data']['name'])) {
            http_response_code($res['status'] >= 400 ? $res['status'] : 502);
            $errMsg = $res['data']['error']['message'] ?? 'Could not initialize live audio session.';
            echo json_encode(['success' => false, 'message' => $errMsg]);
            return;
        }

        $adminContextBlock = $this->getActiveAdminContextPromptBlock();

        echo json_encode([
            'success' => true,
            'token' => $res['data']['name'],
            'expireTime' => $res['data']['expireTime'] ?? $expireTime,
            'newSessionExpireTime' => $res['data']['newSessionExpireTime'] ?? $newSessionExpireTime,
            'adminContext' => $adminContextBlock !== '' ? $adminContextBlock : null,
        ]);
    }

    /**
     * POST /ai/google-drive-fetch
     * Safely downloads or exports a user-shared Google Drive file, Google Doc, or Google Sheet
     * so the frontend can pass it through Emma's reference document parser without CORS errors.
     */
    public function fetchGoogleDriveFile(): void {
        $this->requireAuth();

        $raw = file_get_contents('php://input');
        $body = json_decode($raw ?: '', true);
        $rawUrl = trim((string)($body['url'] ?? ''));

        if ($rawUrl === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Please paste a Google Drive, Google Docs, or Google Sheets link.']);
            return;
        }

        $kind = 'drive';
        $fileId = null;

        if (preg_match('#docs\.google\.com/document/d/([a-zA-Z0-9_-]{10,120})#i', $rawUrl, $m)) {
            $kind = 'gdoc';
            $fileId = $m[1];
        } elseif (preg_match('#docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]{10,120})#i', $rawUrl, $m)) {
            $kind = 'gsheet';
            $fileId = $m[1];
        } elseif (preg_match('#(?:drive|docs)\.google\.com/(?:file/d/|open\?id=|uc\?[^\#]*id=)([a-zA-Z0-9_-]{10,120})#i', $rawUrl, $m)) {
            $kind = 'drive';
            $fileId = $m[1];
        } elseif (preg_match('#[?&]id=([a-zA-Z0-9_-]{10,120})#i', $rawUrl, $m) && str_contains(strtolower($rawUrl), 'google.com')) {
            $kind = 'drive';
            $fileId = $m[1];
        } elseif (preg_match('/^[a-zA-Z0-9_-]{20,120}$/', $rawUrl)) {
            $kind = 'drive';
            $fileId = $rawUrl;
        }

        if (!$fileId) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'That does not look like a valid Google Drive, Google Docs, or Google Sheets link.',
            ]);
            return;
        }

        if ($kind === 'gdoc') {
            $downloadUrl = "https://docs.google.com/document/d/{$fileId}/export?format=txt";
        } elseif ($kind === 'gsheet') {
            $downloadUrl = "https://docs.google.com/spreadsheets/d/{$fileId}/export?format=csv";
        } else {
            $downloadUrl = "https://drive.google.com/uc?export=download&id={$fileId}";
        }

        $responseHeaders = [];
        $ch = curl_init($downloadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'TheVeggieVault-GoogleDriveImporter/1.0',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $val = trim($parts[1]);
                    $responseHeaders[$name] = $val;
                }
                return $len;
            },
        ]);

        $caBundle = $this->resolveCaBundlePath();
        if ($caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }

        $fileBytes = curl_exec($ch);
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = strtolower(trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($fileBytes === false || $httpStatus >= 400 || $httpStatus === 0) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => $curlErr
                    ? "Could not reach Google Drive: {$curlErr}"
                    : 'Could not download that file from Google Drive. Make sure link sharing is set to "Anyone with the link can view".',
            ]);
            return;
        }

        $maxBytes = 10 * 1024 * 1024; // 10 MB
        $byteLength = strlen($fileBytes);
        if ($byteLength === 0) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'The shared Google Drive file appears to be empty.',
            ]);
            return;
        }
        if ($byteLength > $maxBytes) {
            http_response_code(413);
            echo json_encode([
                'success' => false,
                'message' => 'Google Drive file is larger than the 10 MB maximum limit.',
            ]);
            return;
        }

        $disposition = $responseHeaders['content-disposition'] ?? '';
        $parsedFileName = '';
        if ($disposition !== '') {
            if (preg_match("/filename\\*=UTF-8''([^;\\r\\n]+)/i", $disposition, $dm)) {
                $parsedFileName = rawurldecode(trim($dm[1], "\"' "));
            } elseif (preg_match('/filename="([^"]+)"/i', $disposition, $dm)) {
                $parsedFileName = trim($dm[1]);
            } elseif (preg_match('/filename=([^;\r\n]+)/i', $disposition, $dm)) {
                $parsedFileName = trim($dm[1], "\"' ");
            }
        }

        $semiPos = strpos($contentType, ';');
        $cleanMime = $semiPos !== false ? trim(substr($contentType, 0, $semiPos)) : $contentType;

        // If Google returned an HTML sign-in / permission gate instead of a downloaded file
        if (
            $cleanMime === 'text/html'
            && $parsedFileName === ''
            && (
                str_contains($fileBytes, 'ServiceLogin')
                || str_contains($fileBytes, 'accounts.google.com')
                || str_contains($fileBytes, 'Sign in - Google Accounts')
            )
        ) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'This Google Drive item is restricted. In Google Drive, click Share -> set General Access to "Anyone with the link", then try again.',
            ]);
            return;
        }

        $shortId = substr($fileId, 0, 8);
        if ($parsedFileName === '') {
            if ($kind === 'gdoc') {
                $parsedFileName = "google-doc-{$shortId}.txt";
                $cleanMime = 'text/plain';
            } elseif ($kind === 'gsheet') {
                $parsedFileName = "google-sheet-{$shortId}.csv";
                $cleanMime = 'text/csv';
            } else {
                $mimeToExt = [
                    'application/pdf' => 'pdf',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'text/plain' => 'txt',
                    'text/markdown' => 'md',
                    'text/csv' => 'csv',
                    'application/json' => 'json',
                ];
                $ext = $mimeToExt[$cleanMime] ?? 'txt';
                $parsedFileName = "google-drive-{$shortId}.{$ext}";
            }
        }

        if ($kind === 'gdoc' && !preg_match('/\.[a-z0-9]{2,5}$/i', $parsedFileName)) {
            $parsedFileName .= '.txt';
            $cleanMime = 'text/plain';
        } elseif ($kind === 'gsheet' && !preg_match('/\.[a-z0-9]{2,5}$/i', $parsedFileName)) {
            $parsedFileName .= '.csv';
            $cleanMime = 'text/csv';
        }

        echo json_encode([
            'success' => true,
            'fileName' => $parsedFileName,
            'mimeType' => $cleanMime ?: 'application/octet-stream',
            'size' => $byteLength,
            'base64Data' => base64_encode($fileBytes),
        ]);
    }
}
