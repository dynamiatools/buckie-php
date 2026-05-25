<?php

declare(strict_types=1);

namespace Buckie\Auth;

use Buckie\Config\ConfigService;
use Buckie\Errors\BuckieException;

class IdentityService
{
    public function __construct(private readonly string $dataDir) {}

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public function create(string $identity, string $secret): array
    {
        if ($this->find($identity) !== null) {
            throw BuckieException::conflict(
                "Identity '{$identity}' already exists",
                'BUCKIE_IDENTITY_ALREADY_EXISTS'
            );
        }

        $hashedSecret = password_hash($secret, PASSWORD_DEFAULT);
        $now          = (new \DateTimeImmutable())->format(\DateTime::ATOM);

        $id = [
            'identity'     => $identity,
            'hashedSecret' => $hashedSecret,
            'grants'       => [],
            'createdAt'    => $now,
            'updatedAt'    => $now,
        ];

        $cfg = ConfigService::readConfig($this->dataDir);
        $cfg['identities'][] = $id;
        ConfigService::writeConfig($this->dataDir, $cfg);

        return $id;
    }

    public function find(string $identity): ?array
    {
        $cfg = ConfigService::readConfig($this->dataDir);
        foreach ($cfg['identities'] as $id) {
            if ($id['identity'] === $identity) {
                return $id;
            }
        }
        return null;
    }

    public function get(string $identity): array
    {
        $id = $this->find($identity);
        if ($id === null) {
            throw BuckieException::notFound("Identity '{$identity}'");
        }
        return $id;
    }

    public function list(): array
    {
        $cfg = ConfigService::readConfig($this->dataDir);
        return $cfg['identities'];
    }

    /**
     * Verify the provided secret against the stored hash.
     * Returns the full identity record on success, null on failure.
     */
    public function verify(string $identity, string $secret): ?array
    {
        $id = $this->find($identity);
        if ($id === null) {
            return null;
        }

        return password_verify($secret, $id['hashedSecret']) ? $id : null;
    }

    public function grant(string $identity, array $grant): array
    {
        $cfg = ConfigService::readConfig($this->dataDir);
        $idx = $this->findIndex($cfg['identities'], $identity);

        if ($idx < 0) {
            throw BuckieException::notFound("Identity '{$identity}'");
        }

        // Replace existing grant for the same bucket
        $cfg['identities'][$idx]['grants'] = array_values(
            array_filter(
                $cfg['identities'][$idx]['grants'],
                fn($g) => $g['bucket'] !== $grant['bucket']
            )
        );
        $cfg['identities'][$idx]['grants'][] = $grant;
        $cfg['identities'][$idx]['updatedAt'] = (new \DateTimeImmutable())->format(\DateTime::ATOM);

        ConfigService::writeConfig($this->dataDir, $cfg);
        return $cfg['identities'][$idx];
    }

    public function revoke(string $identity, string $bucket): array
    {
        $cfg = ConfigService::readConfig($this->dataDir);
        $idx = $this->findIndex($cfg['identities'], $identity);

        if ($idx < 0) {
            throw BuckieException::notFound("Identity '{$identity}'");
        }

        $cfg['identities'][$idx]['grants'] = array_values(
            array_filter(
                $cfg['identities'][$idx]['grants'],
                fn($g) => $g['bucket'] !== $bucket
            )
        );
        $cfg['identities'][$idx]['updatedAt'] = (new \DateTimeImmutable())->format(\DateTime::ATOM);

        ConfigService::writeConfig($this->dataDir, $cfg);
        return $cfg['identities'][$idx];
    }

    public function delete(string $identity): void
    {
        $cfg    = ConfigService::readConfig($this->dataDir);
        $before = count($cfg['identities']);

        $cfg['identities'] = array_values(
            array_filter($cfg['identities'], fn($id) => $id['identity'] !== $identity)
        );

        if (count($cfg['identities']) === $before) {
            throw BuckieException::notFound("Identity '{$identity}'");
        }

        ConfigService::writeConfig($this->dataDir, $cfg);
    }

    // ── Permission check ──────────────────────────────────────────────────────

    /**
     * Returns the matching grant or null if the identity lacks the permission.
     */
    public function checkPermission(array $identity, string $bucket, string $key, string $permission): ?array
    {
        foreach ($identity['grants'] as $grant) {
            if ($grant['bucket'] !== $bucket) {
                continue;
            }
            if (!in_array($permission, $grant['permissions'], true)) {
                continue;
            }

            $prefixes = $grant['prefixes'] ?? [];
            if (empty($prefixes)) {
                return $grant;
            }

            $normalizedKey = str_starts_with($key, '/') ? $key : "/{$key}";

            foreach ($prefixes as $prefix) {
                $normalizedPrefix = str_starts_with($prefix, '/') ? $prefix : "/{$prefix}";
                if ($normalizedPrefix === '/' || str_starts_with($normalizedKey, $normalizedPrefix)) {
                    return $grant;
                }
            }
        }

        return null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function findIndex(array $identities, string $name): int
    {
        foreach ($identities as $i => $id) {
            if ($id['identity'] === $name) {
                return $i;
            }
        }
        return -1;
    }
}

