<?php

declare(strict_types=1);

namespace Buckie\Config;

class ConfigService
{
    private const DEFAULT_PORT     = 8080;
    private const DEFAULT_HOST     = '0.0.0.0';
    private const DEFAULT_LOG_LEVEL = 'info';

    // ── Data directory resolution ─────────────────────────────────────────────

    public static function getDataDir(): string
    {
        return getenv('BUCKIE_DATA_DIR') ?: (getcwd() . '/.buckie');
    }

    public static function getHost(): string
    {
        return getenv('BUCKIE_HOST') ?: self::DEFAULT_HOST;
    }

    public static function getPort(): int
    {
        $env = getenv('BUCKIE_PORT');
        return $env !== false ? (int) $env : self::DEFAULT_PORT;
    }

    public static function getLogLevel(): string
    {
        return getenv('BUCKIE_LOG_LEVEL') ?: self::DEFAULT_LOG_LEVEL;
    }

    // ── Path helpers ──────────────────────────────────────────────────────────

    public static function getConfigFile(string $dataDir): string
    {
        return $dataDir . '/config.json';
    }

    public static function getLogsDir(string $dataDir): string
    {
        return $dataDir . '/logs';
    }

    public static function getCacheDir(string $dataDir): string
    {
        return $dataDir . '/cache';
    }

    // ── Directory bootstrap ───────────────────────────────────────────────────

    public static function ensureDataDirs(string $dataDir): void
    {
        foreach ([
            $dataDir,
            self::getLogsDir($dataDir),
            self::getCacheDir($dataDir),
        ] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    // ── Config read / write ───────────────────────────────────────────────────

    public static function readConfig(string $dataDir): array
    {
        $file = self::getConfigFile($dataDir);

        if (!file_exists($file)) {
            return ['buckets' => [], 'identities' => []];
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return ['buckets' => [], 'identities' => []];
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return ['buckets' => [], 'identities' => []];
        }

        return [
            'buckets'    => $parsed['buckets']    ?? [],
            'identities' => $parsed['identities'] ?? [],
        ];
    }

    public static function writeConfig(string $dataDir, array $config): void
    {
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $file = self::getConfigFile($dataDir);
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode config as JSON');
        }

        // Atomic write via temp file + rename
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $json);
        rename($tmp, $file);
    }
}

