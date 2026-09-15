<?php

/**
 * Run: php example/hmac-checkout.php
 * Requires PAYGATE_BASE_URL / PAYGATE_API_KEY / PAYGATE_API_SECRET env vars.
 */

require __DIR__ . '/../vendor/autoload.php';

use PayGate\Client;
use PayGate\Exceptions\PayGateException;
use PayGate\Exceptions\TransportException;

$client = Client::withHmac(
    getenv('PAYGATE_BASE_URL') ?: 'https://payments.example.com',
    getenv('PAYGATE_API_KEY') ?: 'pgk_your_api_key',
    getenv('PAYGATE_API_SECRET') ?: 'your_api_secret'
);

try {
    $result = $client->createCheckoutSession([
        'external_ref' => 'example-user-1',
        'plan_ref' => 'premium_1m',
        'amount' => 199000,
        'currency' => 'VND',
        'mode' => 'subscription',
        'interval' => 'month',
        'interval_count' => 1,
        'success_url' => 'https://yourapp.com/success',
        'cancel_url' => 'https://yourapp.com/cancel',
    ]);

    echo "Redirect the user to: {$result['checkout_url']}" . PHP_EOL;
} catch (PayGateException $e) {
    echo "PayGate rejected the request: {$e->getErrorCode()} — {$e->getMessage()}" . PHP_EOL;
} catch (TransportException $e) {
    echo "Could not reach PayGate: {$e->getMessage()}" . PHP_EOL;
}
