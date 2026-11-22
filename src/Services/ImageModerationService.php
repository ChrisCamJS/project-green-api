<?php

namespace App\Services;

use App\Database;

/**
 * Gemini Vision image safety moderation service for all user & recipe uploads
 * (profile avatars, recipe cover/gallery photos, comment attachments, and admin uploads).
 */
class ImageModerationService {
    private const GEMINI_BASE = 'https://generativelanguage.googleapis.com';
    private const MODERATION_MODEL = 'gemini-3.5-flash-lite';
    private const MAX_DIMENSION = 512;

    private string $geminiApiKey;
    private ?string $caBundle;

    public function __construct() {
        $env = Database::loadEnv();
        $this->geminiApiKey = trim((string)($env['GEMINI_API_KEY'] ?? ''));
        $this->caBundle = $this->resolveCaBundlePath();
    }

    /**
     * Resolves an SSL CA certificate bundle path for outbound HTTPS calls on Windows/AMPPS.
     */
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

    /**
     * Resizes oversized images in memory to a lightweight 512px JPEG (~15-40 KB)
     * so moderation checks stay fast while full-resolution bytes remain untouched for storage.
     */
    private function prepareModerationImage(string $rawBytes, string $mimeType): array {
        if (!function_exists('imagecreatefromstring')) {
            return ['bytes' => $rawBytes, 'mime' => $mimeType];
        }

        $src = @imagecreatefromstring($rawBytes);
        if ($src === false) {
            return ['bytes' => $rawBytes, 'mime' => $mimeType];
        }

        $origW = imagesx($src);
        $origH = imagesy($src);
        if ($origW <= 0 || $origH <= 0) {
            imagedestroy($src);
            return ['bytes' => $rawBytes, 'mime' => $mimeType];
        }

        $maxSide = max($origW, $origH);
        if ($maxSide <= self::MAX_DIMENSION && $mimeType === 'image/jpeg') {
            imagedestroy($src);
            return ['bytes' => $rawBytes, 'mime' => 'image/jpeg'];
        }

        $scale = $maxSide > self::MAX_DIMENSION ? (self::MAX_DIMENSION / $maxSide) : 1.0;
        $dstW = max(1, (int)round($origW * $scale));
        $dstH = max(1, (int)round($origH * $scale));

        $dst = imagecreatetruecolor($dstW, $dstH);
        // White backdrop ensures transparent PNG avatars/icons remain clearly legible
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $origW, $origH);

        ob_start();
        imagejpeg($dst, null, 75);
        $jpegBytes = (string)ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        if ($jpegBytes === '') {
            return ['bytes' => $rawBytes, 'mime' => $mimeType];
        }

        return ['bytes' => $jpegBytes, 'mime' => 'image/jpeg'];
    }

    /**
     * Evaluates raw image bytes with Gemini Vision.
     *
     * @param string $rawBytes Decoded binary image bytes.
     * @param string $mimeType Validated image MIME type.
     * @param string $context Upload context: 'profile', 'recipe', or 'comment'.
     * @return array{safe: bool, status?: int, category?: string, message?: string}
     */
    public function moderateImageBytes(string $rawBytes, string $mimeType, string $context = 'recipe'): array {
        if ($rawBytes === '') {
            return [
                'safe' => false,
                'status' => 400,
                'category' => 'invalid_image',
                'message' => 'Image data cannot be empty.',
            ];
        }

        if ($this->geminiApiKey === '') {
            error_log('Image moderation failed: GEMINI_API_KEY is not configured.');
            return [
                'safe' => false,
                'status' => 503,
                'category' => 'service_unavailable',
                'message' => 'Unable to verify image safety right now. Please try uploading again in a moment.',
            ];
        }

        $prepared = $this->prepareModerationImage($rawBytes, $mimeType);
        $contextDesc = $context === 'profile'
            ? 'a user profile avatar or chef portrait on a wholesome plant-based cooking community site (normal selfies, portraits, pets, nature, food, and benign logos are completely fine)'
            : ($context === 'comment'
                ? 'a recipe comment photo on a wholesome plant-based cooking community site (dishes, ingredients, kitchens, plating variations, and nutrition labels are completely fine)'
                : ($context === 'story'
                    ? 'a community recipe story blog photo on a wholesome plant-based cooking community site (dishes, family dinners, kitchens, gardens, ingredients, and cooking moments are completely fine)'
                    : 'a recipe photo on a wholesome plant-based cooking community site (food, dishes, ingredients, kitchens, cooking steps, and nutrition labels are completely fine)'));

        $prompt = "Inspect this uploaded image for safety moderation. It is intended for {$contextDesc}.\n\n"
            . "Set safe=false ONLY if the image contains any inappropriate or harmful content, including:\n"
            . "- Nudity, sexually explicit, or suggestive NSFW imagery\n"
            . "- Graphic violence, gore, blood, weapons abuse, or self-harm\n"
            . "- Hate symbols, slurs, harassment, or threatening imagery\n"
            . "- Obscene hand gestures or profane/abusive visible text\n"
            . "- Shock imagery or illegal activity\n\n"
            . "Do NOT flag innocent photos of whole-food plant-based meals, sauces, vegetables, kitchen tools, portraits, or pets.";

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'inlineData' => [
                                'mimeType' => $prepared['mime'],
                                'data' => base64_encode($prepared['bytes']),
                            ],
                        ],
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.0,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'safe' => ['type' => 'BOOLEAN'],
                        'category' => ['type' => 'STRING'],
                        'reason' => ['type' => 'STRING'],
                    ],
                    'required' => ['safe', 'category', 'reason'],
                ],
            ],
        ];

        $url = self::GEMINI_BASE . '/v1beta/models/' . self::MODERATION_MODEL . ':generateContent';
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->geminiApiKey,
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ];
        if ($this->caBundle !== null) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $httpCode < 200 || $httpCode >= 300) {
            error_log("Image moderation API failure (HTTP {$httpCode}): {$curlErr}");
            return [
                'safe' => false,
                'status' => 503,
                'category' => 'service_unavailable',
                'message' => 'Unable to verify image safety right now. Please try uploading again in a moment.',
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log('Image moderation API returned invalid JSON.');
            return [
                'safe' => false,
                'status' => 503,
                'category' => 'service_unavailable',
                'message' => 'Unable to verify image safety right now. Please try uploading again in a moment.',
            ];
        }

        // Layer 1: API-level safety block before candidate generation
        $blockReason = (string)($decoded['promptFeedback']['blockReason'] ?? '');
        $finishReason = (string)($decoded['candidates'][0]['finishReason'] ?? '');
        if (
            $blockReason !== '' ||
            in_array($finishReason, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'IMAGE_SAFETY'], true)
        ) {
            return [
                'safe' => false,
                'status' => 400,
                'category' => 'safety_blocked',
                'message' => 'This image cannot be uploaded because it appears to contain inappropriate content. Please choose a different photo.',
            ];
        }

        // Layer 2: Structured JSON verdict
        $jsonText = (string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $verdict = json_decode($jsonText, true);
        if (!is_array($verdict) || !array_key_exists('safe', $verdict)) {
            error_log('Image moderation API verdict unreadable: ' . substr($jsonText, 0, 200));
            return [
                'safe' => false,
                'status' => 503,
                'category' => 'service_unavailable',
                'message' => 'Unable to verify image safety right now. Please try uploading again in a moment.',
            ];
        }

        if (empty($verdict['safe'])) {
            return [
                'safe' => false,
                'status' => 400,
                'category' => (string)($verdict['category'] ?? 'inappropriate'),
                'message' => 'This image cannot be uploaded because it appears to contain inappropriate content. Please choose a different photo.',
            ];
        }

        return [
            'safe' => true,
            'category' => (string)($verdict['category'] ?? 'clean'),
        ];
    }

    /**
     * Asserts raw image bytes are safe; otherwise responds with HTTP 400/503 JSON and exits immediately.
     */
    public function assertImageSafe(string $rawBytes, string $mimeType, string $context = 'recipe'): void {
        $result = $this->moderateImageBytes($rawBytes, $mimeType, $context);
        if (empty($result['safe'])) {
            http_response_code((int)($result['status'] ?? 400));
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'This image cannot be uploaded because it appears to contain inappropriate content.',
            ]);
            exit;
        }
    }
}
