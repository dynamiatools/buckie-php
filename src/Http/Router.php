<?php

declare(strict_types=1);

namespace Buckie\Http;

use Buckie\Auth\IdentityService;
use Buckie\Config\ConfigService;
use Buckie\Errors\BuckieException;
use Buckie\Logging\OperationalLogger;
use Buckie\Storage\BucketService;
use Buckie\Storage\LocalStorageService;
use Buckie\Thumbnail\ThumbnailService;

class Router
{
    private IdentityService   $identityService;
    private BucketService     $bucketService;
    private LocalStorageService $storageService;
    private ThumbnailService  $thumbnailService;
    private OperationalLogger $logger;

    public function __construct(private readonly string $dataDir)
    {
        ConfigService::ensureDataDirs($dataDir);

        $this->identityService  = new IdentityService($dataDir);
        $this->bucketService    = new BucketService($dataDir);
        $this->storageService   = new LocalStorageService($this->bucketService);
        $this->thumbnailService = new ThumbnailService($this->bucketService, $this->storageService);
        $this->logger           = new OperationalLogger($dataDir);
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI']    ?? '/';

        // Strip query string from path
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path = '/' . ltrim(rawurldecode($path), '/');

        try {
            if ($path === '/health' && $method === 'GET') {
                $this->sendJson(['ok' => true, 'data' => ['status' => 'ok', 'ts' => date(\DateTime::ATOM)]]);
                return;
            }

            // Require auth for all other routes
            $identity = $this->authenticate();

            // Parse /{bucket}[/{key...}]
            $parts      = explode('/', ltrim($path, '/'), 2);
            $bucketName = $parts[0] ?? '';
            $key        = $parts[1] ?? '';

            if ($bucketName === '') {
                $this->sendError('BUCKIE_BAD_REQUEST', 'Bucket name is required', 400);
                return;
            }

            $start = microtime(true);
            $ip    = $_SERVER['HTTP_X_FORWARDED_FOR']
                  ?? $_SERVER['REMOTE_ADDR']
                  ?? 'unknown';

            match ($method) {
                'GET'    => $this->handleGet($identity, $bucketName, $key, $start, $ip),
                'HEAD'   => $this->handleHead($identity, $bucketName, $key, $start, $ip),
                'PUT'    => $this->handlePut($identity, $bucketName, $key, $start, $ip),
                'DELETE' => $this->handleDelete($identity, $bucketName, $key, $start, $ip),
                default  => $this->sendError('BUCKIE_METHOD_NOT_ALLOWED', "Method '{$method}' not allowed", 405),
            };
        } catch (BuckieException $e) {
            $this->logger->logError('request_error', $e);
            $this->sendJson($e->toArray(), $e->getHttpStatus());
        } catch (\Throwable $e) {
            $this->logger->logError('internal_error', $e);
            $this->sendJson([
                'ok'    => false,
                'error' => ['code' => 'BUCKIE_INTERNAL_ERROR', 'message' => 'Internal server error'],
            ], 500);
        }
    }

    // ── Route handlers ────────────────────────────────────────────────────────

    private function handleGet(array $identity, string $bucketName, string $key, float $start, string $ip): void
    {
        $bucket = $this->bucketService->get($bucketName);

        // Bucket listing (no key or key is exactly empty → GET /:bucket?limit=&cursor=&filter=)
        if ($key === '') {
            $this->checkPermission($identity, $bucketName, '/', 'read');

            $limit  = (int) ($_GET['limit']  ?? 100);
            $limit  = min(max($limit, 1), 1000);
            $cursor = $_GET['cursor'] ?? null;
            $filter = $_GET['filter'] ?? 'all';
            if (!in_array($filter, ['files', 'dirs'], true)) {
                $filter = 'all';
            }

            $result = $this->storageService->listBucket($bucket, $limit, $cursor, $filter);

            $this->logger->log([
                'event'     => 'list',
                'identity'  => $identity['identity'],
                'bucket'    => $bucketName,
                'filter'    => $filter,
                'elapsedMs' => $this->elapsed($start),
                'ip'        => $ip,
            ]);

            $this->sendJson(['ok' => true, 'data' => $result]);
            return;
        }

        // Directory listing
        if (str_ends_with($key, '/')) {
            $this->checkPermission($identity, $bucketName, $key, 'read');

            $filter = $_GET['filter'] ?? 'all';
            if (!in_array($filter, ['files', 'dirs'], true)) {
                $filter = 'all';
            }

            $result = $this->storageService->listDirectory($bucket, $key, $filter);

            $this->logger->log([
                'event'     => 'list',
                'identity'  => $identity['identity'],
                'bucket'    => $bucketName,
                'key'       => $key,
                'filter'    => $filter,
                'elapsedMs' => $this->elapsed($start),
                'ip'        => $ip,
            ]);

            $this->sendJson(['ok' => true, 'data' => $result]);
            return;
        }

        $this->checkPermission($identity, $bucketName, $key, 'read');

        // Thumbnail request
        $w      = isset($_GET['w']) ? (int) $_GET['w'] : 0;
        $h      = isset($_GET['h']) ? (int) $_GET['h'] : 0;
        $fit    = $_GET['fit']    ?? 'cover';
        $format = $_GET['format'] ?? 'webp';

        if ($w > 0 || $h > 0) {
            $thumbPath = $this->thumbnailService->getThumbnailPath($bucket, $key, $w, $h, $fit, $format);

            if ($thumbPath !== null) {
                $mimeType = $this->thumbnailService->getMimeType($format);
                $size     = filesize($thumbPath);

                $this->emitHeader('Content-Type', $mimeType);
                $this->emitHeader('Content-Length', (string) $size);
                $this->emitHeader('Cache-Control', 'public, max-age=86400');

                $this->logger->log([
                    'event'     => 'download',
                    'identity'  => $identity['identity'],
                    'bucket'    => $bucketName,
                    'key'       => $key . '?thumbnail',
                    'size'      => $size,
                    'elapsedMs' => $this->elapsed($start),
                    'ip'        => $ip,
                ]);

                $this->streamFile($thumbPath);
                return;
            }
            // Fall through to original file if thumbnail generation fails
        }

        // Regular file download
        $info = $this->storageService->getFileInfo($bucket, $key);

        $this->emitHeader('Content-Type', $info['mimeType']);
        $this->emitHeader('Content-Length', (string) $info['size']);
        $this->emitHeader('ETag', $info['etag']);
        $this->emitHeader('Last-Modified', $info['lastModified']);
        $this->emitHeader('Cache-Control', 'private, max-age=0');

        $this->logger->log([
            'event'     => 'download',
            'identity'  => $identity['identity'],
            'bucket'    => $bucketName,
            'key'       => $key,
            'size'      => $info['size'],
            'elapsedMs' => $this->elapsed($start),
            'ip'        => $ip,
        ]);

        $this->streamFile($info['path']);
    }

    private function handleHead(array $identity, string $bucketName, string $key, float $start, string $ip): void
    {
        if ($key === '') {
            $this->sendError('BUCKIE_PATH_INVALID', 'Key is required for HEAD', 400);
            return;
        }

        $this->checkPermission($identity, $bucketName, $key, 'read');
        $bucket = $this->bucketService->get($bucketName);

        $info = $this->storageService->getFileInfo($bucket, $key);

        $this->setStatusCode(200);
        $this->emitHeader('Content-Type', $info['mimeType']);
        $this->emitHeader('Content-Length', (string) $info['size']);
        $this->emitHeader('ETag', $info['etag']);
        $this->emitHeader('Last-Modified', $info['lastModified']);
        $this->emitHeader('Cache-Control', 'private, max-age=0');

        $this->logger->log([
            'event'     => 'head',
            'identity'  => $identity['identity'],
            'bucket'    => $bucketName,
            'key'       => $key,
            'size'      => $info['size'],
            'elapsedMs' => $this->elapsed($start),
            'ip'        => $ip,
        ]);
    }

    private function handlePut(array $identity, string $bucketName, string $key, float $start, string $ip): void
    {
        if ($key === '') {
            $this->sendError('BUCKIE_PATH_INVALID', 'Key is required for upload', 400);
            return;
        }

        $this->checkPermission($identity, $bucketName, $key, 'write');
        $bucket = $this->bucketService->get($bucketName);

        $this->logger->log([
            'event'    => 'upload_started',
            'identity' => $identity['identity'],
            'bucket'   => $bucketName,
            'key'      => $key,
            'ip'       => $ip,
        ]);

        $inputStream = fopen('php://input', 'rb');
        if ($inputStream === false) {
            throw new \RuntimeException('Cannot open request body');
        }

        try {
            $result = $this->storageService->upload($bucket, $key, $inputStream);
        } finally {
            fclose($inputStream);
        }

        $this->logger->log([
            'event'     => 'upload_committed',
            'identity'  => $identity['identity'],
            'bucket'    => $bucketName,
            'key'       => $key,
            'size'      => $result['size'],
            'elapsedMs' => $this->elapsed($start),
            'ip'        => $ip,
        ]);

        $this->sendJson(['ok' => true, 'data' => [
            'bucket' => $bucketName,
            'key'    => $key,
            'size'   => $result['size'],
            'etag'   => $result['etag'],
        ]], 201);
    }

    private function handleDelete(array $identity, string $bucketName, string $key, float $start, string $ip): void
    {
        if ($key === '') {
            $this->sendError('BUCKIE_PATH_INVALID', 'Key is required for delete', 400);
            return;
        }

        $this->checkPermission($identity, $bucketName, $key, 'delete');
        $bucket = $this->bucketService->get($bucketName);

        $this->storageService->delete($bucket, $key);

        $this->logger->log([
            'event'     => 'delete',
            'identity'  => $identity['identity'],
            'bucket'    => $bucketName,
            'key'       => $key,
            'elapsedMs' => $this->elapsed($start),
            'ip'        => $ip,
        ]);

        $this->sendJson(['ok' => true, 'data' => ['bucket' => $bucketName, 'key' => $key, 'deleted' => true]]);
    }

    // ── Authentication ────────────────────────────────────────────────────────

    private function authenticate(): array
    {
        $identityName = null;
        $secret       = null;

        // X-Buckie-Identity / X-Buckie-Secret headers
        if (isset($_SERVER['HTTP_X_BUCKIE_IDENTITY'], $_SERVER['HTTP_X_BUCKIE_SECRET'])) {
            $identityName = $_SERVER['HTTP_X_BUCKIE_IDENTITY'];
            $secret       = $_SERVER['HTTP_X_BUCKIE_SECRET'];
        }
        // HTTP Basic Auth
        elseif (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            $identityName = $_SERVER['PHP_AUTH_USER'];
            $secret       = $_SERVER['PHP_AUTH_PW'];
        }
        // Authorization: Basic <base64>
        elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
            if (str_starts_with($auth, 'Basic ')) {
                $decoded = base64_decode(substr($auth, 6));
                if ($decoded !== false && str_contains($decoded, ':')) {
                    [$identityName, $secret] = explode(':', $decoded, 2);
                }
            }
        }

        if ($identityName === null || $secret === null) {
            throw BuckieException::unauthorized();
        }

        $identity = $this->identityService->verify($identityName, $secret);
        if ($identity === null) {
            throw BuckieException::unauthorized('Invalid identity or secret');
        }

        return $identity;
    }

    private function checkPermission(array $identity, string $bucket, string $key, string $permission): void
    {
        $grant = $this->identityService->checkPermission($identity, $bucket, $key, $permission);
        if ($grant === null) {
            $this->logger->logError('permission_denied', sprintf(
                "Identity '%s' lacks '%s' on '%s/%s'",
                $identity['identity'],
                $permission,
                $bucket,
                $key,
            ));
            throw BuckieException::forbidden(
                "Permission '{$permission}' denied on bucket '{$bucket}' for path '{$key}'"
            );
        }
    }

    // ── Response helpers ──────────────────────────────────────────────────────

    protected function sendJson(array $data, int $status = 200): void
    {
        $this->setStatusCode($status);
        $this->emitHeader('Content-Type', 'application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function sendError(string $code, string $message, int $status): void
    {
        $this->sendJson(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }

    protected function setStatusCode(int $code): void
    {
        http_response_code($code);
    }

    protected function emitHeader(string $name, string $value): void
    {
        header("{$name}: {$value}");
    }

    private function streamFile(string $path): void
    {
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            throw BuckieException::notFound("Cannot stream file: {$path}");
        }
        fpassthru($fp);
        fclose($fp);
    }

    private function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}

