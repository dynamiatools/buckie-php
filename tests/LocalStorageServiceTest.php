<?php

declare(strict_types=1);

namespace Buckie\Tests;

use Buckie\Config\ConfigService;
use Buckie\Errors\BuckieException;
use Buckie\Storage\BucketService;
use Buckie\Storage\LocalStorageService;
use PHPUnit\Framework\TestCase;

class LocalStorageServiceTest extends TestCase
{
    private string $tempDir;
    private BucketService $bucketService;
    private LocalStorageService $storageService;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/buckie-test-' . bin2hex(random_bytes(8));
        ConfigService::ensureDataDirs($this->tempDir);
        $this->bucketService  = new BucketService($this->tempDir);
        $this->storageService = new LocalStorageService($this->bucketService);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // ── Upload / Download ─────────────────────────────────────────────────────

    public function testUploadAndGetFileInfo(): void
    {
        $bucket  = $this->createBucket('store');
        $content = 'Hello, Buckie!';

        $result = $this->upload($bucket, 'test/hello.txt', $content);

        $this->assertSame(strlen($content), $result['size']);
        $this->assertNotEmpty($result['etag']);

        $info = $this->storageService->getFileInfo($bucket, 'test/hello.txt');
        $this->assertSame(strlen($content), $info['size']);
        $this->assertSame('text/plain', $info['mimeType']);
        $this->assertNotEmpty($info['etag']);
        $this->assertNotEmpty($info['lastModified']);
        $this->assertNotEmpty($info['path']);
    }

    public function testGetFileInfoThrowsForMissingFile(): void
    {
        $bucket = $this->createBucket('empty');

        $this->expectException(BuckieException::class);
        $this->storageService->getFileInfo($bucket, 'nonexistent.txt');
    }

    public function testGetFileInfoThrowsForDirectory(): void
    {
        $bucket = $this->createBucket('dirs');
        $this->upload($bucket, 'subdir/file.txt', 'content');

        $this->expectException(BuckieException::class);
        $this->storageService->getFileInfo($bucket, 'subdir/');
    }

    public function testUploadOverwritesExistingFile(): void
    {
        $bucket = $this->createBucket('overwrite');
        $this->upload($bucket, 'file.txt', 'original');
        $this->upload($bucket, 'file.txt', 'updated content');

        $info = $this->storageService->getFileInfo($bucket, 'file.txt');
        $this->assertSame(strlen('updated content'), $info['size']);
    }

    public function testUploadCreatesNestedDirectories(): void
    {
        $bucket = $this->createBucket('nested');
        $this->upload($bucket, 'a/b/c/file.txt', 'deep');

        $info = $this->storageService->getFileInfo($bucket, 'a/b/c/file.txt');
        $this->assertSame(4, $info['size']);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function testDeleteFile(): void
    {
        $bucket = $this->createBucket('del');
        $this->upload($bucket, 'to-delete.txt', 'bye');
        $this->storageService->delete($bucket, 'to-delete.txt');

        $this->expectException(BuckieException::class);
        $this->storageService->getFileInfo($bucket, 'to-delete.txt');
    }

    public function testDeleteThrowsForMissingFile(): void
    {
        $bucket = $this->createBucket('del2');

        $this->expectException(BuckieException::class);
        $this->storageService->delete($bucket, 'ghost.txt');
    }

    public function testDeleteThrowsForDirectory(): void
    {
        $bucket = $this->createBucket('del3');
        $this->upload($bucket, 'dir/file.txt', 'content');

        $this->expectException(BuckieException::class);
        $this->storageService->delete($bucket, 'dir');
    }

    // ── List directory ────────────────────────────────────────────────────────

    public function testListDirectory(): void
    {
        $bucket = $this->createBucket('listdir');
        $this->upload($bucket, 'dir/a.txt', 'a');
        $this->upload($bucket, 'dir/b.txt', 'b');

        $result = $this->storageService->listDirectory($bucket, 'dir/', 'all');

        $this->assertSame('listdir', $result['bucket']);
        $this->assertCount(2, $result['entries']);

        $keys = array_column($result['entries'], 'key');
        $this->assertContains('dir/a.txt', $keys);
        $this->assertContains('dir/b.txt', $keys);
    }

    public function testListDirectoryFilterFiles(): void
    {
        $bucket = $this->createBucket('listfiles');
        $this->upload($bucket, 'dir/file.txt', 'f');
        $this->upload($bucket, 'dir/sub/other.txt', 'o');

        $result = $this->storageService->listDirectory($bucket, 'dir/', 'files');
        $keys   = array_column($result['entries'], 'key');

        $this->assertContains('dir/file.txt', $keys);
        $this->assertNotContains('dir/sub/', $keys);
    }

    public function testListDirectoryFilterDirs(): void
    {
        $bucket = $this->createBucket('listdirs');
        $this->upload($bucket, 'dir/file.txt', 'f');
        $this->upload($bucket, 'dir/sub/other.txt', 'o');

        $result = $this->storageService->listDirectory($bucket, 'dir/', 'dirs');
        $keys   = array_column($result['entries'], 'key');

        $this->assertContains('dir/sub/', $keys);
        $this->assertNotContains('dir/file.txt', $keys);
    }

    public function testListDirectoryThrowsForMissingDir(): void
    {
        $bucket = $this->createBucket('nodir');

        $this->expectException(BuckieException::class);
        $this->storageService->listDirectory($bucket, 'missing/', 'all');
    }

    // ── List bucket (paginated) ───────────────────────────────────────────────

    public function testListBucketPagination(): void
    {
        $bucket = $this->createBucket('paginate');
        for ($i = 0; $i < 5; $i++) {
            $this->upload($bucket, "file-{$i}.txt", "content-{$i}");
        }

        $page1 = $this->storageService->listBucket($bucket, 3);
        $this->assertCount(3, $page1['entries']);
        $this->assertTrue($page1['hasMore']);
        $this->assertNotNull($page1['cursor']);

        $page2 = $this->storageService->listBucket($bucket, 3, $page1['cursor']);
        $this->assertCount(2, $page2['entries']);
        $this->assertFalse($page2['hasMore']);
    }

    public function testListBucketAll(): void
    {
        $bucket = $this->createBucket('listall');
        $this->upload($bucket, 'x.txt', 'x');
        $this->upload($bucket, 'y.txt', 'y');

        $result = $this->storageService->listBucket($bucket, 100);
        $this->assertSame(2, $result['total']);
        $this->assertFalse($result['hasMore']);
    }

    // ── HEAD / getFileInfo metadata ───────────────────────────────────────────

    public function testGetFileInfoReturnsCorrectMetadata(): void
    {
        $bucket  = $this->createBucket('meta');
        $content = 'metadata test content';
        $this->upload($bucket, 'meta/file.txt', $content);

        $info = $this->storageService->getFileInfo($bucket, 'meta/file.txt');

        $this->assertSame(strlen($content), $info['size']);
        $this->assertSame('text/plain', $info['mimeType']);
        $this->assertMatchesRegularExpression('/^"[0-9]+-[0-9]+"$/', $info['etag']);
        $this->assertMatchesRegularExpression('/GMT$/', $info['lastModified']);
        $this->assertFileExists($info['path']);
    }

    public function testGetFileInfoEtagChangesAfterOverwrite(): void
    {
        $bucket = $this->createBucket('etag');
        $this->upload($bucket, 'data.bin', 'v1');
        $info1 = $this->storageService->getFileInfo($bucket, 'data.bin');

        sleep(1); // ensure mtime changes
        $this->upload($bucket, 'data.bin', 'v2-longer-content');
        $info2 = $this->storageService->getFileInfo($bucket, 'data.bin');

        $this->assertNotSame($info1['etag'], $info2['etag']);
    }

    // ── MIME detection ────────────────────────────────────────────────────────

    public function testDetectMimeTypeByExtension(): void
    {
        $bucket = $this->createBucket('mime');
        // Use real PNG magic bytes so finfo detects it as image/png
        $pngMagic = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 50);
        $this->upload($bucket, 'image.png', $pngMagic);
        $info = $this->storageService->getFileInfo($bucket, 'image.png');
        $this->assertStringContainsString('image/', $info['mimeType']);
    }

    public function testDetectMimeTypeUnknownExtension(): void
    {
        $bucket = $this->createBucket('mime2');
        $this->upload($bucket, 'file.unknownext', 'data');
        $info = $this->storageService->getFileInfo($bucket, 'file.unknownext');
        $this->assertNotEmpty($info['mimeType']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createBucket(string $name): array
    {
        return $this->bucketService->create($name, $this->tempDir . '/' . $name);
    }

    /**
     * Write $content to a temp stream and upload it.
     */
    private function upload(array $bucket, string $key, string $content): array
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $content);
        rewind($stream);

        $result = $this->storageService->upload($bucket, $key, $stream);
        fclose($stream);

        return $result;
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


