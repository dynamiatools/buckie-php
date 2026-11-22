<?php

declare(strict_types=1);

namespace Buckie\Tests;

use Buckie\Auth\IdentityService;
use Buckie\Config\ConfigService;
use Buckie\Http\Router;
use Buckie\Storage\BucketService;
use Buckie\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests of the HTTP layer for the hardened behaviors:
 * thumbnail parameters, response headers, auth, permission ordering.
 */
class RouterSecurityTest extends TestCase
{
    use TempDir;

    private const IDENTITY = 'tester';
    private const SECRET   = 'tester-secret';

    private string $bucketPath;
    private IdentityService $identities;

    protected function setUp(): void
    {
        $this->makeTempDir('buckie-router-sec');
        ConfigService::ensureDataDirs($this->tempDir . '/data');

        $this->bucketPath = $this->tempDir . '/bucket';
        (new BucketService($this->tempDir . '/data'))->create('media', $this->bucketPath);

        $this->identities = new IdentityService($this->tempDir . '/data');
        $this->identities->create(self::IDENTITY, self::SECRET);
        $this->identities->grant(self::IDENTITY, [
            'bucket' => 'media', 'prefixes' => [], 'permissions' => ['read', 'write', 'delete'],
        ]);

        if (extension_loaded('gd')) {
            $img = imagecreatetruecolor(300, 200);
            imagepng($img, $this->bucketPath . '/photo.png');
            imagedestroy($img);
        }
        file_put_contents($this->bucketPath . '/page.html', '<script>alert(1)</script>');
        file_put_contents($this->bucketPath . '/vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        file_put_contents($this->bucketPath . '/doc.txt', 'plain');
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET    = [];
        putenv('BUCKIE_TRUST_PROXY');
        $this->removeTempDir();
    }

    private function request(string $method, string $uri, bool $auth = true, array $server = []): SecurityRouterResponse
    {
        $_SERVER = array_merge(['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '10.0.0.9'], $server);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET);

        if ($auth) {
            $_SERVER['HTTP_X_BUCKIE_IDENTITY'] = self::IDENTITY;
            $_SERVER['HTTP_X_BUCKIE_SECRET']   = self::SECRET;
        }

        $router = new SecurityRouterHarness($this->tempDir . '/data');
        ob_start();
        $router->dispatch();
        $body = ob_get_clean() ?: '';

        return new SecurityRouterResponse($router->status, $router->headers, $body);
    }

    private function requireGd(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required');
        }
    }

    // ── Thumbnail parameters ──────────────────────────────────────────────────

    public static function badThumbnailQueries(): array
    {
        return [
            'php format'        => ['w=100&format=php'],
            'traversal format'  => ['w=100&format=x%2F..%2F..%2F..%2F..%2Fpublic%2Fa.php'],
            'gif format'        => ['w=100&format=gif'],
            'array format'      => ['w=10&format[]=png'],
            'null byte format'  => ['w=10&format=webp%00.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badThumbnailQueries')]
    public function testInvalidThumbnailParametersReturn400AndWriteNothing(string $query): void
    {
        $this->requireGd();
        $before = $this->snapshot();

        $res = $this->request('GET', "/media/photo.png?{$query}");

        $this->assertSame(400, $res->status, $res->body);
        $this->assertSame($before, $this->snapshot());
    }

    /** Compatibility with 1.0.0: bad sizes/fits never produced an error. */
    public function testOversizedThumbnailIsClampedInsteadOfRejected(): void
    {
        $this->requireGd();

        $res = $this->request('GET', '/media/photo.png?w=50000&format=png');

        $this->assertSame(200, $res->status);
        $size = getimagesizefromstring($res->body);
        $this->assertSame(4096, $size[0]);
    }

    public static function lenientQueries(): array
    {
        return [
            'negative width'    => ['w=-5'],
            'non numeric width' => ['w=abc'],
            'array width'       => ['w[]=1'],
            'unknown fit'       => ['w=10&fit=zoom'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lenientQueries')]
    public function testLegacyLenientParametersStillSucceed(string $query): void
    {
        $this->requireGd();

        $res = $this->request('GET', "/media/photo.png?{$query}");

        $this->assertSame(200, $res->status);
    }

    public function testValidThumbnailIsServedFromCacheOutsideTheBucket(): void
    {
        $this->requireGd();
        $bucketBefore = $this->listing($this->bucketPath);

        $res = $this->request('GET', '/media/photo.png?w=100&h=50&format=png');

        $this->assertSame(200, $res->status);
        $this->assertSame('image/png', $res->headers['Content-Type']);
        $this->assertSame('nosniff', $res->headers['X-Content-Type-Options']);
        $this->assertSame('private, max-age=86400', $res->headers['Cache-Control']);
        $this->assertNotSame('', $res->body);
        $this->assertSame($bucketBefore, $this->listing($this->bucketPath));
    }

    public function testThumbnailRequiresReadPermission(): void
    {
        $this->requireGd();
        $this->identities->revoke(self::IDENTITY, 'media');

        $res = $this->request('GET', '/media/photo.png?w=10');

        $this->assertSame(403, $res->status);
    }

    // ── Response headers ──────────────────────────────────────────────────────

    public function testHtmlIsForcedToDownloadAndNeverSniffed(): void
    {
        $res = $this->request('GET', '/media/page.html');

        $this->assertSame(200, $res->status);
        $this->assertSame('attachment', $res->headers['Content-Disposition']);
        $this->assertSame('nosniff', $res->headers['X-Content-Type-Options']);
        $this->assertStringContainsString('sandbox', $res->headers['Content-Security-Policy']);
    }

    public function testSvgIsForcedToDownload(): void
    {
        $res = $this->request('GET', '/media/vector.svg');

        $this->assertSame('attachment', $res->headers['Content-Disposition']);
    }

    public function testPlainTextIsServedInlineButNotSniffed(): void
    {
        $res = $this->request('GET', '/media/doc.txt');

        $this->assertArrayNotHasKey('Content-Disposition', $res->headers);
        $this->assertSame('nosniff', $res->headers['X-Content-Type-Options']);
        $this->assertSame('plain', $res->body);
    }

    public function testHeadCarriesTheSameSecurityHeaders(): void
    {
        $res = $this->request('HEAD', '/media/page.html');

        $this->assertSame('attachment', $res->headers['Content-Disposition']);
        $this->assertSame('nosniff', $res->headers['X-Content-Type-Options']);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function testBasicAuthorizationHeaderWorks(): void
    {
        $res = $this->request('GET', '/media/doc.txt', false, [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode(self::IDENTITY . ':' . self::SECRET),
        ]);

        $this->assertSame(200, $res->status);
    }

    public function testBasicAuthorizationWithWrongSecretIs401(): void
    {
        $res = $this->request('GET', '/media/doc.txt', false, [
            'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode(self::IDENTITY . ':nope'),
        ]);

        $this->assertSame(401, $res->status);
    }

    public function testMalformedBasicHeaderIs401(): void
    {
        $res = $this->request('GET', '/media/doc.txt', false, ['HTTP_AUTHORIZATION' => 'Basic !!!notbase64']);

        $this->assertSame(401, $res->status);
    }

    public function testUnknownIdentityIs401(): void
    {
        $res = $this->request('GET', '/media/doc.txt', false, [
            'HTTP_X_BUCKIE_IDENTITY' => 'ghost',
            'HTTP_X_BUCKIE_SECRET'   => 'x',
        ]);

        $this->assertSame(401, $res->status);
    }

    public function testHealthEndpointNeedsNoAuth(): void
    {
        $res = $this->request('GET', '/health', false);

        $this->assertSame(200, $res->status);
    }

    // ── Permission ordering (no bucket-existence oracle) ──────────────────────

    public function testMissingAndUnauthorizedBucketsLookTheSame(): void
    {
        $missing = $this->request('GET', '/does-not-exist/file.txt');
        $denied  = $this->request('GET', '/does-not-exist/');

        $this->assertSame(403, $missing->status);
        $this->assertSame(403, $denied->status);

        (new BucketService($this->tempDir . '/data'))->create('hidden', $this->tempDir . '/hidden');
        $hidden = $this->request('GET', '/hidden/file.txt');
        $this->assertSame(403, $hidden->status);
    }

    public function testPrefixGrantDoesNotLeakIntoSiblingPrefix(): void
    {
        mkdir($this->bucketPath . '/tenant-a');
        mkdir($this->bucketPath . '/tenant-ab');
        file_put_contents($this->bucketPath . '/tenant-a/x.txt', 'a');
        file_put_contents($this->bucketPath . '/tenant-ab/x.txt', 'ab');
        $this->identities->grant(self::IDENTITY, [
            'bucket' => 'media', 'prefixes' => ['/tenant-a'], 'permissions' => ['read'],
        ]);

        $this->assertSame(200, $this->request('GET', '/media/tenant-a/x.txt')->status);
        $this->assertSame(403, $this->request('GET', '/media/tenant-ab/x.txt')->status);
    }

    // ── Path safety ───────────────────────────────────────────────────────────

    public function testSymlinkEscapeIsBlockedOverHttp(): void
    {
        $outside = $this->tempDir . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/secret.txt', 'top secret');
        symlink($outside, $this->bucketPath . '/leak');

        $res = $this->request('GET', '/media/leak/secret.txt');

        $this->assertSame(400, $res->status);
        $this->assertStringNotContainsString('top secret', $res->body);
    }

    public function testErrorMessagesDoNotLeakAbsolutePaths(): void
    {
        $res = $this->request('GET', '/media/leak/../../etc/passwd');

        $this->assertStringNotContainsString($this->tempDir, $res->body);
    }

    public function testInternalDirectoryIsHiddenCaseInsensitively(): void
    {
        $res = $this->request('GET', '/media/.BUCKIE/staging/x');

        $this->assertSame(403, $res->status);
    }

    // ── Client IP logging ─────────────────────────────────────────────────────

    public function testForwardedForIsIgnoredByDefault(): void
    {
        $this->request('GET', '/media/doc.txt', true, ['HTTP_X_FORWARDED_FOR' => '6.6.6.6']);

        $this->assertSame('10.0.0.9', $this->lastLoggedIp());
    }

    public function testForwardedForIsHonoredWhenProxyIsTrusted(): void
    {
        putenv('BUCKIE_TRUST_PROXY=1');
        $this->request('GET', '/media/doc.txt', true, ['HTTP_X_FORWARDED_FOR' => '6.6.6.6, 10.0.0.1']);

        $this->assertSame('6.6.6.6', $this->lastLoggedIp());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function lastLoggedIp(): string
    {
        $lines = file($this->tempDir . '/data/logs/access.log.jsonl', FILE_IGNORE_NEW_LINES);
        return json_decode(end($lines), true)['ip'];
    }

    private function listing(string $dir): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $out[] = substr($f->getPathname(), strlen($dir) + 1);
        }
        sort($out);
        return $out;
    }

    /** Everything on disk except logs, which legitimately change per request. */
    private function snapshot(): array
    {
        return array_values(array_filter(
            $this->listing($this->tempDir),
            fn($f) => !str_contains($f, 'logs/')
        ));
    }
}

/** @internal */
class SecurityRouterHarness extends Router
{
    public int $status = 200;
    /** @var array<string, string> */
    public array $headers = [];

    protected function sendJson(array $data, int $status = 200): void
    {
        $this->status = $status;
        $this->headers['Content-Type'] = 'application/json; charset=utf-8';
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function setStatusCode(int $code): void
    {
        $this->status = $code;
    }

    protected function emitHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }
}

/** @internal */
final class SecurityRouterResponse
{
    public function __construct(
        public readonly int $status,
        /** @var array<string, string> */
        public readonly array $headers,
        public readonly string $body,
    ) {}
}
