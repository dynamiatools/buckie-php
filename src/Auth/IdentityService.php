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

        ConfigService::update($this->dataDir, function (array $cfg) use ($id, $identity): array {
            foreach ($cfg['identities'] as $existing) {
                if ($existing['identity'] === $identity) {
                    throw BuckieException::conflict(
                        "Identity '{$identity}' already exists",
                        'BUCKIE_IDENTITY_ALREADY_EXISTS'
                    );
                }
            }
            $cfg['identities'][] = $id;
            return $cfg;
        });

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

        // Always run password_verify, even for unknown identities, so response
        // time does not reveal which identities exist.
        $hash  = $id['hashedSecret'] ?? self::dummyHash();
        $valid = password_verify($secret, $hash);

        return ($id !== null && $valid) ? $id : null;
    }

    public function grant(string $identity, array $grant): array
    {
        foreach ($grant['prefixes'] ?? [] as $prefix) {
            if (!is_string($prefix) || str_contains($prefix, "\0")
                || in_array('..', explode('/', $prefix), true)
            ) {
                throw BuckieException::badRequest("Invalid grant prefix: {$prefix}", 'BUCKIE_PREFIX_INVALID');
            }
        }

        $result = null;
        ConfigService::update($this->dataDir, function (array $cfg) use ($identity, $grant, &$result): array {
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

            $result = $cfg['identities'][$idx];
            return $cfg;
        });

        return $result;
    }

    public function revoke(string $identity, string $bucket): array
    {
        $result = null;
        ConfigService::update($this->dataDir, function (array $cfg) use ($identity, $bucket, &$result): array {
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

            $result = $cfg['identities'][$idx];
            return $cfg;
        });

        return $result;
    }

    public function delete(string $identity): void
    {
        ConfigService::update($this->dataDir, function (array $cfg) use ($identity): array {
            $before = count($cfg['identities']);

            $cfg['identities'] = array_values(
                array_filter($cfg['identities'], fn($id) => $id['identity'] !== $identity)
            );

            if (count($cfg['identities']) === $before) {
                throw BuckieException::notFound("Identity '{$identity}'");
            }

            return $cfg;
        });
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
                if (self::matchesPrefix($normalizedKey, $normalizedPrefix)) {
                    return $grant;
                }
            }
        }

        return null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Prefix match that respects path-segment boundaries: '/tenant-a' matches
     * '/tenant-a' and '/tenant-a/x' but never '/tenant-ab/x'.
     * A prefix ending in '/' is already a segment boundary.
     */
    private static function matchesPrefix(string $key, string $prefix): bool
    {
        if ($prefix === '/') {
            return true;
        }
        if (str_ends_with($prefix, '/')) {
            return str_starts_with($key, $prefix);
        }
        return $key === $prefix || str_starts_with($key, $prefix . '/');
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= password_hash('buckie-dummy-secret', PASSWORD_DEFAULT);
    }

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

