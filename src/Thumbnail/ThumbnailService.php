<?php

declare(strict_types=1);

namespace Buckie\Thumbnail;

use Buckie\Storage\BucketService;
use Buckie\Storage\LocalStorageService;

/**
 * On-the-fly thumbnail generator using GD (preferred) or Imagick.
 * Thumbnails are cached alongside the originals in {WxH}/ subdirectories.
 */
class ThumbnailService
{
    private bool $gdAvailable;
    private bool $imagickAvailable;

    public function __construct(
        private readonly BucketService $bucketService,
        private readonly LocalStorageService $storageService,
    ) {
        $this->gdAvailable      = extension_loaded('gd');
        $this->imagickAvailable = extension_loaded('imagick');
    }

    /**
     * Get thumbnail path (generate + cache if needed).
     * Returns the cached file path or null if thumbnail generation is not supported.
     */
    public function getThumbnailPath(
        array  $bucket,
        string $key,
        int    $width  = 0,
        int    $height = 0,
        string $fit    = 'cover',
        string $format = 'webp',
    ): ?string {
        if (!$this->gdAvailable && !$this->imagickAvailable) {
            return null;
        }

        $originalPath = $this->bucketService->resolveKey($bucket, $key);
        if (!file_exists($originalPath) || is_dir($originalPath)) {
            return null;
        }

        $mimeType = $this->storageService->detectMimeType($originalPath);
        if (!$this->storageService->isImage($mimeType)) {
            return null;
        }

        // Build cache path: {bucket}/{WxH}/{key}.{format}
        $dimKey     = "{$width}x{$height}";
        $keyPath    = ltrim($key, '/');
        $cacheFile  = dirname($originalPath) . "/{$dimKey}/" . pathinfo($key, PATHINFO_FILENAME) . ".{$format}";
        $cacheDir   = dirname($cacheFile);

        // Serve from cache if fresh
        if (file_exists($cacheFile) && filemtime($cacheFile) >= filemtime($originalPath)) {
            return $cacheFile;
        }

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $generated = $this->gdAvailable
            ? $this->generateWithGd($originalPath, $cacheFile, $width, $height, $fit, $format)
            : $this->generateWithImagick($originalPath, $cacheFile, $width, $height, $fit, $format);

        return $generated ? $cacheFile : null;
    }

    public function getMimeType(string $format): string
    {
        return match ($format) {
            'jpeg', 'jpg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            default       => 'image/jpeg',
        };
    }

    // ── GD ────────────────────────────────────────────────────────────────────

    private function generateWithGd(
        string $src,
        string $dst,
        int    $width,
        int    $height,
        string $fit,
        string $format,
    ): bool {
        $info = @getimagesize($src);
        if ($info === false) {
            return false;
        }

        $srcImg = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
            IMAGETYPE_PNG  => @imagecreatefrompng($src),
            IMAGETYPE_GIF  => @imagecreatefromgif($src),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            IMAGETYPE_BMP  => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($src) : false,
            default        => false,
        };

        if ($srcImg === false) {
            return false;
        }

        $srcW = imagesx($srcImg);
        $srcH = imagesy($srcImg);

        // Resolve target dimensions
        [$dstW, $dstH, $cropX, $cropY, $cropW, $cropH] = $this->calculateDimensions(
            $srcW, $srcH, $width, $height, $fit
        );

        $dstImg = imagecreatetruecolor($dstW, $dstH);
        if ($dstImg === false) {
            imagedestroy($srcImg);
            return false;
        }

        // Preserve transparency for PNG/WEBP
        if (in_array($format, ['png', 'webp'], true)) {
            imagealphablending($dstImg, false);
            imagesavealpha($dstImg, true);
            $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
            imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, $transparent);
            imagealphablending($dstImg, true);
        }

        imagecopyresampled($dstImg, $srcImg, 0, 0, $cropX, $cropY, $dstW, $dstH, $cropW, $cropH);

        $ok = match ($format) {
            'png'  => imagepng($dstImg, $dst, 8),
            'webp' => function_exists('imagewebp') ? imagewebp($dstImg, $dst, 85) : imagejpeg($dstImg, $dst, 85),
            default => imagejpeg($dstImg, $dst, 85),
        };

        imagedestroy($srcImg);
        imagedestroy($dstImg);

        return $ok !== false;
    }

    // ── Imagick ───────────────────────────────────────────────────────────────

    private function generateWithImagick(
        string $src,
        string $dst,
        int    $width,
        int    $height,
        string $fit,
        string $format,
    ): bool {
        try {
            $img = new \Imagick($src);
            $img->setImageFormat($format === 'webp' ? 'webp' : ($format === 'png' ? 'png' : 'jpeg'));

            $srcW = $img->getImageWidth();
            $srcH = $img->getImageHeight();

            [$dstW, $dstH, $cropX, $cropY, $cropW, $cropH] = $this->calculateDimensions(
                $srcW, $srcH, $width, $height, $fit
            );

            if ($fit === 'cover') {
                $img->cropImage($cropW, $cropH, $cropX, $cropY);
            }

            $img->resizeImage($dstW, $dstH, \Imagick::FILTER_LANCZOS, 1);

            if ($format !== 'png' && $format !== 'webp') {
                $img->setImageCompressionQuality(85);
            }

            $img->writeImage($dst);
            $img->destroy();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Dimension calculator ──────────────────────────────────────────────────

    /**
     * Returns [dstW, dstH, cropX, cropY, cropW, cropH]
     */
    private function calculateDimensions(
        int    $srcW,
        int    $srcH,
        int    $reqW,
        int    $reqH,
        string $fit,
    ): array {
        // Fill missing dimension proportionally
        if ($reqW === 0 && $reqH === 0) {
            return [$srcW, $srcH, 0, 0, $srcW, $srcH];
        }

        if ($reqW === 0) {
            $reqW = (int) round($srcW * $reqH / $srcH);
        }

        if ($reqH === 0) {
            $reqH = (int) round($srcH * $reqW / $srcW);
        }

        switch ($fit) {
            case 'fill':
                return [$reqW, $reqH, 0, 0, $srcW, $srcH];

            case 'contain': {
                $ratio  = min($reqW / $srcW, $reqH / $srcH);
                $newW   = (int) round($srcW * $ratio);
                $newH   = (int) round($srcH * $ratio);
                return [$newW, $newH, 0, 0, $srcW, $srcH];
            }

            case 'cover':
            default: {
                $ratio  = max($reqW / $srcW, $reqH / $srcH);
                $cropW  = (int) round($reqW / $ratio);
                $cropH  = (int) round($reqH / $ratio);
                $cropX  = (int) round(($srcW - $cropW) / 2);
                $cropY  = (int) round(($srcH - $cropH) / 2);
                return [$reqW, $reqH, $cropX, $cropY, $cropW, $cropH];
            }
        }
    }
}

