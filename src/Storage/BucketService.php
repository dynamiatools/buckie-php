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
        if (!is_dir($bucketPath)) {
            mkdir($bucketPath, 0755, true);
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

        $cfg = ConfigService::readConfig($this->dataDir);
        $cfg['buckets'][] = $bucket;
        ConfigService::writeConfig($this->dataDir, $cfg);

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
        $cfg    = ConfigService::readConfig($this->dataDir);
        $before = count($cfg['buckets']);

        $cfg['buckets'] = array_values(
            array_filter($cfg['buckets'], fn($b) => $b['name'] !== $name)
        );

        if (count($cfg['buckets']) === $before) {
            throw BuckieException::notFound("Bucket '{$name}'");
        }

        ConfigService::writeConfig($this->dataDir, $cfg);
    }

    // ── Path resolution ───────────────────────────────────────────────────────

    /**
     * Resolve a key to an absolute path within the bucket.
     * Guards against path traversal and access to internal directories.
     */
    public function resolveKey(array $bucket, string $key): string
    {
        // Normalize: strip leading slash, then normalize dots/double-slashes
        $normalized = ltrim($key, '/');
        $normalized = implode('/', array_filter(
            explode('/', $normalized),
            fn($p) => $p !== '' && $p !== '.'
        ));

        // Block path traversal components
        foreach (explode('/', $normalized) as $part) {
            if ($part === '..') {
                throw BuckieException::pathTraversal();
            }
            if ($part === self::INTERNAL_DIR) {
                throw BuckieException::pathReserved(self::INTERNAL_DIR);
            }
        }

        $bucketRoot = rtrim(realpath($bucket['path']) ?: $bucket['path'], '/');
        $resolved   = $normalized === ''
            ? $bucketRoot
            : $bucketRoot . '/' . $normalized;

        // Resolved path must start with bucketRoot
        if ($resolved !== $bucketRoot && !str_starts_with($resolved, $bucketRoot . '/')) {
            throw BuckieException::pathTraversal();
        }

        return $resolved;
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    public function ensureBucketInternals(string $bucketPath): void
    {
        $staging = $bucketPath . '/' . self::INTERNAL_DIR . '/staging';
        if (!is_dir($staging)) {
            mkdir($staging, 0755, true);
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


