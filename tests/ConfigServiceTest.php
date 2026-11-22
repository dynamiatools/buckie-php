<?php

declare(strict_types=1);

namespace Buckie\Tests;

use Buckie\Auth\IdentityService;
use Buckie\Config\ConfigService;
use Buckie\Errors\BuckieException;
use Buckie\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase
{
    use TempDir;

    protected function setUp(): void
    {
        $this->makeTempDir('buckie-config');
    }

    protected function tearDown(): void
    {
        putenv('BUCKIE_DATA_DIR');
        putenv('BUCKIE_MAX_UPLOAD_BYTES');
        $this->removeTempDir();
    }

    public function testDefaultDataDirIsNotDerivedFromCwd(): void
    {
        putenv('BUCKIE_DATA_DIR');
        $previous = getcwd();
        chdir($this->tempDir);
        try {
            $dir = ConfigService::getDataDir();
        } finally {
            chdir($previous);
        }

        $this->assertStringNotContainsString($this->tempDir, $dir);
        $this->assertSame(dirname(__DIR__) . '/.buckie', $dir);
    }

    public function testDataDirEnvOverrideIsHonored(): void
    {
        putenv('BUCKIE_DATA_DIR=/somewhere/else');
        $this->assertSame('/somewhere/else', ConfigService::getDataDir());
    }

    public function testMissingConfigReadsAsEmpty(): void
    {
        $this->assertSame(['buckets' => [], 'identities' => []], ConfigService::readConfig($this->tempDir));
    }

    public function testCorruptConfigThrowsInsteadOfReturningEmpty(): void
    {
        file_put_contents($this->tempDir . '/config.json', '{ not json');

        $this->expectException(\RuntimeException::class);
        ConfigService::readConfig($this->tempDir);
    }

    public function testCorruptConfigIsNeverOverwrittenByAWrite(): void
    {
        file_put_contents($this->tempDir . '/config.json', '{ not json');
        $identities = new IdentityService($this->tempDir);

        try {
            $identities->create('alice', 'secret');
            $this->fail('Expected an exception for the corrupt config');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('{ not json', file_get_contents($this->tempDir . '/config.json'));
    }

    public function testConfigFileIsPrivateWhenNew(): void
    {
        ConfigService::writeConfig($this->tempDir, ['buckets' => [], 'identities' => []]);

        $perms = fileperms($this->tempDir . '/config.json') & 0777;
        $this->assertSame(0600, $perms);
    }

    public function testWriteLeavesNoTempFiles(): void
    {
        ConfigService::writeConfig($this->tempDir, ['buckets' => [], 'identities' => []]);

        $leftovers = glob($this->tempDir . '/config.json.tmp.*');
        $this->assertSame([], $leftovers);
    }

    public function testDataDirsAreCreatedPrivate(): void
    {
        $dir = $this->tempDir . '/data';
        ConfigService::ensureDataDirs($dir);

        $this->assertSame(0700, fileperms($dir) & 0777);
        $this->assertDirectoryExists($dir . '/logs');
        $this->assertDirectoryExists($dir . '/cache');
    }

    public function testUpdateAppliesMutationAndKeepsOtherData(): void
    {
        $identities = new IdentityService($this->tempDir);
        $identities->create('alice', 'secret');

        ConfigService::update($this->tempDir, function (array $cfg): array {
            $cfg['buckets'][] = ['name' => 'b', 'path' => '/x'];
            return $cfg;
        });

        $cfg = ConfigService::readConfig($this->tempDir);
        $this->assertCount(1, $cfg['identities']);
        $this->assertCount(1, $cfg['buckets']);
    }

    public function testUpdateThatThrowsLeavesConfigUntouched(): void
    {
        $identities = new IdentityService($this->tempDir);
        $identities->create('alice', 'secret');
        $before = file_get_contents($this->tempDir . '/config.json');

        try {
            ConfigService::update($this->tempDir, function (array $cfg): array {
                throw new \LogicException('boom');
            });
        } catch (\LogicException) {
        }

        $this->assertSame($before, file_get_contents($this->tempDir . '/config.json'));
    }

    public function testConcurrentCreatesDoNotLoseIdentities(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to fork workers');
        }

        $workers = 6;
        $pids    = [];
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    (new IdentityService($this->tempDir))->create("user-{$i}", 'secret');
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $this->assertCount($workers, ConfigService::readConfig($this->tempDir)['identities']);
    }

    public function testDuplicateIdentityIsRejectedInsideTheLock(): void
    {
        $identities = new IdentityService($this->tempDir);
        $identities->create('alice', 'secret');

        $this->expectException(BuckieException::class);
        $identities->create('alice', 'other');
    }

    public function testMaxUploadBytesParsing(): void
    {
        putenv('BUCKIE_MAX_UPLOAD_BYTES=1024');
        $this->assertSame(1024, ConfigService::getMaxUploadBytes());

        putenv('BUCKIE_MAX_UPLOAD_BYTES=abc');
        $this->assertSame(0, ConfigService::getMaxUploadBytes());

        putenv('BUCKIE_MAX_UPLOAD_BYTES');
        $this->assertSame(0, ConfigService::getMaxUploadBytes(), 'unlimited by default, like 1.0.0');
    }
}
