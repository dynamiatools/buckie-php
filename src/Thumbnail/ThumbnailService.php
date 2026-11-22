<?php

declare(strict_types=1);

namespace Buckie\Thumbnail;

use Buckie\Config\ConfigService;
use Buckie\Errors\BuckieException;
use Buckie\Storage\BucketService;
use Buckie\Storage\LocalStorageService;

/**
 * On-the-fly thumbnail generator using GD (preferred) or Imagick.
 * Thumbnails are cached OUTSIDE the buckets, in the data directory's cache/
 * folder, under a hashed file name. User input never reaches a file path.
 */
class ThumbnailService
{
    public const MAX_DIMENSION   = 4096;
    public const MAX_SOURCE_PIXELS = 64_000_000;
    public const FORMATS         = ['webp', 'jpeg', 'png'];
    public const FITS            = ['cover', 'contain', 'fill'];

    private bool $gdAvailable;
    private bool $imagickAvailable;
    private string $cacheDir;

    public function __construct(
        private readonly BucketService $bucketService,
        private readonly LocalStorageService $storageService,
        ?string $cacheDir = null,
    ) {
        $this->gdAvailable      = extension_loaded('gd');
        $this->imagickAvailable = extension_loaded('imagick');
        $this->cacheDir         = ($cacheDir ?? ConfigService::getCacheDir(ConfigService::getDataDir())) . '/thumbnails';
    }

    /** Validate and normalize the output format ('jpg' is an alias of 'jpeg'). */
    public static function normalizeFormat(string $format): string
    {
        $format = strtolower($format);
        if ($format === 'jpg') {
            $format = 'jpeg';
        }
        if (!in_array($format, self::FORMATS, true)) {
            throw BuckieException::badRequest(
                "Unsupported thumbnail format. Allowed: " . implode(', ', self::FORMATS),
                'BUCKIE_THUMBNAIL_PARAM_INVALID'
            );
        }
        return $format;
    }

    public static function normalizeFit(string $fit): string
    {
        if (!in_array($fit, self::FITS, true)) {
            throw BuckieException::badRequest(
                "Unsupported fit. Allowed: " . implode(', ', self::FITS),
                'BUCKIE_THUMBNAIL_PARAM_INVALID'
            );
        }
        return $fit;
    }

    public static function validateDimension(int $value, string $name): int
    {
        if ($value < 0 || $value > self::MAX_DIMENSION) {
            throw BuckieException::badRequest(
                "Thumbnail '{$name}' must be between 0 and " . self::MAX_DIMENSION,
                'BUCKIE_THUMBNAIL_PARAM_INVALID'
            );
        }
        return $value;
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
        // Validate every parameter before touching the filesystem
        $width  = self::validateDimension($width, 'w');
        $height = self::validateDimension($height, 'h');
        $fit    = self::normalizeFit($fit);
        $format = self::normalizeFormat($format);

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

        // Cache path: {dataDir}/cache/thumbnails/{hash}.{format}
        $hash      = hash('sha256', implode('|', [$bucket['name'], $originalPath, $width, $height, $fit, $format]));
        $cacheFile = $this->cacheDir . '/' . $hash . '.' . $format;

        // Serve from cache if fresh
        if (is_file($cacheFile) && filemtime($cacheFile) >= filemtime($originalPath)) {
            return $cacheFile;
        }

        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) {
            return null;
        }

        // Generate to a temp file, then rename, so readers never see a partial file
        $tmpFile = $cacheFile . '.tmp.' . bin2hex(random_bytes(4));

        $generated = $this->gdAvailable
            ? $this->generateWithGd($originalPath, $tmpFile, $width, $height, $fit, $format)
            : $this->generateWithImagick($originalPath, $tmpFile, $width, $height, $fit, $format);

        if (!$generated || !rename($tmpFile, $cacheFile)) {
            @unlink($tmpFile);
            return null;
        }

        return $cacheFile;
    }

    public function getMimeType(string $format): string
    {
        return match ($format) {
            'jpeg', 'jpg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            default       => throw BuckieException::badRequest('Unsupported thumbnail format', 'BUCKIE_THUMBNAIL_PARAM_INVALID'),
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
        if ($info === false || ($info[0] * $info[1]) > self::MAX_SOURCE_PIXELS) {
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
            'webp' => function_exists('imagewebp') && imagewebp($dstImg, $dst, 85),
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
        // Do not let Imagick sniff arbitrary coders: only accept plain raster types
        $info = @getimagesize($src);
        if ($info === false
            || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_BMP], true)
            || ($info[0] * $info[1]) > self::MAX_SOURCE_PIXELS
        ) {
            return false;
        }

        try {
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_TIME, 30);
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

            $img->writeImage(($format === 'jpeg' ? 'jpeg' : $format) . ':' . $dst);
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

