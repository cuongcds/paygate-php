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
     * Two shapes, selected by `mode`:
     *  - mode=subscription: price_id + customer_id, optionally skip_trial,
     *    success_url, cancel_url, external_ref, payment_method. Never send
     *    plan_ref/amount/currency/interval/interval_count/plan_id — they are
     *    derived from the price.
     *  - mode=payment: plan_ref, amount, currency, success_url, cancel_url,
     *    and (HMAC only) external_ref.
     *
     * payment_method is optional ("stripe", "payos", or "test") — omit it for
     * the normal flow. This endpoint never accepts a test_card_code and never
     * resolves a payment result itself: checkout_url points to PayGate's
     * hosted picker page, even when payment_method="test", where the
     * test_card_code is actually submitted. See documents/03.04-testing.md.
     *
     * @param array<string, mixed> $params
     * @return array{checkout_url: string, activated?: bool, trial?: ?array{amount: int,
     *   interval_unit: string, interval_count: int}} `activated` and `trial`
     *   are returned for mode=subscription only
     */
    public function createCheckoutSession(array $params): array
    {
        return $this->request('POST', '/api/v1/checkout-sessions', $params);
    }

    /**
     * POST /api/v1/customers.
     *
     * @param array{name: string, email: string, cc_emails?: string[], bcc_emails?: string[],
     *   merchant_customer_id?: string, locale?: 'en'|'vi'} $params
     * @return array{id: string, name: string, email: string, cc_emails: string[],
     *   bcc_emails: string[], merchant_customer_id: ?string, locale: string,
     *   created_at: string, updated_at: string}
     */
    public function createCustomer(array $params): array
    {
        return $this->request('POST', '/api/v1/customers', $params);
    }

    /**
     * GET /api/v1/customers/{customerId}.
     *
     * @return array<string, mixed> same shape as createCustomer()
     */
    public function getCustomer(string $customerId): array
    {
        return $this->request('GET', '/api/v1/customers/' . rawurlencode($customerId));
    }

    /**
     * PATCH /api/v1/customers/{customerId} — partial update.
     *
     * @param array{name?: string, email?: string, cc_emails?: string[], bcc_emails?: string[],
     *   merchant_customer_id?: string, locale?: 'en'|'vi'} $params
     * @return array<string, mixed> same shape as createCustomer()
     */
    public function updateCustomer(string $customerId, array $params): array
    {
        return $this->request('PATCH', '/api/v1/customers/' . rawurlencode($customerId), $params);
    }

    /**
     * GET /api/v1/plans[?status=active|archived]. The query string is sent but
     * never signed — the signature covers the path only.
     *
     * @param array{status?: 'active'|'archived'} $filters
     * @return list<array<string, mixed>> plans, each as returned by getPlan()
     */
    public function listPlans(array $filters = []): array
    {
        $data = $this->request('GET', '/api/v1/plans', null, http_build_query($filters, '', '&', PHP_QUERY_RFC3986));

        return $data['plans'] ?? [];
    }

    /**
     * GET /api/v1/plans/{planId}.
     *
     * @return array{id: string, code: string, name: string, description: ?string,
     *   status: string, prices: list<array{id: string, name: ?string, amount: int,
     *   currency: string, interval_unit: string, interval_count: int,
     *   trial: ?array{amount: int, interval_unit: string, interval_count: int},
     *   status: string, created_at: string}>, created_at: string, updated_at: string}
     */
    public function getPlan(string $planId): array
    {
        return $this->request('GET', '/api/v1/plans/' . rawurlencode($planId));
    }

    /**
     * POST /api/v1/plans — a plan holds many prices on sale at once. HMAC only.
     *
     * @param array{code: string, name: string, description?: string,
     *   prices: list<array{name?: string, amount: int, currency: string,
     *   interval_unit: 'day'|'week'|'month'|'year', interval_count: int,
     *   trial?: ?array{amount: int, interval_unit: string, interval_count: int}}>} $params
     * @return array<string, mixed> same shape as getPlan()
     */
    public function createPlan(array $params): array
    {
        return $this->request('POST', '/api/v1/plans', $params);
    }

    /**
     * PATCH /api/v1/plans/{planId} — rename, re-describe, or archive. HMAC only.
     *
     * @param array{name?: string, description?: string, status?: 'active'|'archived'} $params
     * @return array<string, mixed> same shape as getPlan()
     */
    public function updatePlan(string $planId, array $params): array
    {
        return $this->request('PATCH', '/api/v1/plans/' . rawurlencode($planId), $params);
    }

    /**
     * POST /api/v1/plans/{planId}/prices — put another price on sale. HMAC only.
     *
     * @param array{name?: string, amount: int, currency: string,
     *   interval_unit: 'day'|'week'|'month'|'year', interval_count: int,
     *   trial?: ?array{amount: int, interval_unit: string, interval_count: int}} $price
     * @return array<string, mixed> same shape as getPlan()
     */
    public function addPlanPrice(string $planId, array $price): array
    {
        return $this->request('POST', '/api/v1/plans/' . rawurlencode($planId) . '/prices', $price);
    }

    /**
     * PATCH /api/v1/plans/{planId}/prices/{priceId} — rename or archive a
     * price. HMAC only.
     *
     * @param array{name?: string, status?: 'active'|'archived'} $params
     * @return array<string, mixed> same shape as getPlan()
     */
    public function updatePlanPrice(string $planId, string $priceId, array $params): array
    {
        return $this->request(
            'PATCH',
            '/api/v1/plans/' . rawurlencode($planId) . '/prices/' . rawurlencode($priceId),
            $params
        );
    }

    /**
     * GET /api/v1/subscriptions/{externalRef} — see documents/api-reference.md.
     *
     * @return array{plan_ref: string, plan_id: ?string, price_id: ?string,
     *   customer_id: ?string, status: string, current_period_end: ?string,
     *   in_trial: bool, trial_ends_at: ?string}
     */
    public function getSubscription(string $externalRef): array
    {
        return $this->request('GET', '/api/v1/subscriptions/' . rawurlencode($externalRef));
    }

    /**
     * PUT /api/v1/subscriptions/{externalRef}/customer — attach a customer.
     *
     * @return array<string, mixed> same shape as getSubscription()
     */
    public function setSubscriptionCustomer(string $externalRef, string $customerId): array
    {
        return $this->request(
            'PUT',
            '/api/v1/subscriptions/' . rawurlencode($externalRef) . '/customer',
            ['customer_id' => $customerId]
        );
    }

    /**
     * PUT /api/v1/subscriptions/{externalRef}/plan — switch to another price,
     * effective from the next renewal. HMAC only.
     *
     * @return array<string, mixed> same shape as getSubscription()
     */
    public function setSubscriptionPrice(string $externalRef, string $priceId): array
    {
        return $this->request(
            'PUT',
            '/api/v1/subscriptions/' . rawurlencode($externalRef) . '/plan',
            ['price_id' => $priceId]
        );
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
    private function request(string $method, string $path, ?array $body = null, string $query = ''): array
    {
        $rawBody = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // The signature covers the path only, never the query string.
        $url = $this->baseUrl . $path . ($query !== '' ? '?' . $query : '');
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
