<?php

declare(strict_types=1);

namespace Buckie\Storage;

use Buckie\Errors\BuckieException;

class LocalStorageService
{
    private const MIME_MAP = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'pdf'  => 'application/pdf',
        'json' => 'application/json',
        'txt'  => 'text/plain',
        'html' => 'text/html',
        'htm'  => 'text/html',
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'xml'  => 'application/xml',
        'zip'  => 'application/zip',
        'tar'  => 'application/x-tar',
        'gz'   => 'application/gzip',
        'mp4'  => 'video/mp4',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'ogg'  => 'audio/ogg',
        'webm' => 'video/webm',
        'ico'  => 'image/x-icon',
        'bmp'  => 'image/bmp',
        'tiff' => 'image/tiff',
        'tif'  => 'image/tiff',
        'avif' => 'image/avif',
    ];

    public function __construct(private readonly BucketService $bucketService) {}

    // ── Download ──────────────────────────────────────────────────────────────

    /**
     * Returns metadata needed to stream a file.
     * Caller is responsible for sending headers and calling streamFile().
     */
    public function getFileInfo(array $bucket, string $key): array
    {
        $filePath = $this->bucketService->resolveKey($bucket, $key);

        if (!file_exists($filePath)) {
            throw BuckieException::notFound("Object '{$key}' in bucket '{$bucket['name']}'");
        }

        if (is_dir($filePath)) {
            throw BuckieException::badRequest("'{$key}' is a directory, not a file", 'BUCKIE_OBJECT_IS_DIRECTORY');
        }

        $stat     = stat($filePath);
        $mimeType = $this->detectMimeType($filePath);
        $etag     = '"' . $stat['size'] . '-' . $stat['mtime'] . '"';

        return [
            'path'         => $filePath,
            'size'         => $stat['size'],
            'mimeType'     => $mimeType,
            'lastModified' => gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT',
            'etag'         => $etag,
        ];
    }

    /**
     * Stream a local file to the current output buffer.
     */
    public function streamFile(string $filePath): void
    {
        $fp = fopen($filePath, 'rb');
        if ($fp === false) {
            throw BuckieException::notFound("Cannot open file for streaming");
        }
        fpassthru($fp);
        fclose($fp);
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    /**
     * Write the request body to a staging file, then atomically rename to final path.
     * Returns ['size' => int, 'etag' => string].
     */
    public function upload(array $bucket, string $key, $inputStream, bool $overwrite = true): array
    {
        $finalPath  = $this->bucketService->resolveKey($bucket, $key);
        $stagingDir = $this->bucketService->getStagingDir($bucket);
        $stagingFile = $stagingDir . '/' . bin2hex(random_bytes(8)) . '.tmp';

        // Ensure parent directory exists
        $parentDir = dirname($finalPath);
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        // Write to staging
        $out = fopen($stagingFile, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Cannot open staging file for writing: {$stagingFile}");
        }

        $bytesWritten = 0;
        try {
            while (!feof($inputStream)) {
                $chunk        = fread($inputStream, 65536);
                if ($chunk === false) {
                    break;
                }
                $written      = fwrite($out, $chunk);
                $bytesWritten += $written;
            }
        } finally {
            fclose($out);
        }

        // Atomic move to final destination
        if (!rename($stagingFile, $finalPath)) {
            @unlink($stagingFile);
            throw new \RuntimeException("Failed to commit upload to '{$finalPath}'");
        }

        $etag = '"' . $bytesWritten . '-' . time() . '"';

        return ['size' => $bytesWritten, 'etag' => $etag];
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function delete(array $bucket, string $key): void
    {
        $filePath = $this->bucketService->resolveKey($bucket, $key);

        if (!file_exists($filePath)) {
            throw BuckieException::notFound("Object '{$key}' in bucket '{$bucket['name']}'");
        }

        if (is_dir($filePath)) {
            throw BuckieException::badRequest("'{$key}' is a directory and cannot be deleted", 'BUCKIE_OBJECT_IS_DIRECTORY');
        }

        unlink($filePath);
    }

    // ── List directory ────────────────────────────────────────────────────────

    public function listDirectory(array $bucket, string $keyPrefix, string $filter = 'all'): array
    {
        $dirPath = $this->bucketService->resolveKey($bucket, $keyPrefix ?: '/');

        if (!file_exists($dirPath)) {
            throw BuckieException::notFound("Directory '{$keyPrefix}' in bucket '{$bucket['name']}'");
        }

        if (!is_dir($dirPath)) {
            throw BuckieException::badRequest("'{$keyPrefix}' is not a directory", 'BUCKIE_NOT_A_DIRECTORY');
        }

        $entries = [];
        $items   = scandir($dirPath);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.buckie') {
                continue;
            }
            $entryPath   = $dirPath . '/' . $item;
            $isDir       = is_dir($entryPath);

            if ($filter === 'files' && $isDir) {
                continue;
            }
            if ($filter === 'dirs' && !$isDir) {
                continue;
            }

            $stat        = stat($entryPath);
            $relativeKey = ($keyPrefix ? rtrim($keyPrefix, '/') . '/' : '') . $item;

            $entries[] = [
                'key'          => $isDir ? $relativeKey . '/' : $relativeKey,
                'size'         => $stat['size'],
                'lastModified' => gmdate(\DateTime::ATOM, $stat['mtime']),
                'isDirectory'  => $isDir,
            ];
        }

        return [
            'bucket'  => $bucket['name'],
            'prefix'  => $keyPrefix ?: '/',
            'filter'  => $filter,
            'entries' => $entries,
            'hasMore' => false,
            'total'   => count($entries),
        ];
    }

    // ── List bucket (paginated, first-level only) ─────────────────────────────

    public function listBucket(array $bucket, int $limit = 100, ?string $cursor = null, string $filter = 'all'): array
    {
        $dirPath = $bucket['path'];
        $items   = @scandir($dirPath);
        if ($items === false) {
            $items = [];
        }

        $allEntries = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.buckie') {
                continue;
            }
            $entryPath = $dirPath . '/' . $item;
            $isDir     = is_dir($entryPath);

            if ($filter === 'files' && $isDir) {
                continue;
            }
            if ($filter === 'dirs' && !$isDir) {
                continue;
            }

            $stat         = stat($entryPath);
            $allEntries[] = [
                'key'          => $isDir ? $item . '/' : $item,
                'size'         => $stat['size'],
                'lastModified' => gmdate(\DateTime::ATOM, $stat['mtime']),
                'isDirectory'  => $isDir,
            ];
        }

        $startIdx = 0;
        if ($cursor !== null) {
            foreach ($allEntries as $i => $entry) {
                if ($entry['key'] === $cursor) {
                    $startIdx = $i + 1;
                    break;
                }
            }
        }

        $page       = array_slice($allEntries, $startIdx, $limit);
        $hasMore    = ($startIdx + $limit) < count($allEntries);
        $nextCursor = $hasMore && !empty($page) ? $page[count($page) - 1]['key'] : null;

        return [
            'bucket'  => $bucket['name'],
            'prefix'  => '/',
            'filter'  => $filter,
            'entries' => $page,
            'cursor'  => $nextCursor,
            'hasMore' => $hasMore,
            'total'   => count($allEntries),
        ];
    }

    // ── MIME detection ────────────────────────────────────────────────────────

    public function detectMimeType(string $filePath): string
    {
        // Try finfo first (magic bytes)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $filePath);
                if (PHP_VERSION_ID < 80500) {
                    finfo_close($finfo);
                }
                if ($mime !== false && $mime !== 'application/octet-stream') {
                    return $mime;
                }
            }
        }

        return $this->mimeFromExtension($filePath);
    }

    public function isImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/') && $mimeType !== 'image/svg+xml';
    }


    private function mimeFromExtension(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return self::MIME_MAP[$ext] ?? 'application/octet-stream';
    }
}

