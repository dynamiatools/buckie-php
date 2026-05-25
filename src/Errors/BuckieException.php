<?php

declare(strict_types=1);

namespace Buckie\Errors;

class BuckieException extends \RuntimeException
{
    public function __construct(
        private readonly string $buckieCode,
        string $message,
        private readonly int $httpStatus = 500,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function getBuckieCode(): string
    {
        return $this->buckieCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function toArray(): array
    {
        return [
            'ok'    => false,
            'error' => [
                'code'    => $this->buckieCode,
                'message' => $this->getMessage(),
            ],
        ];
    }

    // ── Factory helpers ───────────────────────────────────────────────────────

    public static function notFound(string $resource): self
    {
        return new self('BUCKIE_NOT_FOUND', "{$resource} not found", 404);
    }

    public static function conflict(string $message, string $buckieCode = 'BUCKIE_CONFLICT'): self
    {
        return new self($buckieCode, $message, 409);
    }

    public static function forbidden(string $message): self
    {
        return new self('BUCKIE_FORBIDDEN', $message, 403);
    }

    public static function unauthorized(string $message = 'Authentication required'): self
    {
        return new self('BUCKIE_UNAUTHORIZED', $message, 401);
    }

    public static function badRequest(string $message, string $buckieCode = 'BUCKIE_BAD_REQUEST'): self
    {
        return new self($buckieCode, $message, 400);
    }

    public static function pathTraversal(): self
    {
        return new self('BUCKIE_PATH_TRAVERSAL', 'Path traversal detected', 400);
    }

    public static function pathReserved(string $path): self
    {
        return new self('BUCKIE_PATH_RESERVED', "Access to reserved path '{$path}' is forbidden", 403);
    }
}




