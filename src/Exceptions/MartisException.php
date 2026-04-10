<?php

namespace Martis\Exceptions;

use RuntimeException;

/**
 * Base exception for all Martis-specific errors.
 *
 * Every Martis exception carries a machine-readable code, a human-readable
 * message, and optional context data for logging or API serialisation.
 */
class MartisException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  Additional structured data for logging.
     */
    public function __construct(
        string $message = '',
        private readonly string $errorCode = 'martis_error',
        private readonly array $context = [],
        int $httpStatus = 500,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /**
     * Machine-readable error code (snake_case).
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Additional structured context for logging / debugging.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * HTTP status code to use when this exception surfaces in an API response.
     */
    public function httpStatus(): int
    {
        return $this->getCode();
    }

    /**
     * Convert to a serialisable array suitable for JSON API error responses.
     *
     * @return array{code: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ];
    }
}
