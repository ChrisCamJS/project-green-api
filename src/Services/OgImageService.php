<?php
namespace App\Services;

/**
 * Shared GD image-compositing helpers for Open Graph share previews.
 */
class OgImageService {
    /**
     * Resolves a local /images or /uploads path, data URI, or remote HTTP(S) URL into a GD image resource.
     */
    public static function loadImageResource(?string $rawSource, ?string $fallbackPath = null) {
        $raw = trim((string)$rawSource);
        $bytes = null;

        if ($raw !== '' && strpos($raw, 'default-veggie-vault-placeholder') === false) {
            if (preg_match('#^data:image/(?:png|jpe?g|webp|gif);base64,(.+)$#is', $raw, $m)) {
                $decoded = base64_decode($m[1], true);
                if ($decoded !== false) {
                    $bytes = $decoded;
                }
            } else {
                $pathPart = $raw;
                if (preg_match('#^https?://#i', $raw)) {
                    $parsed = parse_url($raw);
                    $pathPart = $parsed['path'] ?? '';
                }

                if ($pathPart !== '' && str_starts_with($pathPart, '/')) {
                    $normalized = preg_replace('#^/pantry-rebuild-api/public/#', '/', $pathPart);
                    $roots = array_filter([
                        realpath(__DIR__ . '/../../public'),
                        realpath(dirname(__DIR__, 3) . '/project-green/public'),
                    ]);
                    foreach ($roots as $root) {
                        $candidate = realpath(
                            $root . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $normalized), DIRECTORY_SEPARATOR)
                        );
                        if ($candidate && str_starts_with($candidate, $root) && is_file($candidate) && is_readable($candidate)) {
                            $fileBytes = @file_get_contents($candidate);
                            if ($fileBytes !== false) {
                                $bytes = $fileBytes;
                                break;
                            }
                        }
                    }
                }

                if ($bytes === null && preg_match('#^https?://#i', $raw) && function_exists('curl_init')) {
                    $ch = curl_init($raw);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT => 5,
                        CURLOPT_MAXREDIRS => 3,
                    ]);
                    $resp = curl_exec($ch);
                    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($resp !== false && $code >= 200 && $code < 300) {
                        $bytes = $resp;
                    }
                }
            }
        }

        if ($bytes === null && $fallbackPath !== null && is_file($fallbackPath) && is_readable($fallbackPath)) {
            $fallbackBytes = @file_get_contents($fallbackPath);
            if ($fallbackBytes !== false) {
                $bytes = $fallbackBytes;
            }
        }

        if ($bytes === null) {
            return null;
        }

        $img = @imagecreatefromstring($bytes);
        return $img ?: null;
    }

    /**
     * Resolves the default og-vault-preview.jpg path across API and frontend public folders.
     */
    public static function resolveBaseOgImagePath(): ?string {
        $candidates = [
            __DIR__ . '/../../public/og-vault-preview.jpg',
            dirname(__DIR__, 3) . '/project-green/public/og-vault-preview.jpg',
        ];
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/og-vault-preview.jpg';
        }
        foreach ($candidates as $path) {
            if (is_string($path) && is_file($path) && is_readable($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Center-crops and cover-scales a GD image resource to exact target dimensions.
     */
    public static function createCoverCanvas($src, int $targetW, int $targetH) {
        $srcW = imagesx($src);
        $srcH = imagesy($src);
        if ($srcW <= 0 || $srcH <= 0) {
            return null;
        }
        $scale = max($targetW / $srcW, $targetH / $srcH);
        $cropW = (int)round($targetW / $scale);
        $cropH = (int)round($targetH / $scale);
        $cropX = (int)max(0, floor(($srcW - $cropW) / 2));
        $cropY = (int)max(0, floor(($srcH - $cropH) / 2));

        $canvas = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $src, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);
        return $canvas;
    }

    /**
     * Generates a forest-emerald monogram avatar when a user has no uploaded profile photo.
     */
    public static function createInitialAvatar(string $initial, int $size) {
        $img = imagecreatetruecolor($size, $size);
        for ($y = 0; $y < $size; $y++) {
            $t = $y / max(1, $size - 1);
            $r = (int)round(22 + (13 - 22) * $t);
            $g = (int)round(101 + (59 - 101) * $t);
            $b = (int)round(52 + (40 - 52) * $t);
            imageline($img, 0, $y, $size, $y, imagecolorallocate($img, $r, $g, $b));
        }

        $textColor = imagecolorallocate($img, 240, 253, 244);
        $fontPath = self::resolveBoldFontPath();
        if ($fontPath) {
            $fontSize = (int)round($size * 0.42);
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $initial);
            if ($bbox !== false) {
                $textW = $bbox[2] - $bbox[0];
                $textH = $bbox[1] - $bbox[7];
                $x = (int)round(($size - $textW) / 2 - $bbox[0]);
                $y = (int)round(($size - $textH) / 2 - $bbox[7]);
                imagettftext($img, $fontSize, 0, $x, $y, $textColor, $fontPath, $initial);
                return $img;
            }
        }

        imagestring(
            $img,
            5,
            (int)round(($size - imagefontwidth(5)) / 2),
            (int)round(($size - imagefontheight(5)) / 2),
            $initial,
            $textColor
        );
        return $img;
    }

    /**
     * Center-crops a GD image to a smooth circular avatar of the requested diameter.
     */
    public static function createCircularAvatar($srcImg, int $diameter) {
        $srcW = imagesx($srcImg);
        $srcH = imagesy($srcImg);
        if ($srcW <= 0 || $srcH <= 0) {
            return null;
        }

        $workSize = $diameter * 2;
        $square = imagecreatetruecolor($workSize, $workSize);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagefilledrectangle($square, 0, 0, $workSize, $workSize, imagecolorallocatealpha($square, 255, 255, 255, 0));
        imagealphablending($square, true);

        $cropSide = min($srcW, $srcH);
        $cropX = (int)floor(($srcW - $cropSide) / 2);
        $cropY = (int)floor(($srcH - $cropSide) / 2);
        imagecopyresampled($square, $srcImg, 0, 0, $cropX, $cropY, $workSize, $workSize, $cropSide, $cropSide);

        imagealphablending($square, false);
        $center = ($workSize - 1) / 2.0;
        $outerR = $workSize / 2.0;
        $innerR = $outerR - 2.0;
        $outerR2 = $outerR * $outerR;

        for ($y = 0; $y < $workSize; $y++) {
            $dy = $y - $center;
            $dy2 = $dy * $dy;
            for ($x = 0; $x < $workSize; $x++) {
                $dx = $x - $center;
                $dist2 = ($dx * $dx) + $dy2;
                if ($dist2 >= $outerR2) {
                    $rgb = imagecolorat($square, $x, $y) & 0x00FFFFFF;
                    imagesetpixel($square, $x, $y, (127 << 24) | $rgb);
                } elseif ($dist2 > ($innerR * $innerR)) {
                    $frac = min(1.0, max(0.0, (sqrt($dist2) - $innerR) / ($outerR - $innerR)));
                    $alpha = (int)round($frac * 127);
                    $rgb = imagecolorat($square, $x, $y) & 0x00FFFFFF;
                    imagesetpixel($square, $x, $y, ($alpha << 24) | $rgb);
                }
            }
        }

        $out = imagecreatetruecolor($diameter, $diameter);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefilledrectangle($out, 0, 0, $diameter, $diameter, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $square, 0, 0, 0, 0, $diameter, $diameter, $workSize, $workSize);
        return $out;
    }

    /**
     * Draws a stadium/capsule pill cleanly via a non-overlapping RGBA layer.
     */
    public static function drawCapsule($canvas, int $left, int $top, int $right, int $bottom, int $radius, int $color): void {
        $w = max(1, ($right - $left) + 1);
        $h = max(1, ($bottom - $top) + 1);
        $r = max(1, min($radius, (int)floor(min($w, $h) / 2)));
        $d = $r * 2;

        $rgba = imagecolorsforindex($canvas, $color);
        $tmp = imagecreatetruecolor($w, $h);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        imagefilledrectangle($tmp, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($tmp, 0, 0, 0, 127));

        $localCol = imagecolorallocatealpha($tmp, $rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']);
        imagefilledellipse($tmp, $r, $r, $d, $d, $localCol);
        imagefilledellipse($tmp, $w - 1 - $r, $r, $d, $d, $localCol);
        if (($w - 1) - $d > 0) {
            imagefilledrectangle($tmp, $r, 0, $w - 1 - $r, $h - 1, $localCol);
        }

        imagecopy($canvas, $tmp, $left, $top, 0, 0, $w, $h);
    }

    /**
     * Finds a readable bold TrueType font across Windows and Linux hosts.
     */
    public static function resolveBoldFontPath(): ?string {
        $candidates = [
            'C:/Windows/Fonts/segoeuib.ttf',
            'C:/Windows/Fonts/arialbd.ttf',
            'C:/Windows/Fonts/calibrib.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
        ];
        foreach ($candidates as $fontPath) {
            if (is_file($fontPath) && is_readable($fontPath)) {
                return $fontPath;
            }
        }
        return null;
    }

    /**
     * Sends a rendered GD canvas as a cacheable JPEG response (supporting HEAD probes).
     */
    public static function outputJpeg($canvas, int $quality = 88): void {
        ob_start();
        imagejpeg($canvas, null, $quality);
        $jpegBytes = (string)ob_get_clean();

        if (!headers_sent()) {
            header_remove('Content-Type');
            header_remove('X-Frame-Options');
            header('Access-Control-Allow-Origin: *');
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . strlen($jpegBytes));
            header('Cache-Control: public, max-age=3600');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }
        echo $jpegBytes;
    }
}
