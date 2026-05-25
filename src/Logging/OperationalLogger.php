<?php

declare(strict_types=1);

namespace Buckie\Logging;

use Buckie\Config\ConfigService;

class OperationalLogger
{
    private string $accessLog;
    private string $errorLog;

    public function __construct(private readonly string $dataDir)
    {
        $logsDir         = ConfigService::getLogsDir($dataDir);
        $this->accessLog = $logsDir . '/access.log.jsonl';
        $this->errorLog  = $logsDir . '/error.log.jsonl';
    }

    public function log(array $context): void
    {
        $entry = array_merge([
            'ts'      => (new \DateTimeImmutable())->format(\DateTime::ATOM),
            'level'   => 'info',
        ], $context);

        $this->append($this->accessLog, $entry);
    }

    public function logError(string $event, string|\Throwable $error, array $context = []): void
    {
        $message = $error instanceof \Throwable ? $error->getMessage() : $error;

        $entry = array_merge([
            'ts'      => (new \DateTimeImmutable())->format(\DateTime::ATOM),
            'level'   => 'error',
            'event'   => $event,
            'message' => $message,
        ], $context);

        $this->append($this->errorLog, $entry);
    }

    private function append(string $file, array $entry): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        // Use locking to avoid concurrent write corruption
        $fp = fopen($file, 'ab');
        if ($fp === false) {
            return;
        }
        flock($fp, LOCK_EX);
        fwrite($fp, $line);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

