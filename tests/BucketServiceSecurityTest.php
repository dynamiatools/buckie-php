<?php

declare(strict_types=1);

namespace Buckie\Tests;

use Buckie\Config\ConfigService;
use Buckie\Errors\BuckieException;
use Buckie\Storage\BucketService;
use Buckie\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

class BucketServiceSecurityTest extends TestCase
{
    use TempDir;

    private BucketService $service;
    private array $bucket;
    private string $outside;

    protected function setUp(): void
    {
        $this->makeTempDir('buckie-bucket-sec');
        ConfigService::ensureDataDirs($this->tempDir . '/data');
        $this->service = new BucketService($this->tempDir . '/data');
        $this->bucket  = $this->service->create('b', $this->tempDir . '/bucket');

        $this->outside = $this->tempDir . '/outside';
        mkdir($this->outside);
        file_put_contents($this->outside . '/secret.txt', 'top secret');
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    public function testSymlinkedFileInsideBucketCannotEscape(): void
    {
        symlink($this->outside . '/secret.txt', $this->tempDir . '/bucket/link.txt');

        $this->expectException(BuckieException::class);
        $this->expectExceptionCode(400);
        $this->service->resolveKey($this->bucket, 'link.txt');
    }

    public function testSymlinkedDirectoryInsideBucketCannotEscape(): void
    {
        symlink($this->outside, $this->tempDir . '/bucket/dir');

        $this->expectException(BuckieException::class);
        $this->service->resolveKey($this->bucket, 'dir/secret.txt');
    }

    public function testWriteThroughSymlinkedDirectoryIsBlockedEvenForNewFiles(): void
    {
        symlink($this->outside, $this->tempDir . '/bucket/dir');

        $this->expectException(BuckieException::class);
        $this->service->resolveKey($this->bucket, 'dir/new/file.txt');
    }

    public function testDanglingSymlinkIsRejected(): void
    {
        symlink($this->tempDir . '/does-not-exist', $this->tempDir . '/bucket/dangling');

        $this->expectException(BuckieException::class);
        $this->service->resolveKey($this->bucket, 'dangling');
    }

    public function testSymlinkPointingInsideTheBucketIsAllowed(): void
    {
        mkdir($this->tempDir . '/bucket/real');
        file_put_contents($this->tempDir . '/bucket/real/a.txt', 'a');
        symlink($this->tempDir . '/bucket/real', $this->tempDir . '/bucket/alias');

        $resolved = $this->service->resolveKey($this->bucket, 'alias/a.txt');

        $this->assertStringEndsWith('/bucket/alias/a.txt', $resolved);
    }

    public function testNonExistingNestedKeyInsideBucketResolves(): void
    {
        $resolved = $this->service->resolveKey($this->bucket, 'new/nested/file.txt');

        $this->assertStringEndsWith('/bucket/new/nested/file.txt', $resolved);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedVariants')]
    public function testInternalDirectoryIsBlockedCaseInsensitively(string $key): void
    {
        $this->expectException(BuckieException::class);
        $this->expectExceptionCode(403);
        $this->service->resolveKey($this->bucket, $key);
    }

    public static function reservedVariants(): array
    {
        return [
            ['.buckie/staging/x'],
            ['.BUCKIE/staging/x'],
            ['a/.Buckie/x'],
        ];
    }

    public function testNulByteInKeyIsRejected(): void
    {
        $this->expectException(BuckieException::class);
        $this->service->resolveKey($this->bucket, "a\0b");
    }

    public function testTraversalIsRejected(): void
    {
        $this->expectException(BuckieException::class);
        $this->service->resolveKey($this->bucket, 'a/../../outside/secret.txt');
    }

    public function testCreateDuplicateBucketIsRejectedAtomically(): void
    {
        $this->expectException(BuckieException::class);
        $this->expectExceptionCode(409);
        $this->service->create('b', $this->tempDir . '/other');
    }
}
