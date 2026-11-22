<?php

namespace App\Services;

use App\Database;
use PDO;

/**
 * Handles Recipe Stories schema migration, strict HTML sanitization (XSS prevention),
 * SEO slug generation, read-time & auto-excerpt calculation, and EXIF-stripped +
 * Gemini-moderated story image uploads.
 */
class StoryService {
    private static bool $schemaChecked = false;
    private PDO $db;

    /**
     * Allowed HTML tags and their allowlisted attributes for rich-text story bodies.
     */
    private const ALLOWED_TAGS = [
        'p' => [],
        'br' => [],
        'h2' => [],
        'h3' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'blockquote' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'hr' => [],
        'figure' => ['class'],
        'figcaption' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'loading'],
        'iframe' => ['src', 'title', 'frameborder', 'allow', 'allowfullscreen', 'loading', 'referrerpolicy'],
    ];

    /**
     * Tags whose entire subtree (including inner text) must be removed during sanitization.
     */
    private const DROP_SUBTREE_TAGS = [
        'script',
        'style',
        'object',
        'embed',
        'applet',
        'form',
        'input',
        'button',
        'textarea',
        'select',
        'option',
        'svg',
        'math',
        'template',
        'noscript',
        'meta',
        'link',
        'base',
        'head',
        'title',
    ];

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::connect();
    }

    /**
     * Ensures `recipe_stories`, `story_likes`, and `notifications.story_slug` exist.
     */
    public static function ensureSchema(PDO $db): void {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;

        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS recipe_stories (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    recipe_id INT NOT NULL,
                    user_id INT NOT NULL,
                    title VARCHAR(200) NOT NULL,
                    slug VARCHAR(240) NOT NULL UNIQUE,
                    excerpt VARCHAR(320) NOT NULL,
                    cover_image VARCHAR(500) NULL DEFAULT NULL,
                    content_html MEDIUMTEXT NOT NULL,
                    read_time_minutes INT NOT NULL DEFAULT 1,
                    status ENUM('draft', 'published') NOT NULL DEFAULT 'published',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_recipe_stories_recipe_id (recipe_id, status, created_at),
                    INDEX idx_recipe_stories_user_id (user_id, status, created_at),
                    CONSTRAINT fk_recipe_stories_recipe
                        FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
                    CONSTRAINT fk_recipe_stories_user
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $db->exec("
                CREATE TABLE IF NOT EXISTS story_likes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    story_id INT NOT NULL,
                    user_id INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_story_user_like (story_id, user_id),
                    INDEX idx_story_likes_story_id (story_id),
                    CONSTRAINT fk_story_likes_story
                        FOREIGN KEY (story_id) REFERENCES recipe_stories(id) ON DELETE CASCADE,
                    CONSTRAINT fk_story_likes_user
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $notifCols = $db->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('story_slug', $notifCols, true)) {
                $db->exec("ALTER TABLE notifications ADD COLUMN story_slug VARCHAR(240) NULL DEFAULT NULL AFTER comment_id");
            }
        } catch (\Throwable $e) {
            error_log('StoryService::ensureSchema warning: ' . $e->getMessage());
        }
    }

    /**
     * Sanitizes rich-text HTML using a strict DOMDocument allowlist to prevent stored XSS.
     */
    public function sanitizeContentHtml(string $rawHtml): string {
        $trimmed = trim($rawHtml);
        if ($trimmed === '') {
            return '';
        }

        // Normalize common block-level browser output (<div> -> <p>) before parsing
        $normalized = preg_replace('/<\s*div(\s+[^>]*)?>/i', '<p>', $trimmed);
        $normalized = preg_replace('/<\s*\/\s*div\s*>/i', '</p>', (string)$normalized);
        $normalized = preg_replace('/<\s*h1(\s+[^>]*)?>/i', '<h2>', (string)$normalized);
        $normalized = preg_replace('/<\s*\/\s*h1\s*>/i', '</h2>', (string)$normalized);
        $normalized = preg_replace('/<\s*h[4-6](\s+[^>]*)?>/i', '<h3>', (string)$normalized);
        $normalized = preg_replace('/<\s*\/\s*h[4-6]\s*>/i', '</h3>', (string)$normalized);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prevUseErrors = libxml_use_internal_errors(true);

        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="__story_root__">'
            . $normalized
            . '</div></body></html>';

        $loaded = $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);

        if (!$loaded) {
            return '<p>' . htmlspecialchars(strip_tags($trimmed), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        $root = $dom->getElementById('__story_root__');
        if (!$root) {
            return '<p>' . htmlspecialchars(strip_tags($trimmed), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        $this->sanitizeDomNode($root, $dom);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }

        $output = trim($output);
        if ($output === '') {
            return '';
        }

        // If the result has no block tags at all, wrap in <p>
        if (!preg_match('/<(p|h2|h3|blockquote|ul|ol|figure)\b/i', $output)) {
            $output = '<p>' . $output . '</p>';
        }

        return $output;
    }

    /**
     * Recursively sanitizes DOM nodes in place.
     */
    private function sanitizeDomNode(\DOMNode $parent, \DOMDocument $dom): void {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $node) {
            if ($node->nodeType === XML_COMMENT_NODE) {
                $parent->removeChild($node);
                continue;
            }

            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            /** @var \DOMElement $node */
            $tag = strtolower($node->nodeName);

            if (in_array($tag, self::DROP_SUBTREE_TAGS, true)) {
                $parent->removeChild($node);
                continue;
            }

            // Recurse into children first so unwrapped inner nodes are already clean
            $this->sanitizeDomNode($node, $dom);

            if (!array_key_exists($tag, self::ALLOWED_TAGS)) {
                // Unwrap unknown element but keep its sanitized children
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            $allowedAttrs = self::ALLOWED_TAGS[$tag];
            $existingAttrs = [];
            if ($node->hasAttributes()) {
                foreach ($node->attributes as $attr) {
                    $existingAttrs[] = $attr->name;
                }
            }

            foreach ($existingAttrs as $attrName) {
                $lowerAttr = strtolower($attrName);
                if (!in_array($lowerAttr, $allowedAttrs, true)) {
                    $node->removeAttribute($attrName);
                    continue;
                }

                $val = trim((string)$node->getAttribute($attrName));
                if ($lowerAttr === 'class') {
                    if ($tag === 'figure' && $val === 'story-video-embed') {
                        $node->setAttribute('class', 'story-video-embed');
                    } else {
                        $node->removeAttribute($attrName);
                    }
                } elseif ($lowerAttr === 'href') {
                    if (!$this->isSafeLinkUrl($val)) {
                        $node->removeAttribute($attrName);
                    } else {
                        $node->setAttribute('href', $val);
                    }
                } elseif ($lowerAttr === 'src' && $tag === 'img') {
                    if (!$this->isSafeImageSrc($val)) {
                        $node->removeAttribute($attrName);
                    } else {
                        // Normalize self-hosted story/recipe image URLs to portable root-relative paths
                        if (preg_match('#https?://[^/]+(?:/pantry-rebuild-api/public)?(/(?:images|uploads)/[^\s"\'<>]+)$#i', $val, $m)) {
                            $val = $m[1];
                        }
                        $node->setAttribute('src', $val);
                    }
                } elseif ($lowerAttr === 'src' && $tag === 'iframe') {
                    $ytId = $this->extractYouTubeVideoId($val);
                    if ($ytId === null) {
                        $node->removeAttribute($attrName);
                    } else {
                        $node->setAttribute('src', 'https://www.youtube-nocookie.com/embed/' . $ytId);
                    }
                }
            }

            if ($tag === 'iframe') {
                if (!$node->hasAttribute('src') || trim($node->getAttribute('src')) === '') {
                    $parent->removeChild($node);
                    continue;
                }
                while ($node->firstChild) {
                    $node->removeChild($node->firstChild);
                }
                $node->setAttribute('title', 'YouTube video player');
                $node->setAttribute('frameborder', '0');
                $node->setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
                $node->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
                $node->setAttribute('allowfullscreen', 'allowfullscreen');
                $node->setAttribute('loading', 'lazy');
            }

            if ($tag === 'a') {
                if (!$node->hasAttribute('href') || trim($node->getAttribute('href')) === '') {
                    // Unwrap empty/unsafe anchor
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                    continue;
                }
                $node->setAttribute('target', '_blank');
                $node->setAttribute('rel', 'noopener noreferrer');
            }

            if ($tag === 'img') {
                if (!$node->hasAttribute('src') || trim($node->getAttribute('src')) === '') {
                    $parent->removeChild($node);
                    continue;
                }
                $node->setAttribute('loading', 'lazy');
                if (!$node->hasAttribute('alt')) {
                    $node->setAttribute('alt', 'Story photo');
                }
            }
        }
    }

    /**
     * Validates anchor hrefs: allows http://, https://, mailto:, and root-relative paths.
     */
    private function isSafeLinkUrl(string $url): bool {
        if ($url === '') {
            return false;
        }
        $clean = preg_replace('/[\x00-\x20]+/', '', $url);
        if (preg_match('/^(javascript|vbscript|data|file):/i', (string)$clean)) {
            return false;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }
        return (bool)preg_match('/^https?:\/\/[^\s<>"\']+$/i', $url);
    }

    /**
     * Validates image src attributes: allows http://, https://, and /images/ or /uploads/ paths.
     */
    private function isSafeImageSrc(string $src): bool {
        if ($src === '') {
            return false;
        }
        $clean = preg_replace('/[\x00-\x20]+/', '', $src);
        if (preg_match('/^(javascript|vbscript|data):/i', (string)$clean)) {
            return false;
        }
        if (str_starts_with($src, '/images/') || str_starts_with($src, '/uploads/')) {
            return !str_contains($src, '..');
        }
        return (bool)preg_match('/^https?:\/\/[^\s<>"\']+$/i', $src);
    }

    /**
     * Extracts a valid 11-character YouTube video ID from a watch, share, shorts, or embed URL.
     */
    public function extractYouTubeVideoId(string $url): ?string {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $patterns = [
            '~^(?:https?:)?//(?:www\.|m\.)?youtube(?:-nocookie)?\.com/embed/([a-zA-Z0-9_-]{11})(?:[?&/].*)?$~i',
            '~^(?:https?:)?//(?:www\.|m\.)?youtube\.com/watch\?(?:[^#]*&)?v=([a-zA-Z0-9_-]{11})(?:[&#].*)?$~i',
            '~^(?:https?:)?//youtu\.be/([a-zA-Z0-9_-]{11})(?:[?&/].*)?$~i',
            '~^(?:https?:)?//(?:www\.|m\.)?youtube\.com/shorts/([a-zA-Z0-9_-]{11})(?:[?&/].*)?$~i',
            '~^(?:https?:)?//(?:www\.|m\.)?youtube\.com/live/([a-zA-Z0-9_-]{11})(?:[?&/].*)?$~i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    /**
     * Extracts clean plain text from HTML content.
     */
    public function extractPlainText(string $html): string {
        $spaced = preg_replace('/<(\/p|\/h2|\/h3|\/li|\/blockquote|br\s*\/?)>/i', '$0 ', $html);
        $decoded = html_entity_decode(strip_tags((string)$spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string)preg_replace('/\s+/u', ' ', $decoded));
    }

    /**
     * Calculates estimated reading time in minutes (minimum 1 minute, ~200 words per minute).
     */
    public function calculateReadTimeMinutes(string $html): int {
        $plain = $this->extractPlainText($html);
        if ($plain === '') {
            return 1;
        }
        $words = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = is_array($words) ? count($words) : 0;
        return max(1, (int)ceil($wordCount / 200));
    }

    /**
     * Builds or normalizes a story excerpt (up to 320 characters).
     */
    public function buildExcerpt(string $html, string $customExcerpt = ''): string {
        $candidate = trim((string)preg_replace('/\s+/u', ' ', strip_tags($customExcerpt)));
        if ($candidate === '') {
            $candidate = $this->extractPlainText($html);
        }
        if ($candidate === '') {
            $candidate = 'A community cooking story from The Veggie Vault.';
        }

        if (mb_strlen($candidate, 'UTF-8') <= 315) {
            return $candidate;
        }

        $truncated = mb_substr($candidate, 0, 312, 'UTF-8');
        $lastSpace = mb_strrpos($truncated, ' ', 0, 'UTF-8');
        if ($lastSpace !== false && $lastSpace > 180) {
            $truncated = mb_substr($truncated, 0, $lastSpace, 'UTF-8');
        }
        return rtrim($truncated, " \t\n\r\0\x0B.,;:-") . '...';
    }

    /**
     * Generates a unique SEO-friendly slug for a story.
     */
    public function generateUniqueSlug(string $title, string $recipeTitle = '', ?int $excludeId = null): string {
        $combined = trim($title);
        if ($recipeTitle !== '' && mb_strlen($combined, 'UTF-8') < 45) {
            $combined .= ' ' . $recipeTitle;
        }

        $slugBase = strtolower($combined);
        $slugBase = preg_replace('/[^a-z0-9]+/i', '-', $slugBase);
        $slugBase = trim((string)$slugBase, '-');
        if ($slugBase === '') {
            $slugBase = 'veggie-vault-story';
        }
        if (strlen($slugBase) > 190) {
            $slugBase = rtrim(substr($slugBase, 0, 190), '-');
        }

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $suffix = substr(bin2hex(random_bytes(3)), 0, 4);
            $candidate = $slugBase . '-' . $suffix;

            if ($excludeId !== null && $excludeId > 0) {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM recipe_stories WHERE slug = ? AND id <> ?");
                $stmt->execute([$candidate, $excludeId]);
            } else {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM recipe_stories WHERE slug = ?");
                $stmt->execute([$candidate]);
            }

            if ((int)$stmt->fetchColumn() === 0) {
                return $candidate;
            }
        }

        return $slugBase . '-' . time() . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
    }

    /**
     * Validates a base64-encoded image, runs Gemini Vision NSFW/safety moderation,
     * strips EXIF metadata by re-encoding via GD, and saves to `/images/stories/`.
     *
     * @return array{success: bool, status?: int, message?: string, imageUrl?: string}
     */
    public function processAndSaveStoryImage(string $rawImage, int $userId, string $role = 'inline'): array {
        $rawImage = trim($rawImage);
        if ($rawImage === '') {
            return [
                'success' => false,
                'status' => 400,
                'message' => 'No image data provided.',
            ];
        }

        // Allow already-saved server image paths
        if (
            (str_starts_with($rawImage, '/images/') || str_starts_with($rawImage, '/uploads/'))
            && !str_contains($rawImage, '..')
        ) {
            return [
                'success' => true,
                'imageUrl' => $rawImage,
            ];
        }

        $base64Body = $rawImage;
        if (str_starts_with($rawImage, 'data:image')) {
            $parts = explode(';', $rawImage, 2);
            $dataParts = explode(',', $parts[1] ?? '', 2);
            $base64Body = $dataParts[1] ?? '';
        }

        $decodedData = base64_decode($base64Body, true);
        if ($decodedData === false || $decodedData === '') {
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Invalid base64 image data.',
            ];
        }

        if (strlen($decodedData) > 10 * 1024 * 1024) {
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Story image must be 10 MB or smaller.',
            ];
        }

        $imgInfo = @getimagesizefromstring($decodedData);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $mime = is_array($imgInfo) ? ($imgInfo['mime'] ?? '') : '';
        if ($imgInfo === false || !in_array($mime, $allowedMimes, true)) {
            return [
                'success' => false,
                'status' => 400,
                'message' => 'Story image must be a valid JPG, PNG, WebP, or GIF.',
            ];
        }

        // Run Gemini Vision safety moderation
        $modResult = (new ImageModerationService())->moderateImageBytes($decodedData, $mime, 'story');
        if (empty($modResult['safe'])) {
            return [
                'success' => false,
                'status' => (int)($modResult['status'] ?? 400),
                'message' => $modResult['message'] ?? 'This image did not pass our community safety check.',
            ];
        }

        $uploadDir = __DIR__ . '/../../public/images/stories/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $safeRole = $role === 'cover' ? 'cover' : 'inline';

        // Re-encode through GD to strip all EXIF/GPS metadata and optimize size
        if (function_exists('imagecreatefromstring')) {
            $srcImg = @imagecreatefromstring($decodedData);
            if ($srcImg !== false) {
                $width = imagesx($srcImg);
                $height = imagesy($srcImg);
                $maxWidth = $safeRole === 'cover' ? 1600 : 1200;

                if ($width > $maxWidth && $width > 0) {
                    $targetWidth = $maxWidth;
                    $targetHeight = max(1, (int)round($height * ($maxWidth / $width)));
                } else {
                    $targetWidth = max(1, $width);
                    $targetHeight = max(1, $height);
                }

                $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
                if ($mime === 'image/png' || $mime === 'image/webp' || $mime === 'image/gif') {
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);
                    $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
                    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
                }

                imagecopyresampled($canvas, $srcImg, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

                if (function_exists('imagewebp')) {
                    $filename = time() . '_' . $userId . '_story_' . $safeRole . '_' . bin2hex(random_bytes(4)) . '.webp';
                    $saved = @imagewebp($canvas, $uploadDir . $filename, 84);
                } else {
                    $filename = time() . '_' . $userId . '_story_' . $safeRole . '_' . bin2hex(random_bytes(4)) . '.jpg';
                    $saved = @imagejpeg($canvas, $uploadDir . $filename, 86);
                }

                imagedestroy($srcImg);
                imagedestroy($canvas);

                if ($saved) {
                    return [
                        'success' => true,
                        'imageUrl' => '/images/stories/' . $filename,
                    ];
                }
            }
        }

        // Fallback if GD re-encoding was unavailable
        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        $ext = $mimeToExt[$mime] ?? 'jpg';
        $filename = time() . '_' . $userId . '_story_' . $safeRole . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents($uploadDir . $filename, $decodedData) === false) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Failed to save story image to the server.',
            ];
        }

        return [
            'success' => true,
            'imageUrl' => '/images/stories/' . $filename,
        ];
    }
}
