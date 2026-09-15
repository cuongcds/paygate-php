<?php

declare(strict_types=1);

namespace PayGate;

/**
 * Computes the X-Signature header for the HMAC auth strategy — see
 * documents/authentication.md. Signed string is
 * "{timestamp}.{METHOD}.{path}.{raw_body}", HMAC-SHA256, lowercase hex.
 */
final class HmacSigner
{
    private string $apiSecret;

    public function __construct(string $apiSecret)
    {
        $this->apiSecret = $apiSecret;
    }

    /**
     * @param string $path Path only, no query string, with a leading slash
     *                     (e.g. "/api/v1/checkout-sessions").
     * @param string $rawBody Exact request body bytes ("" for a GET with no body).
     */
    public function sign(string $method, string $path, string $rawBody, int $timestamp): string
    {
        $signedPayload = $timestamp . '.' . strtoupper($method) . '.' . $path . '.' . $rawBody;

        return hash_hmac('sha256', $signedPayload, $this->apiSecret);
    }
}
