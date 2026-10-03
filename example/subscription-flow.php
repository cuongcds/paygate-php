<?php

/**
 * Run: php example/subscription-flow.php
 * Requires PAYGATE_BASE_URL / PAYGATE_API_KEY / PAYGATE_API_SECRET env vars.
 *
 * customer -> plan with 3 prices -> checkout with price_id -> poll subscription.
 */

require __DIR__ . '/../vendor/autoload.php';

use PayGate\Client;
use PayGate\Exceptions\PayGateException;

$client = Client::withHmac(
    getenv('PAYGATE_BASE_URL') ?: 'https://payments.example.com',
    getenv('PAYGATE_API_KEY') ?: 'pgk_your_api_key',
    getenv('PAYGATE_API_SECRET') ?: 'your_api_secret'
);

try {
    $customer = $client->createCustomer([
        'name' => 'Nguyen Van A',
        'email' => 'a@example.com',
        'merchant_customer_id' => 'user-42',
        'locale' => 'vi',
    ]);

    $plan = $client->createPlan([
        'code' => 'premium_' . time(),
        'name' => 'Premium',
        'prices' => [
            [
                'name' => 'Monthly',
                'amount' => 199000,
                'currency' => 'VND',
                'interval_unit' => 'month',
                'interval_count' => 1,
                'trial' => ['amount' => 0, 'interval_unit' => 'day', 'interval_count' => 7],
            ],
            ['name' => 'Half-year', 'amount' => 1090000, 'currency' => 'VND', 'interval_unit' => 'month', 'interval_count' => 6],
            ['name' => 'Yearly', 'amount' => 1990000, 'currency' => 'VND', 'interval_unit' => 'year', 'interval_count' => 1],
        ],
    ]);
    $yearly = $plan['prices'][2];

    $session = $client->createCheckoutSession([
        'mode' => 'subscription',
        'price_id' => $yearly['id'],
        'customer_id' => $customer['id'],
        'external_ref' => 'user-42',
        'success_url' => 'https://yourapp.com/success',
        'cancel_url' => 'https://yourapp.com/cancel',
    ]);
    echo "Open {$session['checkout_url']} (activated: " . json_encode($session['activated'] ?? null)
        . ', trial: ' . json_encode($session['trial'] ?? null) . ')' . PHP_EOL;

    for ($i = 0; $i < 20; $i++) {
        try {
            $subscription = $client->getSubscription('user-42');
            echo "status={$subscription['status']} in_trial=" . json_encode($subscription['in_trial']) . PHP_EOL;
            if ($subscription['status'] === 'active') {
                break;
            }
        } catch (PayGateException $e) {
            if ($e->getErrorCode() !== 'not_found') {
                throw $e;
            }
        }
        sleep(3);
    }
} catch (PayGateException $e) {
    echo "PayGate error: {$e->getErrorCode()} — {$e->getMessage()}" . PHP_EOL;
    exit(1);
}
