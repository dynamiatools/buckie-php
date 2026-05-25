<?php

declare(strict_types=1);

namespace Buckie\Tests;

use Buckie\Auth\IdentityService;
use Buckie\Config\ConfigService;
use Buckie\Http\Router;
use Buckie\Storage\BucketService;
use Buckie\Storage\LocalStorageService;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the HEAD /:bucket/:key endpoint.
 *
 * Strategy: set up $_SERVER superglobal to simulate HTTP requests,
 * capture output with ob_start/ob_get_clean, and use xdebug_get_headers()
 * or inspect the HTTP response via a custom RouterHarness.
 *
 * Because PHPUnit runs in CLI mode (where headers cannot actually be sent),
 * we test the Router through a RouterHarness that captures headers.
 */
class RouterHeadTest extends TestCase
{
    private string $tempDir;
    private BucketService $bucketService;
    private IdentityService $identityService;
    private LocalStorageService $storageService;

    /** Identity credentials used in tests */
    private const IDENTITY = 'test-user';
    private const SECRET   = 'super-secret';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/buckie-router-test-' . bin2hex(random_bytes(8));
        ConfigService::ensureDataDirs($this->tempDir);

        $this->bucketService   = new BucketService($this->tempDir);
        $this->identityService = new IdentityService($this->tempDir);
        $this->storageService  = new LocalStorageService($this->bucketService);

        // Seed identity with full access
        $this->identityService->create(self::IDENTITY, self::SECRET);
        $this->identityService->grant(self::IDENTITY, [
            'bucket'      => 'test-bucket',
            'prefixes'    => [],
            'permissions' => ['read', 'write', 'delete'],
        ]);
    }

    protected function tearDown(): void
    {
        // Restore superglobals
        $_SERVER = [];
        $_GET    = [];

        $this->removeDir($this->tempDir);
    }

    // ── HEAD /:bucket/:key ────────────────────────────────────────────────────

    public function testHeadExistingFileReturns200(): void
    {
        $bucketPath = $this->tempDir . '/test-bucket';
        $this->bucketService->create('test-bucket', $bucketPath);
        $this->uploadFile($bucketPath, 'hello.txt', 'Hello World');

        $harness = $this->request('HEAD', '/test-bucket/hello.txt');

        $this->assertSame(200, $harness->statusCode);
        $this->assertSame('', $harness->body); // no body for HEAD
        $this->assertNotEmpty($harness->headers['Content-Type']);
        $this->assertSame('11', $harness->headers['Content-Length']); // strlen('Hello World')
        $this->assertNotEmpty($harness->headers['ETag']);
        $this->assertNotEmpty($harness->headers['Last-Modified']);
    }

    public function testHeadMissingFileReturns404(): void
    {
        $this->bucketService->create('test-bucket', $this->tempDir . '/test-bucket');

        $harness = $this->request('HEAD', '/test-bucket/nonexistent.txt');

        $this->assertSame(404, $harness->statusCode);
    }

    public function testHeadWithoutKeyReturns400(): void
    {
        $this->bucketService->create('test-bucket', $this->tempDir . '/test-bucket');

        // HEAD /:bucket (no key) should return 400
        $harness = $this->request('HEAD', '/test-bucket');

        $this->assertSame(400, $harness->statusCode);
    }

    public function testHeadUnauthorizedReturns401(): void
    {
        $this->bucketService->create('test-bucket', $this->tempDir . '/test-bucket');

        $harness = $this->request('HEAD', '/test-bucket/file.txt', auth: false);

        $this->assertSame(401, $harness->statusCode);
    }

    public function testHeadForbiddenReturns403(): void
    {
        // Create a bucket the identity has NO access to
        $this->bucketService->create('test-bucket', $this->tempDir . '/test-bucket');
        $this->uploadFile($this->tempDir . '/test-bucket', 'secret.txt', 'classified');

        // Grant read only to '/allowed/' prefix
        $this->identityService->revoke(self::IDENTITY, 'test-bucket');
        $this->identityService->grant(self::IDENTITY, [
            'bucket'      => 'test-bucket',
            'prefixes'    => ['/allowed/'],
            'permissions' => ['read'],
        ]);

        $harness = $this->request('HEAD', '/test-bucket/secret.txt');

        // Must return 403, NOT 404 — prevents file scanning
        $this->assertSame(403, $harness->statusCode);
    }

    public function testHeadContentTypeDetectedCorrectly(): void
    {
        $bucketPath = $this->tempDir . '/test-bucket';
        $this->bucketService->create('test-bucket', $bucketPath);
        $this->uploadFile($bucketPath, 'image.jpg', str_repeat("\xff\xd8\xff", 10));

        $harness = $this->request('HEAD', '/test-bucket/image.jpg');

        $this->assertSame(200, $harness->statusCode);
        $this->assertStringContainsStringIgnoringCase('image/', $harness->headers['Content-Type']);
    }

    public function testHeadNestedKeyPath(): void
    {
        $bucketPath = $this->tempDir . '/test-bucket';
        $this->bucketService->create('test-bucket', $bucketPath);
        $this->uploadFile($bucketPath, 'a/b/c/deep.txt', 'deep content', createDirs: true);

        $harness = $this->request('HEAD', '/test-bucket/a/b/c/deep.txt');

        $this->assertSame(200, $harness->statusCode);
        $this->assertSame('12', $harness->headers['Content-Length']);
    }

    public function testHeadMissingBucketReturnsForbidden(): void
    {
        // The identity has no grant for 'ghost-bucket', so permission check fires
        // before bucket existence check — returns 403 (prevents bucket enumeration)
        $harness = $this->request('HEAD', '/ghost-bucket/file.txt');

        $this->assertSame(403, $harness->statusCode);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Simulate an HTTP request through the Router using a test harness.
     */
    private function request(string $method, string $path, bool $auth = true): RouterResponse
    {
        // Build superglobals
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $path;
        $_GET                      = [];

        if ($auth) {
            $_SERVER['HTTP_X_BUCKIE_IDENTITY'] = self::IDENTITY;
            $_SERVER['HTTP_X_BUCKIE_SECRET']   = self::SECRET;
        } else {
            unset($_SERVER['HTTP_X_BUCKIE_IDENTITY'], $_SERVER['HTTP_X_BUCKIE_SECRET']);
        }

        $router = new RouterHarness($this->tempDir);

        ob_start();
        $router->dispatch();
        $body = ob_get_clean() ?: '';

        return new RouterResponse(
            statusCode: $router->capturedStatus,
            headers:    $router->capturedHeaders,
            body:       $body,
        );
    }

    /**
     * Write $content directly to $bucketPath/$key (bypassing staging).
     */
    private function uploadFile(string $bucketPath, string $key, string $content, bool $createDirs = false): void
    {
        $target = $bucketPath . '/' . $key;
        if ($createDirs) {
            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        file_put_contents($target, $content);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Test harness — subclass of Router that captures headers instead of sending them
// ──────────────────────────────────────────────────────────────────────────────

/**
 * @internal
 */
class RouterHarness extends Router
{
    public int $capturedStatus = 200;

    /** @var array<string, string> */
    public array $capturedHeaders = [];

    protected function sendJson(array $data, int $status = 200): void
    {
        $this->capturedStatus = $status;
        $this->capturedHeaders['Content-Type'] = 'application/json; charset=utf-8';
        // Suppress body output for HEAD requests
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
            echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }

    protected function sendError(string $code, string $message, int $status): void
    {
        $this->sendJson(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }

    protected function setStatusCode(int $code): void
    {
        $this->capturedStatus = $code;
    }

    protected function emitHeader(string $name, string $value): void
    {
        $this->capturedHeaders[$name] = $value;
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Simple value object for test assertions
// ──────────────────────────────────────────────────────────────────────────────

/**
 * @internal
 */
final class RouterResponse
{
    public function __construct(
        public readonly int $statusCode,
        /** @var array<string, string> */
        public readonly array $headers,
        public readonly string $body,
    ) {}
}



