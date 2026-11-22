<?php

declare(strict_types=1);

namespace Buckie\Storage;

use Buckie\Config\ConfigService;
use Buckie\Errors\BuckieException;

class BucketService
{
    private const INTERNAL_DIR = '.buckie';

    public function __construct(private readonly string $dataDir) {}

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public function create(string $name, string $bucketPath): array
    {
        if (!str_starts_with($bucketPath, '/')) {
            throw BuckieException::badRequest(
                "Bucket path must be absolute: {$bucketPath}",
                'BUCKIE_BUCKET_PATH_INVALID'
            );
        }

        if ($this->find($name) !== null) {
            throw BuckieException::conflict("Bucket '{$name}' already exists", 'BUCKIE_BUCKET_ALREADY_EXISTS');
        }

        // Ensure directory exists
        if (!is_dir($bucketPath) && !mkdir($bucketPath, 0755, true) && !is_dir($bucketPath)) {
            throw BuckieException::badRequest(
                "Cannot create bucket path: {$bucketPath}",
                'BUCKIE_BUCKET_PATH_NOT_WRITABLE'
            );
        }

        // Validate writable
        if (!is_writable($bucketPath)) {
            throw BuckieException::badRequest(
                "Bucket path is not writable: {$bucketPath}",
                'BUCKIE_BUCKET_PATH_NOT_WRITABLE'
            );
        }

        $this->ensureBucketInternals($bucketPath);

        $bucket = [
            'name'      => $name,
            'path'      => $bucketPath,
            'storage'   => 'local',
            'createdAt' => (new \DateTimeImmutable())->format(\DateTime::ATOM),
        ];

        ConfigService::update($this->dataDir, function (array $cfg) use ($bucket, $name): array {
            foreach ($cfg['buckets'] as $existing) {
                if ($existing['name'] === $name) {
                    throw BuckieException::conflict("Bucket '{$name}' already exists", 'BUCKIE_BUCKET_ALREADY_EXISTS');
                }
            }
            $cfg['buckets'][] = $bucket;
            return $cfg;
        });

        return $bucket;
    }

    public function find(string $name): ?array
    {
        $cfg = ConfigService::readConfig($this->dataDir);
        foreach ($cfg['buckets'] as $bucket) {
            if ($bucket['name'] === $name) {
                return $bucket;
            }
        }
        return null;
    }

    public function get(string $name): array
    {
        $bucket = $this->find($name);
        if ($bucket === null) {
            throw BuckieException::notFound("Bucket '{$name}'");
        }
        return $bucket;
    }

    public function list(): array
    {
        $cfg = ConfigService::readConfig($this->dataDir);
        return $cfg['buckets'];
    }

    public function delete(string $name): void
    {
        ConfigService::update($this->dataDir, function (array $cfg) use ($name): array {
            $before = count($cfg['buckets']);

            $cfg['buckets'] = array_values(
                array_filter($cfg['buckets'], fn($b) => $b['name'] !== $name)
            );

            if (count($cfg['buckets']) === $before) {
                throw BuckieException::notFound("Bucket '{$name}'");
            }

            return $cfg;
        });
    }

    // ── Path resolution ───────────────────────────────────────────────────────

    /**
     * Resolve a key to an absolute path within the bucket.
     * Guards against path traversal and access to internal directories.
     */
    public function resolveKey(array $bucket, string $key): string
    {
        if (str_contains($key, "\0")) {
            throw BuckieException::badRequest('Invalid key', 'BUCKIE_PATH_INVALID');
        }

        // Normalize: strip leading slash, then normalize dots/double-slashes
        $normalized = ltrim($key, '/');
        $normalized = implode('/', array_filter(
            explode('/', $normalized),
            fn($p) => $p !== '' && $p !== '.'
        ));

        // Block path traversal components and the internal directory
        // (case-insensitive, so case-insensitive filesystems cannot bypass it)
        foreach (explode('/', $normalized) as $part) {
            if ($part === '..') {
                throw BuckieException::pathTraversal();
            }
            if (strcasecmp($part, self::INTERNAL_DIR) === 0) {
                throw BuckieException::pathReserved(self::INTERNAL_DIR);
            }
        }

        $realRoot   = realpath($bucket['path']);
        $bucketRoot = rtrim($realRoot !== false ? $realRoot : $bucket['path'], '/');
        $resolved   = $normalized === ''
            ? $bucketRoot
            : $bucketRoot . '/' . $normalized;

        // Resolved path must start with bucketRoot
        if ($resolved !== $bucketRoot && !str_starts_with($resolved, $bucketRoot . '/')) {
            throw BuckieException::pathTraversal();
        }

        // Symlink guard: the deepest existing ancestor must still live inside the bucket
        if ($realRoot !== false && $resolved !== $bucketRoot) {
            $probe = $resolved;
            while (!file_exists($probe) && !is_link($probe) && $probe !== $bucketRoot) {
                $probe = dirname($probe);
            }
            $realProbe = realpath($probe);
            if ($realProbe === false
                || ($realProbe !== $bucketRoot && !str_starts_with($realProbe, $bucketRoot . '/'))
            ) {
                throw BuckieException::pathTraversal();
            }
        }

        return $resolved;
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    public function ensureBucketInternals(string $bucketPath): void
    {
        $staging = $bucketPath . '/' . self::INTERNAL_DIR . '/staging';
        if (!is_dir($staging) && !mkdir($staging, 0750, true) && !is_dir($staging)) {
            throw new \RuntimeException("Cannot create staging directory: {$staging}");
        }
    }

    public function getStagingDir(array $bucket): string
    {
        return $bucket['path'] . '/' . self::INTERNAL_DIR . '/staging';
    }

    public function validateStartup(): void
    {
        foreach ($this->list() as $bucket) {
            if (!is_dir($bucket['path'])) {
                fwrite(STDERR, "[Buckie] Warning: bucket '{$bucket['name']}' path '{$bucket['path']}' does not exist\n");
                continue;
            }
            $this->ensureBucketInternals($bucket['path']);
        }
    }
}


