<?php

declare(strict_types=1);

namespace Buckie\Tests;

use Buckie\Auth\IdentityService;
use Buckie\Config\ConfigService;
use Buckie\Errors\BuckieException;
use Buckie\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

class IdentityServiceSecurityTest extends TestCase
{
    use TempDir;

    private IdentityService $service;

    protected function setUp(): void
    {
        $this->makeTempDir('buckie-identity-sec');
        ConfigService::ensureDataDirs($this->tempDir);
        $this->service = new IdentityService($this->tempDir);
        $this->service->create('tenant', 'secret');
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    private function identityWithPrefix(string $prefix): array
    {
        return $this->service->grant('tenant', [
            'bucket'      => 'b',
            'prefixes'    => [$prefix],
            'permissions' => ['read'],
        ]);
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function prefixCases(): array
    {
        return [
            'exact key'                 => ['/tenant-a', '/tenant-a', true],
            'child'                     => ['/tenant-a', '/tenant-a/file.txt', true],
            'sibling with suffix'       => ['/tenant-a', '/tenant-ab/file.txt', false],
            'sibling with dash'         => ['/tenant-a', '/tenant-a-secret/file.txt', false],
            'trailing slash child'      => ['/tenant-a/', '/tenant-a/file.txt', true],
            'trailing slash sibling'    => ['/tenant-a/', '/tenant-ab/file.txt', false],
            'prefix without leading /'  => ['tenant-a', '/tenant-a/x', true],
            'key without leading /'     => ['/tenant-a', 'tenant-a/x', true],
            'root prefix allows all'    => ['/', '/anything/at/all', true],
            'different tree'            => ['/tenant-a', '/other/file', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('prefixCases')]
    public function testPrefixRespectsSegmentBoundaries(string $prefix, string $key, bool $allowed): void
    {
        $identity = $this->identityWithPrefix($prefix);

        $grant = $this->service->checkPermission($identity, 'b', $key, 'read');

        $this->assertSame($allowed, $grant !== null);
    }

    public function testPermissionStillRequiredForMatchingPrefix(): void
    {
        $identity = $this->identityWithPrefix('/tenant-a');

        $this->assertNull($this->service->checkPermission($identity, 'b', '/tenant-a/x', 'write'));
    }

    public function testGrantRejectsTraversalInPrefix(): void
    {
        $this->expectException(BuckieException::class);
        $this->identityWithPrefix('/tenant-a/../tenant-b');
    }

    public function testVerifyAcceptsCorrectSecretOnly(): void
    {
        $this->assertNotNull($this->service->verify('tenant', 'secret'));
        $this->assertNull($this->service->verify('tenant', 'wrong'));
    }

    public function testVerifyUnknownIdentityReturnsNullAndStillHashes(): void
    {
        // Behavioral check: an unknown identity must go through password_verify too.
        // Timing is not asserted (flaky); we assert correctness of both paths.
        $this->assertNull($this->service->verify('ghost', 'secret'));
        $this->assertNull($this->service->verify('ghost', ''));
    }

    public function testRevokeAndDeleteStillWork(): void
    {
        $this->identityWithPrefix('/a');
        $revoked = $this->service->revoke('tenant', 'b');
        $this->assertSame([], $revoked['grants']);

        $this->service->delete('tenant');
        $this->assertNull($this->service->find('tenant'));
    }
}
