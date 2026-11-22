<?php

declare(strict_types=1);

namespace Buckie\Config;

class ConfigService
{
    public const VERSION = '1.0.0';

    private const DEFAULT_PORT     = 8080;
    private const DEFAULT_HOST     = '0.0.0.0';
    private const DEFAULT_LOG_LEVEL = 'info';
    private const DEFAULT_MAX_UPLOAD_BYTES = 536_870_912; // 512 MiB, matches the Docker PHP limits

    // ── Data directory resolution ─────────────────────────────────────────────

    public static function getDataDir(): string
    {
        // Never derive the default from the current working directory: under
        // FPM/FrankenPHP the cwd is often the webroot, which would expose config.json.
        return getenv('BUCKIE_DATA_DIR') ?: (dirname(__DIR__, 2) . '/.buckie');
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

    /** Maximum accepted upload size in bytes (BUCKIE_MAX_UPLOAD_BYTES); 0 means unlimited. */
    public static function getMaxUploadBytes(): int
    {
        $env = getenv('BUCKIE_MAX_UPLOAD_BYTES');
        return ($env !== false && ctype_digit($env)) ? (int) $env : self::DEFAULT_MAX_UPLOAD_BYTES;
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
                if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
                    throw new \RuntimeException("Cannot create directory: {$dir}");
                }
            }
        }
    }

    // ── Config read / write ───────────────────────────────────────────────────

    /**
     * Read the config. A missing file yields an empty config; a corrupt file
     * throws so that callers never overwrite existing data with an empty config.
     */
    public static function readConfig(string $dataDir): array
    {
        $file = self::getConfigFile($dataDir);

        if (!file_exists($file)) {
            return ['buckets' => [], 'identities' => []];
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read config file: {$file}");
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            throw new \RuntimeException("Config file is not valid JSON: {$file}");
        }

        return [
            'buckets'    => $parsed['buckets']    ?? [],
            'identities' => $parsed['identities'] ?? [],
        ];
    }

    public static function writeConfig(string $dataDir, array $config): void
    {
        if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
            throw new \RuntimeException("Cannot create data directory: {$dataDir}");
        }

        $file = self::getConfigFile($dataDir);
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode config as JSON');
        }

        // Atomic write via temp file + rename (temp file is 0600 and cleaned up on failure)
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        $fp  = @fopen($tmp, 'xb');
        if ($fp === false) {
            throw new \RuntimeException("Cannot create temp config file: {$tmp}");
        }
        @chmod($tmp, 0600);

        $ok = fwrite($fp, $json) === strlen($json);
        $ok = fclose($fp) && $ok;

        if (!$ok || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to write config file: {$file}");
        }
    }

    /**
     * Read-modify-write the config under an exclusive lock so concurrent
     * processes (CLI + HTTP) cannot lose each other's changes.
     *
     * The callback receives the current config and returns the new one,
     * or null to skip writing. Its other return values are not used.
     *
     * @param callable(array): ?array $mutate
     */
    public static function update(string $dataDir, callable $mutate): void
    {
        if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
            throw new \RuntimeException("Cannot create data directory: {$dataDir}");
        }

        $lockFile = self::getConfigFile($dataDir) . '.lock';
        $lock     = fopen($lockFile, 'cb');
        if ($lock === false) {
            throw new \RuntimeException("Cannot open config lock: {$lockFile}");
        }
        @chmod($lockFile, 0600);

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException("Cannot lock config: {$lockFile}");
            }

            $new = $mutate(self::readConfig($dataDir));
            if ($new !== null) {
                self::writeConfig($dataDir, $new);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
