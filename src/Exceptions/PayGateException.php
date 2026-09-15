<?php

declare(strict_types=1);

namespace PayGate\Exceptions;

use RuntimeException;

/**
 * Base exception for every error PayGate's API returns (`error.code` in the
 * response envelope) — see documents/errors.md. Thrown by Client for any
 * `success: false` response; network/transport failures throw
 * TransportException instead.
 */
class PayGateException extends RuntimeException
{
    private string $errorCode;

    public function __construct(string $errorCode, string $message, int $httpStatus)
    {
        parent::__construct($message, $httpStatus);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->getCode();
    }
}
