<?php

declare(strict_types=1);

namespace Buckie\Tests;

use Buckie\Config\ConfigService;
use Buckie\Errors\BuckieException;
use Buckie\Storage\BucketService;
use Buckie\Storage\LocalStorageService;
use Buckie\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

class UploadHardeningTest extends TestCase
{
    use TempDir;

    private LocalStorageService $storage;
    private array $bucket;

    protected function setUp(): void
    {
        $this->makeTempDir('buckie-upload');
        ConfigService::ensureDataDirs($this->tempDir . '/data');
        $buckets       = new BucketService($this->tempDir . '/data');
        $this->bucket  = $buckets->create('b', $this->tempDir . '/bucket');
        $this->storage = new LocalStorageService($buckets);
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function stream(string $content)
    {
        $fp = fopen('php://memory', 'r+b');
        fwrite($fp, $content);
        rewind($fp);
        return $fp;
    }

    private function stagingFiles(): array
    {
        return glob($this->tempDir . '/bucket/.buckie/staging/*') ?: [];
    }

    public function testUploadWithinLimitSucceeds(): void
    {
        $result = $this->storage->upload($this->bucket, 'a.txt', $this->stream('hello'), true, 10);

        $this->assertSame(5, $result['size']);
        $this->assertSame('hello', file_get_contents($this->tempDir . '/bucket/a.txt'));
    }

    public function testUploadOverLimitIsRejectedAndLeavesNothingBehind(): void
    {
        try {
            $this->storage->upload($this->bucket, 'a.txt', $this->stream(str_repeat('x', 200_000)), true, 100_000);
            $this->fail('Expected payload too large');
        } catch (BuckieException $e) {
            $this->assertSame(413, $e->getHttpStatus());
        }

        $this->assertFileDoesNotExist($this->tempDir . '/bucket/a.txt');
        $this->assertSame([], $this->stagingFiles());
    }

    public function testDeclaredContentLengthOverLimitIsRejectedUpFront(): void
    {
        try {
            $this->storage->upload($this->bucket, 'a.txt', $this->stream('x'), true, 10, 1_000_000);
            $this->fail('Expected payload too large');
        } catch (BuckieException $e) {
            $this->assertSame(413, $e->getHttpStatus());
        }
    }

    public function testContentLengthMismatchIsRejected(): void
    {
        try {
            $this->storage->upload($this->bucket, 'a.txt', $this->stream('abc'), true, 0, 10);
            $this->fail('Expected an incomplete upload error');
        } catch (BuckieException $e) {
            $this->assertSame('BUCKIE_UPLOAD_INCOMPLETE', $e->getBuckieCode());
        }

        $this->assertFileDoesNotExist($this->tempDir . '/bucket/a.txt');
        $this->assertSame([], $this->stagingFiles());
    }

    public function testOverwriteFalseRejectsExistingObject(): void
    {
        $this->storage->upload($this->bucket, 'a.txt', $this->stream('one'));

        try {
            $this->storage->upload($this->bucket, 'a.txt', $this->stream('two'), false);
            $this->fail('Expected conflict');
        } catch (BuckieException $e) {
            $this->assertSame(409, $e->getHttpStatus());
        }

        $this->assertSame('one', file_get_contents($this->tempDir . '/bucket/a.txt'));
    }

    public function testUploadOntoDirectoryIsRejected(): void
    {
        mkdir($this->tempDir . '/bucket/dir');

        $this->expectException(BuckieException::class);
        $this->storage->upload($this->bucket, 'dir', $this->stream('x'));
    }

    public function testUploadEtagMatchesFileInfoEtag(): void
    {
        $result = $this->storage->upload($this->bucket, 'a.txt', $this->stream('hello'));
        $info   = $this->storage->getFileInfo($this->bucket, 'a.txt');

        $this->assertSame($info['etag'], $result['etag']);
    }

    public function testUploadThroughSymlinkedDirectoryIsBlocked(): void
    {
        $outside = $this->tempDir . '/outside';
        mkdir($outside);
        symlink($outside, $this->tempDir . '/bucket/escape');

        try {
            $this->storage->upload($this->bucket, 'escape/pwn.txt', $this->stream('x'));
            $this->fail('Expected path traversal error');
        } catch (BuckieException) {
        }

        $this->assertFileDoesNotExist($outside . '/pwn.txt');
    }

    public function testActiveContentDetection(): void
    {
        $this->assertTrue(LocalStorageService::isActiveContent('text/html'));
        $this->assertTrue(LocalStorageService::isActiveContent('image/svg+xml'));
        $this->assertTrue(LocalStorageService::isActiveContent('application/javascript'));
        $this->assertFalse(LocalStorageService::isActiveContent('image/png'));
        $this->assertFalse(LocalStorageService::isActiveContent('application/pdf'));
    }
}
