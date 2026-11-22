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
     * Caller is responsible for sending headers and streaming the file.
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

    // ── Upload ────────────────────────────────────────────────────────────────

    /**
     * Write the request body to a staging file, then atomically rename to final path.
     *
     * @param int      $maxBytes      Maximum accepted size; 0 disables the limit.
     * @param int|null $contentLength Declared size; the upload is rejected if the body differs.
     * @return array{size: int, etag: string}
     */
    public function upload(
        array $bucket,
        string $key,
        $inputStream,
        bool $overwrite = true,
        int $maxBytes = 0,
        ?int $contentLength = null,
    ): array {
        $finalPath = $this->bucketService->resolveKey($bucket, $key);

        if ($maxBytes > 0 && $contentLength !== null && $contentLength > $maxBytes) {
            throw BuckieException::payloadTooLarge($maxBytes);
        }
        if (is_dir($finalPath)) {
            throw BuckieException::badRequest("'{$key}' is a directory", 'BUCKIE_OBJECT_IS_DIRECTORY');
        }
        if (!$overwrite && file_exists($finalPath)) {
            throw BuckieException::conflict("Object '{$key}' already exists", 'BUCKIE_OBJECT_ALREADY_EXISTS');
        }

        $this->bucketService->ensureBucketInternals($bucket['path']);
        $stagingFile = $this->bucketService->getStagingDir($bucket) . '/' . bin2hex(random_bytes(8)) . '.tmp';

        // Ensure parent directory exists (resolveKey already proved it stays inside the bucket)
        $parentDir = dirname($finalPath);
        if (!is_dir($parentDir) && !mkdir($parentDir, 0755, true) && !is_dir($parentDir)) {
            throw new \RuntimeException("Cannot create directory for '{$key}'");
        }

        $out = fopen($stagingFile, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Cannot open staging file for writing');
        }

        $bytesWritten = 0;
        try {
            while (!feof($inputStream)) {
                $chunk = fread($inputStream, 65536);
                if ($chunk === false) {
                    throw new \RuntimeException('Failed to read the request body');
                }
                if ($chunk === '') {
                    continue;
                }

                $bytesWritten += strlen($chunk);
                if ($maxBytes > 0 && $bytesWritten > $maxBytes) {
                    throw BuckieException::payloadTooLarge($maxBytes);
                }

                // fwrite may write fewer bytes than requested (disk full); treat as failure
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Failed to write upload to disk (disk full?)');
                }
            }

            if ($contentLength !== null && $contentLength !== $bytesWritten) {
                throw BuckieException::badRequest(
                    "Body size ({$bytesWritten}) does not match Content-Length ({$contentLength})",
                    'BUCKIE_UPLOAD_INCOMPLETE'
                );
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($stagingFile);
            throw $e;
        }
        fclose($out);

        // Atomic move to final destination
        if (!rename($stagingFile, $finalPath)) {
            @unlink($stagingFile);
            throw new \RuntimeException('Failed to commit upload');
        }

        // Same ETag formula as getFileInfo(), so uploads and reads agree
        clearstatcache(true, $finalPath);
        $stat = stat($finalPath);

        return ['size' => $bytesWritten, 'etag' => '"' . $stat['size'] . '-' . $stat['mtime'] . '"'];
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

    /** Content a browser could execute if served inline from the API origin. */
    public static function isActiveContent(string $mimeType): bool
    {
        return in_array($mimeType, [
            'text/html',
            'application/xhtml+xml',
            'image/svg+xml',
            'text/javascript',
            'application/javascript',
            'application/xml',
            'text/xml',
        ], true);
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

