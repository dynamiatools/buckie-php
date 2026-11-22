<?php

declare(strict_types=1);

namespace Buckie\Config;

class ConfigService
{
    public const VERSION = '1.1.0';

    private const DEFAULT_PORT     = 8080;
    private const DEFAULT_HOST     = '0.0.0.0';
    private const DEFAULT_LOG_LEVEL = 'info';
    private const DEFAULT_MAX_UPLOAD_BYTES = 0; // unlimited, as in 1.0.0; PHP's own limits still apply

    // ── Data directory resolution ─────────────────────────────────────────────

    /**
     * Resolve the data directory.
     *
     * Order: BUCKIE_DATA_DIR, then <project root>/.buckie, then (legacy, 1.0.0
     * behavior) <cwd>/.buckie when it already holds a config.json. The cwd is
     * never used for a NEW installation: under FPM/FrankenPHP it is often the
     * webroot, which would expose config.json.
     */
    public static function getDataDir(): string
    {
        $env = getenv('BUCKIE_DATA_DIR');
        if ($env !== false && $env !== '') {
            return $env;
        }

        $default = dirname(__DIR__, 2) . '/.buckie';
        if (is_file($default . '/config.json')) {
            return $default;
        }

        $cwd = getcwd();
        if ($cwd !== false && is_file($cwd . '/.buckie/config.json')) {
            self::warnOnce(
                "Using the legacy data directory '{$cwd}/.buckie' (derived from the working directory). "
                . "Set BUCKIE_DATA_DIR to this path to make it explicit, and keep it outside the web root."
            );
            return $cwd . '/.buckie';
        }

        return $default;
    }

    private static function warnOnce(string $message): void
    {
        static $seen = [];
        if (!isset($seen[$message])) {
            $seen[$message] = true;
            error_log('[Buckie] Warning: ' . $message);
        }
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

    /** Maximum accepted upload size in bytes (BUCKIE_MAX_UPLOAD_BYTES); 0 (default) means unlimited. */
    public static function getMaxUploadBytes(): int
    {
        $env = getenv('BUCKIE_MAX_UPLOAD_BYTES');
        return ($env !== false && ctype_digit($env)) ? (int) $env : self::DEFAULT_MAX_UPLOAD_BYTES;
    }

    /**
     * Opt-in: follow symlinks inside a bucket even when they point outside it
     * (BUCKIE_FOLLOW_SYMLINKS=1). Only for operators who deliberately link
     * directories from other disks into a bucket.
     */
    public static function followExternalSymlinks(): bool
    {
        return getenv('BUCKIE_FOLLOW_SYMLINKS') === '1';
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

        // A zero-byte or whitespace-only file is an empty config (as in 1.0.0)
        if (trim($raw) === '') {
            return ['buckets' => [], 'identities' => []];
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

        // Keep the mode and owner of an existing config (the web server user may only be able to
        // read it through them); a brand-new config is private (0600).
        $mode = 0600;
        $uid  = null;
        $gid  = null;
        $ref  = is_file($file) ? @stat($file) : false;
        if ($ref !== false) {
            $mode = $ref['mode'] & 0777;
            $uid  = $ref['uid'];
            $gid  = $ref['gid'];
        } elseif (function_exists('posix_geteuid') && posix_geteuid() === 0 && ($dirStat = @stat($dataDir)) !== false) {
            // Running as root (e.g. `docker exec`): hand the new file to the data dir's owner
            $uid = $dirStat['uid'];
            $gid = $dirStat['gid'];
        }

        // Atomic write via temp file + rename (cleaned up on failure)
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        $fp  = @fopen($tmp, 'xb');
        if ($fp === false) {
            throw new \RuntimeException("Cannot create temp config file: {$tmp}");
        }
        @chmod($tmp, $mode);
        if ($uid !== null && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chown($tmp, $uid);
            @chgrp($tmp, $gid);
        }

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
        $lock     = @fopen($lockFile, 'cb');
        if ($lock !== false) {
            @chmod($lockFile, 0666 & ~umask());
        }
        // shortcut: if the lock file cannot be opened (e.g. it was created by another user), we fall
        // back to the unlocked 1.0.0 behavior instead of failing. Revisit once all deployments
        // run the CLI as a single user.

        try {
            if ($lock !== false && !flock($lock, LOCK_EX)) {
                throw new \RuntimeException("Cannot lock config: {$lockFile}");
            }

            $new = $mutate(self::readConfig($dataDir));
            if ($new !== null) {
                self::writeConfig($dataDir, $new);
            }
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
