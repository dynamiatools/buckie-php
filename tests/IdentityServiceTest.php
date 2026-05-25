<?php

declare(strict_types=1);

namespace Buckie\Tests;

use Buckie\Auth\IdentityService;
use Buckie\Config\ConfigService;
use Buckie\Errors\BuckieException;
use PHPUnit\Framework\TestCase;

class IdentityServiceTest extends TestCase
{
    private string $tempDir;
    private IdentityService $identityService;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/buckie-test-' . bin2hex(random_bytes(8));
        ConfigService::ensureDataDirs($this->tempDir);
        $this->identityService = new IdentityService($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public function testCreateIdentity(): void
    {
        $identity = $this->identityService->create('test-user', 'secret123');

        $this->assertSame('test-user', $identity['identity']);
        $this->assertNotSame('secret123', $identity['hashedSecret']);
        $this->assertSame([], $identity['grants']);
        $this->assertNotEmpty($identity['createdAt']);
    }

    public function testCreateDuplicateThrows(): void
    {
        $this->identityService->create('duplicate', 'pass');

        $this->expectException(BuckieException::class);
        $this->expectExceptionMessageMatches('/already exists/i');
        $this->identityService->create('duplicate', 'other');
    }

    public function testFindExistingIdentity(): void
    {
        $this->identityService->create('findme', 'pass');
        $found = $this->identityService->find('findme');

        $this->assertNotNull($found);
        $this->assertSame('findme', $found['identity']);
    }

    public function testFindReturnsNullForUnknown(): void
    {
        $this->assertNull($this->identityService->find('nobody'));
    }

    public function testGetThrowsForMissing(): void
    {
        $this->expectException(BuckieException::class);
        $this->identityService->get('ghost');
    }

    public function testListReturnsAllIdentities(): void
    {
        $this->identityService->create('user-a', 'pass');
        $this->identityService->create('user-b', 'pass');

        $list = $this->identityService->list();
        $this->assertCount(2, $list);
    }

    public function testDeleteIdentity(): void
    {
        $this->identityService->create('deletable', 'pass');
        $this->identityService->delete('deletable');

        $this->assertNull($this->identityService->find('deletable'));
    }

    public function testDeleteThrowsForMissing(): void
    {
        $this->expectException(BuckieException::class);
        $this->identityService->delete('ghost');
    }

    // ── Verify ────────────────────────────────────────────────────────────────

    public function testVerifyCorrectCredentials(): void
    {
        $this->identityService->create('verifiable', 'my-secret');
        $result = $this->identityService->verify('verifiable', 'my-secret');

        $this->assertNotNull($result);
        $this->assertSame('verifiable', $result['identity']);
    }

    public function testVerifyIncorrectPasswordReturnsNull(): void
    {
        $this->identityService->create('user1', 'correct-pass');
        $result = $this->identityService->verify('user1', 'wrong-pass');

        $this->assertNull($result);
    }

    public function testVerifyUnknownIdentityReturnsNull(): void
    {
        $result = $this->identityService->verify('unknown', 'any');
        $this->assertNull($result);
    }

    // ── Permissions ──────────────────────────────────────────────────────────

    public function testGrantAndCheckPermission(): void
    {
        $this->identityService->create('perm-user', 'pass');
        $this->identityService->grant('perm-user', [
            'bucket'      => 'docs',
            'prefixes'    => ['/tenant-a/'],
            'permissions' => ['read', 'write'],
        ]);

        $updated = $this->identityService->get('perm-user');

        $grant = $this->identityService->checkPermission($updated, 'docs', '/tenant-a/file.pdf', 'read');
        $this->assertNotNull($grant);

        $denied = $this->identityService->checkPermission($updated, 'docs', '/tenant-b/file.pdf', 'read');
        $this->assertNull($denied);
    }

    public function testCheckPermissionWildcardPrefix(): void
    {
        $this->identityService->create('wildcard-user', 'pass');
        $this->identityService->grant('wildcard-user', [
            'bucket'      => 'assets',
            'prefixes'    => [],
            'permissions' => ['read'],
        ]);

        $updated = $this->identityService->get('wildcard-user');

        $grant = $this->identityService->checkPermission($updated, 'assets', 'any/path/file.jpg', 'read');
        $this->assertNotNull($grant);
    }

    public function testCheckPermissionWrongBucketReturnsNull(): void
    {
        $this->identityService->create('bucket-user', 'pass');
        $this->identityService->grant('bucket-user', [
            'bucket'      => 'bucket-a',
            'prefixes'    => [],
            'permissions' => ['read'],
        ]);

        $updated = $this->identityService->get('bucket-user');

        $denied = $this->identityService->checkPermission($updated, 'bucket-b', 'file.txt', 'read');
        $this->assertNull($denied);
    }

    public function testCheckPermissionWrongActionReturnsNull(): void
    {
        $this->identityService->create('readonly-user', 'pass');
        $this->identityService->grant('readonly-user', [
            'bucket'      => 'docs',
            'prefixes'    => [],
            'permissions' => ['read'],
        ]);

        $updated = $this->identityService->get('readonly-user');

        $denied = $this->identityService->checkPermission($updated, 'docs', 'file.txt', 'write');
        $this->assertNull($denied);
    }

    public function testRevokePermissions(): void
    {
        $this->identityService->create('rev-user', 'pass');
        $this->identityService->grant('rev-user', [
            'bucket'      => 'docs',
            'prefixes'    => [],
            'permissions' => ['read'],
        ]);
        $this->identityService->revoke('rev-user', 'docs');

        $updated = $this->identityService->get('rev-user');
        $this->assertCount(0, $updated['grants']);
    }

    public function testGrantReplacesExistingGrantForSameBucket(): void
    {
        $this->identityService->create('replace-user', 'pass');
        $this->identityService->grant('replace-user', [
            'bucket'      => 'docs',
            'prefixes'    => [],
            'permissions' => ['read'],
        ]);
        $this->identityService->grant('replace-user', [
            'bucket'      => 'docs',
            'prefixes'    => [],
            'permissions' => ['read', 'write'],
        ]);

        $updated = $this->identityService->get('replace-user');
        $this->assertCount(1, $updated['grants']);
        $this->assertContains('write', $updated['grants'][0]['permissions']);
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

