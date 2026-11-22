<?php

declare(strict_types=1);

namespace Buckie\Tests\Support;

/**
 * Creates a unique temp directory per test and removes it afterwards
 * without ever following symlinks.
 */
trait TempDir
{
    protected string $tempDir = '';

    protected function makeTempDir(string $prefix = 'buckie-test'): string
    {
        $this->tempDir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0755, true);
        return $this->tempDir;
    }

    protected function removeTempDir(): void
    {
        if ($this->tempDir !== '') {
            $this->removeTree($this->tempDir);
        }
    }

    protected function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->removeTree($path . '/' . $item);
            }
        }
        @rmdir($path);
    }
}
