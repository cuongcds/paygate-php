<?php

declare(strict_types=1);

namespace PayGate;

use PayGate\Exceptions\PayGateException;
use PayGate\Exceptions\TransportException;

/**
 * PayGate API client — see https://github.com/cuongcds/paygate-docs for the
 * full API reference this wraps.
 *
 * Construct with exactly one auth strategy:
 *   Client::withHmac($baseUrl, $apiKey, $apiSecret)
 *   Client::withFirebaseIdToken($baseUrl, $idToken)
 */
final class Client
{
    private string $baseUrl;
    private ?string $apiKey;
    private ?HmacSigner $signer;
    private ?string $bearerToken;

    private function __construct(
        string $baseUrl,
        ?string $apiKey,
        ?HmacSigner $signer,
        ?string $bearerToken
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->signer = $signer;
        $this->bearerToken = $bearerToken;
    }

    public static function withHmac(string $baseUrl, string $apiKey, string $apiSecret): self
    {
        return new self($baseUrl, $apiKey, new HmacSigner($apiSecret), null);
    }

    public static function withFirebaseIdToken(string $baseUrl, string $idToken): self
    {
        return new self($baseUrl, null, null, $idToken);
    }

    /**
     * POST /api/v1/checkout-sessions — see documents/api-reference.md.
     *
     * @param array<string, mixed> $params plan_ref, amount, currency, mode,
     *   success_url, cancel_url, and (HMAC only) external_ref, plus
     *   interval/interval_count for mode=subscription. payment_method/
     *   test_card_code are optional — omit them for the normal flow.
     * @return array<string, mixed> the `data` object (e.g. ["checkout_url" => ...]
     *   or ["status" => ..., "subscription" => [...]] for payment_method=test)
     */
    public function createCheckoutSession(array $params): array
    {
        return $this->request('POST', '/api/v1/checkout-sessions', $params);
    }

    /**
     * GET /api/v1/subscriptions/{externalRef} — see documents/api-reference.md.
     *
     * @return array{plan_ref: string, status: string, current_period_end: ?string}
     */
    public function getSubscription(string $externalRef): array
    {
        return $this->request('GET', '/api/v1/subscriptions/' . rawurlencode($externalRef));
    }

    /**
     * GET /api/v1/transactions/{transactionId} — see
     * documents/03.05-transactions.md. Use this to verify the
     * paygate_transaction_id/paygate_external_ref query params PayGate
     * appends to your success_url/cancel_url before trusting the redirect.
     *
     * @param int|string $transactionId
     * @return array{transaction_id: int, external_ref: string, plan_ref: string,
     *   amount: int, currency: string, mode: string, status: string, created_at: string}
     */
    public function getTransaction($transactionId): array
    {
        return $this->request('GET', '/api/v1/transactions/' . rawurlencode((string) $transactionId));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws PayGateException PayGate answered with success=false
     * @throws TransportException the request never got a valid response
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $rawBody = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $url = $this->baseUrl . $path;
        $timestamp = time();

        $headers = ['Content-Type: application/json'];
        if ($this->bearerToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->bearerToken;
        } else {
            $signature = $this->signer->sign($method, $path, $rawBody, $timestamp);
            $headers[] = 'X-App-Key: ' . $this->apiKey;
            $headers[] = 'X-Timestamp: ' . $timestamp;
            $headers[] = 'X-Signature: ' . $signature;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new TransportException('Request to PayGate failed: ' . $error);
        }
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded) || !array_key_exists('success', $decoded)) {
            throw new TransportException('PayGate returned a non-JSON or unexpected response.');
        }

        if ($decoded['success'] === true) {
            return $decoded['data'] ?? [];
        }

        $error = $decoded['error'] ?? [];

        throw new PayGateException(
            $error['code'] ?? 'unknown_error',
            $error['message'] ?? 'PayGate returned an error with no message.',
            $httpStatus ?: 400
        );
    }
}
