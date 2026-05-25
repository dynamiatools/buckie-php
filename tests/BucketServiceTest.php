<?php

declare(strict_types=1);

namespace Buckie\Tests;

use Buckie\Config\ConfigService;
use Buckie\Errors\BuckieException;
use Buckie\Storage\BucketService;
use PHPUnit\Framework\TestCase;

class BucketServiceTest extends TestCase
{
    private string $tempDir;
    private BucketService $bucketService;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/buckie-test-' . bin2hex(random_bytes(8));
        ConfigService::ensureDataDirs($this->tempDir);
        $this->bucketService = new BucketService($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public function testCreateBucket(): void
    {
        $bucketPath = $this->tempDir . '/my-bucket';
        $bucket = $this->bucketService->create('test', $bucketPath);

        $this->assertSame('test', $bucket['name']);
        $this->assertSame($bucketPath, $bucket['path']);
        $this->assertNotEmpty($bucket['createdAt']);
    }

    public function testCreateRejectsRelativePaths(): void
    {
        $this->expectException(BuckieException::class);
        $this->expectExceptionMessageMatches('/absolute/i');
        $this->bucketService->create('bad', 'relative/path');
    }

    public function testCreateRejectsDuplicateBuckets(): void
    {
        $bucketPath = $this->tempDir . '/dup-bucket';
        $this->bucketService->create('dup', $bucketPath);

        $this->expectException(BuckieException::class);
        $this->expectExceptionMessageMatches('/already exists/i');
        $this->bucketService->create('dup', $bucketPath);
    }

    public function testListBuckets(): void
    {
        $this->bucketService->create('b1', $this->tempDir . '/b1');
        $this->bucketService->create('b2', $this->tempDir . '/b2');

        $list = $this->bucketService->list();
        $this->assertCount(2, $list);
    }

    public function testFindExistingBucket(): void
    {
        $this->bucketService->create('findme', $this->tempDir . '/findme');
        $found = $this->bucketService->find('findme');

        $this->assertNotNull($found);
        $this->assertSame('findme', $found['name']);
    }

    public function testFindReturnsNullForMissingBucket(): void
    {
        $result = $this->bucketService->find('nonexistent');
        $this->assertNull($result);
    }

    public function testGetThrowsForMissingBucket(): void
    {
        $this->expectException(BuckieException::class);
        $this->bucketService->get('nonexistent');
    }

    public function testDeleteBucket(): void
    {
        $this->bucketService->create('del', $this->tempDir . '/del');
        $this->bucketService->delete('del');

        $this->assertNull($this->bucketService->find('del'));
    }

    public function testDeleteThrowsForMissingBucket(): void
    {
        $this->expectException(BuckieException::class);
        $this->bucketService->delete('ghost');
    }

    // ── Path resolution ───────────────────────────────────────────────────────

    public function testResolveKeyNormal(): void
    {
        $bucket = $this->bucketService->create('resolve', $this->tempDir . '/resolve');
        $resolved = $this->bucketService->resolveKey($bucket, 'subdir/file.txt');

        $this->assertStringEndsWith('subdir/file.txt', $resolved);
        $this->assertStringStartsWith($bucket['path'], $resolved);
    }

    public function testResolveKeyBlocksPathTraversal(): void
    {
        $bucket = $this->bucketService->create('safe', $this->tempDir . '/safe');

        $this->expectException(BuckieException::class);
        $this->bucketService->resolveKey($bucket, '../../../etc/passwd');
    }

    public function testResolveKeyBlocksInternalDir(): void
    {
        $bucket = $this->bucketService->create('internal', $this->tempDir . '/internal');

        $this->expectException(BuckieException::class);
        $this->bucketService->resolveKey($bucket, '.buckie/staging/file.txt');
    }

    public function testResolveKeyEmptyReturnsRoot(): void
    {
        $bucket = $this->bucketService->create('root', $this->tempDir . '/root');
        $resolved = $this->bucketService->resolveKey($bucket, '');

        $this->assertSame(realpath($bucket['path']), $resolved);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

