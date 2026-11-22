<?php

declare(strict_types=1);

namespace Buckie\Tests;

use Buckie\Config\ConfigService;
use Buckie\Errors\BuckieException;
use Buckie\Storage\BucketService;
use Buckie\Storage\LocalStorageService;
use Buckie\Tests\Support\TempDir;
use Buckie\Thumbnail\ThumbnailService;
use PHPUnit\Framework\TestCase;

class ThumbnailServiceTest extends TestCase
{
    use TempDir;

    private ThumbnailService $thumbs;
    private array $bucket;
    private string $bucketPath;
    private string $cacheDir;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required');
        }

        $this->makeTempDir('buckie-thumbs');
        $dataDir = $this->tempDir . '/data';
        ConfigService::ensureDataDirs($dataDir);

        $buckets          = new BucketService($dataDir);
        $this->bucketPath = $this->tempDir . '/bucket';
        $this->bucket     = $buckets->create('b', $this->bucketPath);
        $this->cacheDir   = ConfigService::getCacheDir($dataDir);
        $this->thumbs     = new ThumbnailService($buckets, new LocalStorageService($buckets), $this->cacheDir);

        $img = imagecreatetruecolor(400, 200);
        imagepng($img, $this->bucketPath . '/photo.png');
        imagedestroy($img);
        file_put_contents($this->bucketPath . '/notes.txt', 'not an image');
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /** @return list<string> all files below $dir, relative */
    private function filesIn(string $dir): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $out[] = substr($f->getPathname(), strlen($dir) + 1);
        }
        sort($out);
        return $out;
    }

    public static function badFormats(): array
    {
        return [
            'php'            => ['php'],
            'traversal'      => ['x/../../../../public/a.php'],
            'config'         => ['json/../../config.json'],
            'gif'            => ['gif'],
            'empty'          => [''],
            'null byte'      => ["webp\0.php"],
            'double ext'     => ['webp.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badFormats')]
    public function testUnsupportedFormatIsRejectedAndWritesNothing(string $format): void
    {
        $before = $this->filesIn($this->tempDir);

        try {
            $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', 100, 100, 'cover', $format);
            $this->fail('Expected a bad request');
        } catch (BuckieException $e) {
            $this->assertSame(400, $e->getHttpStatus());
        }

        $this->assertSame($before, $this->filesIn($this->tempDir));
    }

    public static function badDimensions(): array
    {
        return [[-1, 10], [10, -1], [ThumbnailService::MAX_DIMENSION + 1, 10], [10, 50000]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badDimensions')]
    public function testOutOfRangeDimensionsAreRejected(int $w, int $h): void
    {
        $this->expectException(BuckieException::class);
        $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', $w, $h);
    }

    public function testUnknownFitIsRejected(): void
    {
        $this->expectException(BuckieException::class);
        $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', 10, 10, '../../x');
    }

    public function testMaxDimensionIsAccepted(): void
    {
        $this->assertSame(
            ThumbnailService::MAX_DIMENSION,
            ThumbnailService::validateDimension(ThumbnailService::MAX_DIMENSION, 'w')
        );
    }

    public function testThumbnailIsCachedOutsideTheBucket(): void
    {
        $bucketBefore = $this->filesIn($this->bucketPath);

        $path = $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', 100, 50, 'cover', 'png');

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertStringStartsWith($this->cacheDir . '/thumbnails/', $path);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}\.png$/', basename($path));
        $this->assertSame($bucketBefore, $this->filesIn($this->bucketPath), 'bucket must not be modified');

        $size = getimagesize($path);
        $this->assertSame([100, 50], [$size[0], $size[1]]);
    }

    public function testJpgIsAnAliasOfJpeg(): void
    {
        $path = $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', 40, 40, 'cover', 'JPG');

        $this->assertNotNull($path);
        $this->assertStringEndsWith('.jpeg', $path);
        $this->assertSame('image/jpeg', $this->thumbs->getMimeType('jpeg'));
    }

    public function testRealUserFileNamedLikeAThumbnailIsNeverOverwritten(): void
    {
        mkdir($this->bucketPath . '/100x100');
        file_put_contents($this->bucketPath . '/100x100/photo.webp', 'precious user data');
        touch($this->bucketPath . '/100x100/photo.webp', time() - 3600);

        $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', 100, 100, 'cover', 'webp');

        $this->assertSame('precious user data', file_get_contents($this->bucketPath . '/100x100/photo.webp'));
    }

    public function testCacheHitReturnsSamePathWithoutRegenerating(): void
    {
        $first = $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', 80, 80, 'cover', 'png');
        $mtime = filemtime($first);
        sleep(1);
        $second = $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', 80, 80, 'cover', 'png');

        $this->assertSame($first, $second);
        $this->assertSame($mtime, filemtime($second));
    }

    public function testStaleCacheIsRegenerated(): void
    {
        $path = $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', 80, 80, 'cover', 'png');
        touch($path, time() - 7200);
        clearstatcache();

        $again = $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', 80, 80, 'cover', 'png');

        $this->assertSame($path, $again);
        $this->assertGreaterThan(time() - 60, filemtime($again));
    }

    public function testDifferentParametersUseDifferentCacheFiles(): void
    {
        $a = $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', 80, 80, 'cover', 'png');
        $b = $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', 80, 80, 'contain', 'png');
        $c = $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', 90, 80, 'cover', 'png');

        $this->assertCount(3, array_unique([$a, $b, $c]));
    }

    public function testNonImageReturnsNull(): void
    {
        $this->assertNull($this->thumbs->getThumbnailPath($this->bucket, 'notes.txt', 10, 10));
    }

    public function testMissingFileReturnsNull(): void
    {
        $this->assertNull($this->thumbs->getThumbnailPath($this->bucket, 'missing.png', 10, 10));
    }

    public function testNoTempFilesAreLeftInCache(): void
    {
        $this->thumbs->getThumbnailPath($this->bucket, 'photo.png', 60, 60, 'cover', 'png');

        $tmp = array_filter($this->filesIn($this->cacheDir), fn($f) => str_contains($f, '.tmp.'));
        $this->assertSame([], array_values($tmp));
    }

    public function testMimeTypeForUnknownFormatThrows(): void
    {
        $this->expectException(BuckieException::class);
        $this->thumbs->getMimeType('php');
    }
}
