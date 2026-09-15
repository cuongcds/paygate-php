<?php

declare(strict_types=1);

namespace PayGate\Exceptions;

use RuntimeException;

/**
 * The request never reached PayGate or no valid HTTP response came back
 * (DNS, timeout, connection refused, malformed body) — as opposed to
 * PayGateException, which means PayGate itself answered with an error.
 */
class TransportException extends RuntimeException
{
}
