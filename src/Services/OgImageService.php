<?php
namespace App\Services;

use PDO;

/**
 * Shared GD image-compositing and static-file caching helpers for Open Graph share previews.
 * Writing rendered 1200x630 baseline JPEGs directly into /public/images/ ensures social crawlers
 * (Facebook, WhatsApp, LinkedIn, X, Discord) fetch static .jpg assets from the exact same
 * directory as standard recipe images, with zero dynamic query-string or first-scrape delays.
 */
class OgImageService {
    private const CACHE_VERSION = 'v4';

    /**
     * Pre-generates static 1200x630 OG JPEG files in /public/images/ for all variant recipes,
     * published stories, and chef profiles once per CACHE_VERSION so files already exist on disk
     * before social media crawlers scrape any share URL.
     */
    public static function warmAllOgImages(
        PDO $db,
        string $apiPublicUrl = 'https://theveggievault.com/pantry-rebuild-api/public'
    ): void {
        if (!extension_loaded('gd')) {
            return;
        }
        [$dirPath] = self::resolveWritableImagesDir();
        if (!$dirPath) {
            return;
        }

        $stampFile = $dirPath . DIRECTORY_SEPARATOR . '.og_warm_' . self::CACHE_VERSION;
        if (is_file($stampFile) && (time() - (int)@filemtime($stampFile)) < 600) {
            return;
        }
        @file_put_contents($stampFile, (string)time());

        try {
            $vStmt = $db->query("
                SELECT id, image_url, image_source, parent_recipe_id, is_masterpiece
                FROM recipes
                WHERE parent_recipe_id IS NOT NULL
            ");
            $variants = $vStmt ? ($vStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($variants as $vRow) {
                self::ensureCachedRecipeOgUrl($vRow, $apiPublicUrl);
            }
        } catch (\Throwable $e) {
            // Non-blocking
        }

        try {
            $sStmt = $db->query("
                SELECT s.id, s.slug, s.cover_image,
                       u.username AS author_name,
                       u.display_name AS author_display_name,
                       u.profile_image AS author_avatar,
                       r.image_url AS recipe_image_url,
                       r.image_source AS recipe_image_source
                FROM recipe_stories s
                LEFT JOIN users u ON u.id = s.user_id
                LEFT JOIN recipes r ON r.id = s.recipe_id
                WHERE s.status = 'published'
            ");
            $stories = $sStmt ? ($sStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($stories as $sRow) {
                self::ensureCachedStoryOgUrl($sRow, $apiPublicUrl);
            }
        } catch (\Throwable $e) {
            // Non-blocking
        }

        try {
            $uStmt = $db->query("SELECT id, username, display_name, profile_image FROM users");
            $users = $uStmt ? ($uStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($users as $uRow) {
                self::ensureCachedProfileOgUrl($db, $uRow, $apiPublicUrl);
            }
        } catch (\Throwable $e) {
            // Non-blocking
        }
    }

    /**
     * Ensures a static 1200x630 JPEG exists directly in /public/images/ for a recipe (variant or WebP)
     * and returns its public HTTPS URL, or null if GD rendering fails.
     */
    public static function ensureCachedRecipeOgUrl(
        array $recipe,
        string $apiPublicUrl = 'https://theveggievault.com/pantry-rebuild-api/public'
    ): ?string {
        if (!extension_loaded('gd')) {
            return null;
        }
        $id = (int)($recipe['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $imgUrl = trim((string)($recipe['image_url'] ?? $recipe['imageUrl'] ?? ''));
        $imgSrc = trim((string)($recipe['image_source'] ?? $recipe['imageSource'] ?? ''));
        $parentId = (int)($recipe['parent_recipe_id'] ?? $recipe['parentRecipeId'] ?? 0);
        $isMasterpiece = !empty($recipe['is_masterpiece']) || !empty($recipe['isMasterpiece']) ? 1 : 0;

        $hash = substr(md5(self::CACHE_VERSION . "|{$id}|{$imgUrl}|{$imgSrc}|{$parentId}|{$isMasterpiece}"), 0, 10);
        $prefix = $parentId > 0 ? 'og_variant' : 'og_recipe';
        $filename = "{$prefix}_{$id}_{$hash}.jpg";

        [$dirPath, $urlSubpath] = self::resolveWritableImagesDir();
        if (!$dirPath) {
            return null;
        }

        $fullPath = $dirPath . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($fullPath) || filesize($fullPath) < 512) {
            $canvas = self::renderRecipeOgCanvas($recipe);
            if (!$canvas || !self::writeBaselineJpegAtomically($canvas, $fullPath, 88)) {
                return null;
            }
        }

        return rtrim($apiPublicUrl, '/') . $urlSubpath . '/' . $filename;
    }

    /**
     * Ensures a static 1200x630 JPEG exists directly in /public/images/ for a published story
     * and returns its public HTTPS URL, or null if GD rendering fails.
     */
    public static function ensureCachedStoryOgUrl(
        array $story,
        string $apiPublicUrl = 'https://theveggievault.com/pantry-rebuild-api/public'
    ): ?string {
        if (!extension_loaded('gd')) {
            return null;
        }
        $id = (int)($story['id'] ?? 0);
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($story['slug'] ?? ''));
        $ident = $id > 0 ? (string)$id : $slug;
        if ($ident === '') {
            return null;
        }

        $cover = trim((string)($story['cover_image'] ?? $story['coverImage'] ?? ''));
        $recipeImg = trim((string)($story['recipe_image_url'] ?? $story['recipeImageUrl'] ?? ($story['recipe']['imageUrl'] ?? '')));
        $avatar = trim((string)($story['author_avatar'] ?? $story['authorAvatar'] ?? ''));
        $author = trim((string)($story['author_name'] ?? $story['authorName'] ?? ''));

        $hash = substr(md5(self::CACHE_VERSION . "|{$ident}|{$cover}|{$recipeImg}|{$avatar}|{$author}"), 0, 10);
        $filename = "og_story_{$ident}_{$hash}.jpg";

        [$dirPath, $urlSubpath] = self::resolveWritableImagesDir();
        if (!$dirPath) {
            return null;
        }

        $fullPath = $dirPath . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($fullPath) || filesize($fullPath) < 512) {
            $canvas = self::renderStoryOgCanvas($story);
            if (!$canvas || !self::writeBaselineJpegAtomically($canvas, $fullPath, 88)) {
                return null;
            }
        }

        return rtrim($apiPublicUrl, '/') . $urlSubpath . '/' . $filename;
    }

    /**
     * Ensures a static 1200x630 JPEG exists directly in /public/images/ for a chef profile
     * (latest published recipe photo or og-vault-preview.jpg overlaid with circular avatar & @username)
     * and returns its public HTTPS URL, or null if GD rendering fails.
     */
    public static function ensureCachedProfileOgUrl(
        PDO $db,
        array $user,
        string $apiPublicUrl = 'https://theveggievault.com/pantry-rebuild-api/public'
    ): ?string {
        if (!extension_loaded('gd')) {
            return null;
        }
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $latestImg = '';
        try {
            $stmt = $db->prepare("
                SELECT image_url
                FROM recipes
                WHERE user_id = ?
                  AND is_public = 1
                  AND image_url IS NOT NULL
                  AND TRIM(image_url) != ''
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['image_url'])) {
                $latestImg = trim((string)$row['image_url']);
            }
        } catch (\Throwable $e) {
            $latestImg = '';
        }

        $avatar = trim((string)($user['profile_image'] ?? ''));
        $uname = trim((string)($user['username'] ?? ''));
        $dname = trim((string)($user['display_name'] ?? ''));

        $hash = substr(md5(self::CACHE_VERSION . "|{$userId}|{$latestImg}|{$avatar}|{$uname}|{$dname}"), 0, 10);
        $filename = "og_profile_{$userId}_{$hash}.jpg";

        [$dirPath, $urlSubpath] = self::resolveWritableImagesDir();
        if (!$dirPath) {
            return null;
        }

        $fullPath = $dirPath . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($fullPath) || filesize($fullPath) < 512) {
            $src = self::loadImageResource($latestImg, self::resolveBaseOgImagePath());
            $canvas = $src ? self::createCoverCanvas($src, 1200, 630) : null;
            if (!$canvas) {
                return null;
            }
            self::drawProfileShowcaseBadge($canvas, $user);
            if (!self::writeBaselineJpegAtomically($canvas, $fullPath, 88)) {
                return null;
            }
        }

        return rtrim($apiPublicUrl, '/') . $urlSubpath . '/' . $filename;
    }

    /**
     * Renders a 1200x630 recipe OG canvas (overlaying the Variant / Masterpiece Variant badge when forked).
     */
    public static function renderRecipeOgCanvas(array $recipe) {
        $imgUrl = trim((string)($recipe['image_url'] ?? $recipe['imageUrl'] ?? ''));
        $imgSrc = trim((string)($recipe['image_source'] ?? $recipe['imageSource'] ?? ''));
        $effectiveImg = ($imgSrc === 'none') ? '' : $imgUrl;

        $src = self::loadImageResource($effectiveImg, self::resolveBaseOgImagePath());
        $canvas = $src ? self::createCoverCanvas($src, 1200, 630) : null;
        if (!$canvas) {
            return null;
        }

        $isVariant = !empty($recipe['parent_recipe_id']) || !empty($recipe['parentRecipeId']);
        if ($isVariant) {
            $isMasterpiece = !empty($recipe['is_masterpiece']) || !empty($recipe['isMasterpiece']);
            self::drawVariantBadge($canvas, $isMasterpiece);
        }

        return $canvas;
    }

    /**
     * Renders a 1200x630 story OG canvas with the Veggie Vault Stories badge and creator avatar + handle.
     */
    public static function renderStoryOgCanvas(array $story) {
        $rawCover = trim((string)($story['cover_image'] ?? $story['coverImage'] ?? ''));
        $rawRecipeImg = trim((string)($story['recipe_image_url'] ?? $story['recipeImageUrl'] ?? ($story['recipe']['imageUrl'] ?? '')));
        $recipeSource = trim((string)($story['recipe_image_source'] ?? $story['recipeImageSource'] ?? ($story['recipe']['imageSource'] ?? '')));
        $selectedImg = $rawCover !== ''
            ? $rawCover
            : ($recipeSource !== 'none' ? $rawRecipeImg : '');

        $src = self::loadImageResource($selectedImg, self::resolveBaseOgImagePath());
        $canvas = $src ? self::createCoverCanvas($src, 1200, 630) : null;
        if (!$canvas) {
            return null;
        }

        self::drawStoriesBadge($canvas);
        self::drawStoryCreatorOverlay($canvas, $story);
        return $canvas;
    }

    /**
     * Resolves the writable /public/images directory (same directory as normal recipe images).
     *
     * @return array{0: ?string, 1: string}
     */
    private static function resolveWritableImagesDir(): array {
        $publicRoot = realpath(__DIR__ . '/../../public');
        if (!$publicRoot) {
            return [null, ''];
        }
        $imagesDir = $publicRoot . DIRECTORY_SEPARATOR . 'images';
        if (!is_dir($imagesDir)) {
            @mkdir($imagesDir, 0755, true);
        }
        if (is_dir($imagesDir) && is_writable($imagesDir)) {
            return [$imagesDir, '/images'];
        }
        return [null, ''];
    }

    /**
     * Draws the circular user avatar (or monogram) and @username pill in the upper-left showcase region.
     */
    public static function drawProfileShowcaseBadge($canvas, array $user): void {
        $cx = 185;
        $cy = 180;
        $avatarDiameter = 216;
        $avatarRadius = (int)($avatarDiameter / 2);
        $whiteRingRadius = $avatarRadius + 5;
        $greenRingRadius = $whiteRingRadius + 5;

        for ($i = 5; $i >= 1; $i--) {
            $alpha = 127 - (int)round((6 - $i) * 8);
            $shadowColor = imagecolorallocatealpha($canvas, 8, 22, 16, max(75, min(124, $alpha)));
            $r = $greenRingRadius + ($i * 3);
            imagefilledellipse($canvas, $cx + 4, $cy + 8, $r * 2, $r * 2, $shadowColor);
        }

        $greenRingColor = imagecolorallocate($canvas, 134, 239, 172);
        imagefilledellipse($canvas, $cx, $cy, $greenRingRadius * 2, $greenRingRadius * 2, $greenRingColor);

        $whiteColor = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledellipse($canvas, $cx, $cy, $whiteRingRadius * 2, $whiteRingRadius * 2, $whiteColor);

        $avatarImg = self::loadImageResource($user['profile_image'] ?? null);
        if (!$avatarImg) {
            $labelSource = trim((string)($user['display_name'] ?: $user['username'] ?: 'V'));
            $initial = strtoupper(substr($labelSource, 0, 1));
            $avatarImg = self::createInitialAvatar($initial !== '' ? $initial : 'V', 432);
        }

        if ($avatarImg) {
            $circular = self::createCircularAvatar($avatarImg, $avatarDiameter);
            if ($circular) {
                imagecopy($canvas, $circular, $cx - $avatarRadius, $cy - $avatarRadius, 0, 0, $avatarDiameter, $avatarDiameter);
            }
        }

        $username = trim((string)($user['username'] ?? ''));
        if ($username !== '') {
            $handleText = '@' . $username;
            if (strlen($handleText) > 22) {
                $handleText = substr($handleText, 0, 21) . '...';
            }

            $centerX = $cx;
            $centerY = $cy + $greenRingRadius + 24;
            $fontPath = self::resolveBoldFontPath();
            $padX = 24;
            $padY = 12;

            if ($fontPath) {
                $fontSize = 18;
                $bbox = imagettfbbox($fontSize, 0, $fontPath, $handleText);
                $textW = $bbox !== false ? ($bbox[2] - $bbox[0]) : strlen($handleText) * 11;
                $textH = $bbox !== false ? ($bbox[1] - $bbox[7]) : 20;
            } else {
                $textW = imagefontwidth(5) * strlen($handleText);
                $textH = imagefontheight(5);
            }

            $pillW = max(140, (int)$textW + ($padX * 2));
            $pillH = max(38, (int)$textH + ($padY * 2));
            $halfW = (int)round($pillW / 2);
            $halfH = (int)round($pillH / 2);
            $left = $centerX - $halfW;
            $right = $centerX + $halfW;
            $top = $centerY - $halfH;
            $bottom = $centerY + $halfH;

            self::drawCapsule($canvas, $left + 2, $top + 4, $right + 2, $bottom + 4, $halfH, imagecolorallocatealpha($canvas, 0, 0, 0, 75));
            self::drawCapsule($canvas, $left - 2, $top - 2, $right + 2, $bottom + 2, $halfH + 2, imagecolorallocate($canvas, 134, 239, 172));
            self::drawCapsule($canvas, $left, $top, $right, $bottom, $halfH, imagecolorallocatealpha($canvas, 17, 56, 40, 12));

            $textColor = imagecolorallocate($canvas, 255, 255, 255);
            if ($fontPath && isset($bbox) && $bbox !== false) {
                $tx = (int)round($centerX - ($textW / 2) - $bbox[0]);
                $ty = (int)round($centerY - ($textH / 2) - $bbox[7]);
                imagettftext($canvas, 18, 0, $tx, $ty, $textColor, $fontPath, $handleText);
            } else {
                $tx = (int)round($centerX - ($textW / 2));
                $ty = (int)round($centerY - ($textH / 2));
                imagestring($canvas, 5, $tx, $ty, $handleText, $textColor);
            }
        }
    }

    /**
     * Overlays the Masterpiece Variant (or Recipe Variant) badge pill in the top-left corner.
     */
    public static function drawVariantBadge($canvas, bool $isMasterpiece): void {
        $label = $isMasterpiece ? 'MASTERPIECE VARIANT' : 'RECIPE VARIANT';
        $fontPath = self::resolveBoldFontPath();
        $fontSize = 20;
        $padX = 28;
        $padY = 16;
        $iconSpace = 30;

        if ($fontPath) {
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $label);
            $textW = $bbox !== false ? ($bbox[2] - $bbox[0]) : strlen($label) * 14;
            $textH = $bbox !== false ? ($bbox[1] - $bbox[7]) : 22;
        } else {
            $textW = imagefontwidth(5) * strlen($label);
            $textH = imagefontheight(5);
        }

        $pillW = (int)$textW + $iconSpace + ($padX * 2);
        $pillH = max(56, (int)$textH + ($padY * 2));
        $left = 36;
        $top = 36;
        $right = $left + $pillW;
        $bottom = $top + $pillH;
        $radius = (int)floor($pillH / 2);

        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 62);
        self::drawCapsule($canvas, $left + 3, $top + 6, $right + 3, $bottom + 6, $radius, $shadow);

        if ($isMasterpiece) {
            $borderColor = imagecolorallocate($canvas, 253, 230, 138); // #fde68a
            $fillColor = imagecolorallocate($canvas, 217, 119, 6);     // #d97706
            $textColor = imagecolorallocate($canvas, 255, 251, 235);   // #fffbeb
        } else {
            $borderColor = imagecolorallocate($canvas, 221, 214, 254); // #ddd6fe
            $fillColor = imagecolorallocate($canvas, 109, 40, 217);    // #6d28d9
            $textColor = imagecolorallocate($canvas, 255, 255, 255);   // #ffffff
        }

        self::drawCapsule($canvas, $left - 3, $top - 3, $right + 3, $bottom + 3, $radius + 3, $borderColor);
        self::drawCapsule($canvas, $left, $top, $right, $bottom, $radius, $fillColor);

        $sx = $left + $padX + 10;
        $sy = (int)round(($top + $bottom) / 2);
        $sparklePts = [
            $sx, $sy - 11,
            $sx + 3, $sy - 3,
            $sx + 11, $sy,
            $sx + 3, $sy + 3,
            $sx, $sy + 11,
            $sx - 3, $sy + 3,
            $sx - 11, $sy,
            $sx - 3, $sy - 3,
        ];
        imagefilledpolygon($canvas, $sparklePts, $textColor);

        $textStartX = $left + $padX + $iconSpace;
        if ($fontPath && isset($bbox) && $bbox !== false) {
            $tx = $textStartX - $bbox[0];
            $ty = (int)round(($top + $bottom) / 2 - ($textH / 2) - $bbox[7]);
            imagettftext($canvas, $fontSize, 0, $tx, $ty, $textColor, $fontPath, $label);
        } else {
            $ty = (int)round(($top + $bottom) / 2 - ($textH / 2));
            imagestring($canvas, 5, $textStartX, $ty, $label, $textColor);
        }
    }

    /**
     * Overlays the "VEGGIE VAULT STORIES" badge in the top-left corner.
     */
    public static function drawStoriesBadge($canvas): void {
        $label = 'VEGGIE VAULT STORIES';
        $fontPath = self::resolveBoldFontPath();
        $fontSize = 19;
        $padX = 26;
        $padY = 15;
        $iconSpace = 30;

        if ($fontPath) {
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $label);
            $textW = $bbox !== false ? ($bbox[2] - $bbox[0]) : strlen($label) * 14;
            $textH = $bbox !== false ? ($bbox[1] - $bbox[7]) : 22;
        } else {
            $textW = imagefontwidth(5) * strlen($label);
            $textH = imagefontheight(5);
        }

        $pillW = (int)$textW + $iconSpace + ($padX * 2);
        $pillH = max(52, (int)$textH + ($padY * 2));
        $left = 36;
        $top = 36;
        $right = $left + $pillW;
        $bottom = $top + $pillH;
        $radius = (int)floor($pillH / 2);

        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 62);
        self::drawCapsule($canvas, $left + 3, $top + 6, $right + 3, $bottom + 6, $radius, $shadow);

        $borderColor = imagecolorallocate($canvas, 134, 239, 172); // #86efac
        $fillColor = imagecolorallocate($canvas, 20, 83, 45);      // #14532d
        $textColor = imagecolorallocate($canvas, 240, 253, 244);   // #f0fdf4

        self::drawCapsule($canvas, $left - 3, $top - 3, $right + 3, $bottom + 3, $radius + 3, $borderColor);
        self::drawCapsule($canvas, $left, $top, $right, $bottom, $radius, $fillColor);

        $bx = $left + $padX + 2;
        $by = (int)round(($top + $bottom) / 2);
        $leftPage = [$bx, $by - 8, $bx + 9, $by - 6, $bx + 9, $by + 9, $bx, $by + 7];
        $rightPage = [$bx + 11, $by - 6, $bx + 20, $by - 8, $bx + 20, $by + 7, $bx + 11, $by + 9];
        imagefilledpolygon($canvas, $leftPage, $textColor);
        imagefilledpolygon($canvas, $rightPage, $textColor);

        $textStartX = $left + $padX + $iconSpace;
        if ($fontPath && isset($bbox) && $bbox !== false) {
            $tx = $textStartX - $bbox[0];
            $ty = (int)round(($top + $bottom) / 2 - ($textH / 2) - $bbox[7]);
            imagettftext($canvas, $fontSize, 0, $tx, $ty, $textColor, $fontPath, $label);
        } else {
            $ty = (int)round(($top + $bottom) / 2 - ($textH / 2));
            imagestring($canvas, 5, $textStartX, $ty, $label, $textColor);
        }
    }

    /**
     * Draws a compact bottom-left creator overlay (circular avatar + handle pill).
     */
    public static function drawStoryCreatorOverlay($canvas, array $story): void {
        $avatarDiameter = 84;
        $avatarRadius = (int)($avatarDiameter / 2);
        $whiteRingR = $avatarRadius + 3;
        $greenRingR = $whiteRingR + 3;
        $cx = 36 + $greenRingR;
        $cy = 630 - 36 - $greenRingR;

        for ($i = 4; $i >= 1; $i--) {
            $alpha = 127 - (int)round((5 - $i) * 9);
            $shadowCol = imagecolorallocatealpha($canvas, 8, 22, 16, max(72, min(124, $alpha)));
            $r = $greenRingR + ($i * 2);
            imagefilledellipse($canvas, $cx + 3, $cy + 5, $r * 2, $r * 2, $shadowCol);
        }

        imagefilledellipse($canvas, $cx, $cy, $greenRingR * 2, $greenRingR * 2, imagecolorallocate($canvas, 134, 239, 172));
        imagefilledellipse($canvas, $cx, $cy, $whiteRingR * 2, $whiteRingR * 2, imagecolorallocate($canvas, 255, 255, 255));

        $rawAvatar = $story['author_avatar'] ?? $story['authorAvatar'] ?? null;
        $avatarSrc = self::loadImageResource($rawAvatar);
        if (!$avatarSrc) {
            $display = trim((string)($story['author_display_name'] ?? $story['authorDisplayName'] ?? ''));
            $uname = trim((string)($story['author_name'] ?? $story['authorName'] ?? 'V'));
            $labelSource = $display !== '' ? $display : ($uname !== '' ? $uname : 'V');
            $initial = strtoupper(substr($labelSource, 0, 1));
            $avatarSrc = self::createInitialAvatar($initial !== '' ? $initial : 'V', 256);
        }

        if ($avatarSrc) {
            $circular = self::createCircularAvatar($avatarSrc, $avatarDiameter);
            if ($circular) {
                imagecopy($canvas, $circular, $cx - $avatarRadius, $cy - $avatarRadius, 0, 0, $avatarDiameter, $avatarDiameter);
            }
        }

        $username = trim((string)($story['author_name'] ?? $story['authorName'] ?? ''));
        if ($username !== '') {
            $handleText = '@' . $username;
            if (strlen($handleText) > 24) {
                $handleText = substr($handleText, 0, 23) . '...';
            }

            $fontPath = self::resolveBoldFontPath();
            $fontSize = 17;
            $padX = 20;
            $padY = 11;
            if ($fontPath) {
                $bbox = imagettfbbox($fontSize, 0, $fontPath, $handleText);
                $textW = $bbox !== false ? ($bbox[2] - $bbox[0]) : strlen($handleText) * 11;
                $textH = $bbox !== false ? ($bbox[1] - $bbox[7]) : 18;
            } else {
                $textW = imagefontwidth(5) * strlen($handleText);
                $textH = imagefontheight(5);
            }

            $pillH = max(40, (int)$textH + ($padY * 2));
            $pillW = max(110, (int)$textW + ($padX * 2));
            $pillLeft = $cx + $greenRingR + 14;
            $pillRight = $pillLeft + $pillW;
            $pillTop = $cy - (int)round($pillH / 2);
            $pillBottom = $pillTop + $pillH;
            $radius = (int)floor($pillH / 2);

            $pillShadow = imagecolorallocatealpha($canvas, 0, 0, 0, 72);
            self::drawCapsule($canvas, $pillLeft + 2, $pillTop + 4, $pillRight + 2, $pillBottom + 4, $radius, $pillShadow);
            self::drawCapsule($canvas, $pillLeft - 2, $pillTop - 2, $pillRight + 2, $pillBottom + 2, $radius + 2, imagecolorallocate($canvas, 134, 239, 172));
            self::drawCapsule($canvas, $pillLeft, $pillTop, $pillRight, $pillBottom, $radius, imagecolorallocatealpha($canvas, 17, 56, 40, 12));

            $white = imagecolorallocate($canvas, 255, 255, 255);
            if ($fontPath && isset($bbox) && $bbox !== false) {
                $tx = $pillLeft + $padX - $bbox[0];
                $ty = (int)round($cy - ($textH / 2) - $bbox[7]);
                imagettftext($canvas, $fontSize, 0, $tx, $ty, $white, $fontPath, $handleText);
            } else {
                $tx = $pillLeft + $padX;
                $ty = (int)round($cy - ($textH / 2));
                imagestring($canvas, 5, $tx, $ty, $handleText, $white);
            }
        }
    }

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
                    if (strpos($normalized, '/images/') !== 0 && strpos($normalized, '/uploads/') !== 0) {
                        $normalized = '/images' . $normalized;
                    }
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
     * Writes a baseline JPEG to a temp file and atomically renames it into place
     * so crawlers never read a partially-written image file.
     */
    private static function writeBaselineJpegAtomically($canvas, string $fullPath, int $quality = 88): bool {
        imageinterlace($canvas, false);
        $tmpPath = $fullPath . '.tmp.' . str_replace('.', '', uniqid('', true));
        if (!@imagejpeg($canvas, $tmpPath, $quality) || !is_file($tmpPath) || filesize($tmpPath) < 512) {
            @unlink($tmpPath);
            return false;
        }
        if (!@rename($tmpPath, $fullPath)) {
            @unlink($tmpPath);
            return false;
        }
        return true;
    }

    /**
     * Sends a rendered GD canvas as a cacheable baseline JPEG response (supporting HEAD probes).
     */
    public static function outputJpeg($canvas, int $quality = 88): void {
        imageinterlace($canvas, false);
        ob_start();
        imagejpeg($canvas, null, $quality);
        $jpegBytes = (string)ob_get_clean();

        if (!headers_sent()) {
            header_remove('Content-Type');
            header_remove('X-Frame-Options');
            header_remove('Access-Control-Allow-Credentials');
            header_remove('Cache-Control');
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
