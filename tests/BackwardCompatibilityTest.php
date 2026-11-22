<?php

declare(strict_types=1);

namespace Buckie\Tests;

use Buckie\Auth\IdentityService;
use Buckie\Config\ConfigService;
use Buckie\Http\Router;
use Buckie\Storage\BucketService;
use Buckie\Storage\LocalStorageService;
use Buckie\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * An installation created by Buckie 1.0.0 (or by the Node.js implementation)
 * must keep working with the current code, config included.
 */
class BackwardCompatibilityTest extends TestCase
{
    use TempDir;

    private string $dataDir;
    private string $bucketPath;

    protected function setUp(): void
    {
        $this->makeTempDir('buckie-compat');
        $this->dataDir    = $this->tempDir . '/legacy-data';
        $this->bucketPath = $this->tempDir . '/storage/documents';
        mkdir($this->dataDir . '/logs', 0755, true);
        mkdir($this->dataDir . '/cache', 0755, true);
        mkdir($this->bucketPath . '/.buckie/staging', 0755, true);

        // Config exactly as 1.0.0 wrote it, including a Node.js style "$2b$" bcrypt hash
        $nodeHash = '$2b$' . substr(password_hash('node-secret', PASSWORD_BCRYPT), 4);
        $config   = [
            'buckets' => [[
                'name' => 'documents', 'path' => $this->bucketPath,
                'storage' => 'local', 'createdAt' => '2025-01-10T10:00:00+00:00',
            ]],
            'identities' => [
                [
                    'identity' => 'erp-prod', 'hashedSecret' => password_hash('php-secret', PASSWORD_DEFAULT),
                    'grants' => [[
                        'bucket' => 'documents', 'prefixes' => ['/tenant-a/'], 'permissions' => ['read', 'write', 'delete'],
                    ]],
                    'createdAt' => '2025-01-10T10:00:00+00:00', 'updatedAt' => '2025-01-10T10:00:00+00:00',
                ],
                [
                    // Grant without "prefixes", as the Node.js implementation may write it
                    'identity' => 'from-node', 'hashedSecret' => $nodeHash,
                    'grants' => [['bucket' => 'documents', 'permissions' => ['read']]],
                    'createdAt' => '2025-01-10T10:00:00+00:00', 'updatedAt' => '2025-01-10T10:00:00+00:00',
                ],
            ],
        ];
        file_put_contents($this->dataDir . '/config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($this->dataDir . '/config.json', 0644);

        mkdir($this->bucketPath . '/tenant-a/300x300', 0755, true); // thumbnails cached the old way
        file_put_contents($this->bucketPath . '/tenant-a/300x300/photo.webp', 'old cached thumbnail');
        file_put_contents($this->bucketPath . '/tenant-a/invoice.txt', 'invoice');
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET    = [];
        putenv('BUCKIE_DATA_DIR');
        putenv('BUCKIE_FOLLOW_SYMLINKS');
        $this->removeTempDir();
    }

    private function get(string $uri, string $identity, string $secret): array
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_BUCKIE_IDENTITY' => $identity, 'HTTP_X_BUCKIE_SECRET' => $secret,
        ];
        $_GET = [];

        $router = new CompatRouterHarness($this->dataDir);
        ob_start();
        $router->dispatch();
        $body = ob_get_clean() ?: '';

        return [$router->status, $body];
    }

    public function testLegacyConfigIsReadUnchanged(): void
    {
        $cfg = ConfigService::readConfig($this->dataDir);

        $this->assertCount(1, $cfg['buckets']);
        $this->assertCount(2, $cfg['identities']);
        $this->assertSame('documents', (new BucketService($this->dataDir))->get('documents')['name']);
    }

    public function testExistingIdentitiesStillAuthenticate(): void
    {
        $ids = new IdentityService($this->dataDir);

        $this->assertNotNull($ids->verify('erp-prod', 'php-secret'));
        $this->assertNotNull($ids->verify('from-node', 'node-secret'), 'Node.js $2b$ hashes must keep working');
        $this->assertNull($ids->verify('erp-prod', 'wrong'));
    }

    public function testExistingRequestsKeepWorkingOverHttp(): void
    {
        [$status, $body] = $this->get('/documents/tenant-a/invoice.txt', 'erp-prod', 'php-secret');

        $this->assertSame(200, $status);
        $this->assertSame('invoice', $body);

        [$status] = $this->get('/documents/tenant-a/invoice.txt', 'from-node', 'node-secret');
        $this->assertSame(200, $status, 'grant without "prefixes" means the whole bucket');
    }

    public function testTrailingSlashPrefixesBehaveAsBefore(): void
    {
        $ids      = new IdentityService($this->dataDir);
        $identity = $ids->get('erp-prod');

        $this->assertNotNull($ids->checkPermission($identity, 'documents', '/tenant-a/x.pdf', 'read'));
        $this->assertNull($ids->checkPermission($identity, 'documents', '/tenant-b/x.pdf', 'read'));
    }

    public function testRewritingConfigKeepsExistingModeAndData(): void
    {
        $ids = new IdentityService($this->dataDir);
        $ids->create('new-user', 'secret');

        $this->assertSame(0644, fileperms($this->dataDir . '/config.json') & 0777, 'web server must still be able to read it');
        $cfg = ConfigService::readConfig($this->dataDir);
        $this->assertCount(3, $cfg['identities']);
        $this->assertSame('/tenant-a/', $cfg['identities'][0]['grants'][0]['prefixes'][0]);
        $this->assertSame('2025-01-10T10:00:00+00:00', $cfg['buckets'][0]['createdAt']);
    }

    public function testEmptyConfigFileIsStillAnEmptyConfig(): void
    {
        file_put_contents($this->dataDir . '/config.json', '');

        $this->assertSame(['buckets' => [], 'identities' => []], ConfigService::readConfig($this->dataDir));
    }

    public function testOldThumbnailDirectoriesInBucketsDoNotBreakListing(): void
    {
        $buckets = new BucketService($this->dataDir);
        $storage = new LocalStorageService($buckets);

        $list = $storage->listDirectory($buckets->get('documents'), 'tenant-a/');

        $keys = array_column($list['entries'], 'key');
        $this->assertContains('tenant-a/invoice.txt', $keys);
        $this->assertContains('tenant-a/300x300/', $keys);
    }

    public function testLegacyCwdDataDirIsStillFoundWhenItAlreadyHoldsAConfig(): void
    {
        putenv('BUCKIE_DATA_DIR');
        $cwd = $this->tempDir . '/old-cwd';
        mkdir($cwd . '/.buckie', 0755, true);
        file_put_contents($cwd . '/.buckie/config.json', '{"buckets":[],"identities":[]}');

        $previous = getcwd();
        chdir($cwd);
        try {
            $dir = ConfigService::getDataDir();
        } finally {
            chdir($previous);
        }

        $this->assertSame($cwd . '/.buckie', $dir);
    }

    public function testNewInstallationsNeverUseTheCwd(): void
    {
        putenv('BUCKIE_DATA_DIR');
        $cwd = $this->tempDir . '/fresh-cwd';
        mkdir($cwd, 0755, true);

        $previous = getcwd();
        chdir($cwd);
        try {
            $dir = ConfigService::getDataDir();
        } finally {
            chdir($previous);
        }

        $this->assertSame(dirname(__DIR__) . '/.buckie', $dir);
    }

    public function testExternalSymlinksWorkWhenExplicitlyEnabled(): void
    {
        $other = $this->tempDir . '/other-disk';
        mkdir($other);
        file_put_contents($other . '/big.bin', 'data');
        symlink($other, $this->bucketPath . '/archive');
        $buckets = new BucketService($this->dataDir);
        $bucket  = $buckets->get('documents');

        try {
            $buckets->resolveKey($bucket, 'archive/big.bin');
            $this->fail('Blocked by default');
        } catch (\Buckie\Errors\BuckieException) {
        }

        putenv('BUCKIE_FOLLOW_SYMLINKS=1');
        $this->assertStringEndsWith('/archive/big.bin', $buckets->resolveKey($bucket, 'archive/big.bin'));

        // Traversal and the reserved directory stay blocked even with the opt-in
        $this->expectException(\Buckie\Errors\BuckieException::class);
        $buckets->resolveKey($bucket, 'archive/../../etc/passwd');
    }

    public function testBucketRootThatIsItselfASymlinkKeepsWorking(): void
    {
        $real = $this->tempDir . '/real-root';
        mkdir($real . '/.buckie/staging', 0755, true);
        file_put_contents($real . '/f.txt', 'x');
        symlink($real, $this->tempDir . '/linked-root');

        $buckets = new BucketService($this->dataDir);
        $bucket  = ['name' => 'l', 'path' => $this->tempDir . '/linked-root'];

        $this->assertStringEndsWith('/real-root/f.txt', $buckets->resolveKey($bucket, 'f.txt'));
    }

    public function testNoUploadLimitByDefault(): void
    {
        putenv('BUCKIE_MAX_UPLOAD_BYTES');
        $this->assertSame(0, ConfigService::getMaxUploadBytes());
    }
}

/** @internal */
class CompatRouterHarness extends Router
{
    public int $status = 200;

    protected function sendJson(array $data, int $status = 200): void
    {
        $this->status = $status;
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function setStatusCode(int $code): void
    {
        $this->status = $code;
    }

    protected function emitHeader(string $name, string $value): void {}
}
