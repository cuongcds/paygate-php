<?php

/**
 * Run: php example/poll-subscription.php <external_ref>
 * Requires PAYGATE_BASE_URL / PAYGATE_API_KEY / PAYGATE_API_SECRET env vars.
 */

require __DIR__ . '/../vendor/autoload.php';

use PayGate\Client;
use PayGate\Exceptions\PayGateException;

$externalRef = $argv[1] ?? null;
if ($externalRef === null) {
    fwrite(STDERR, "Usage: php poll-subscription.php <external_ref>" . PHP_EOL);
    exit(1);
}

$client = Client::withHmac(
    getenv('PAYGATE_BASE_URL') ?: 'https://payments.example.com',
    getenv('PAYGATE_API_KEY') ?: 'pgk_your_api_key',
    getenv('PAYGATE_API_SECRET') ?: 'your_api_secret'
);

try {
    $subscription = $client->getSubscription($externalRef);
    echo json_encode($subscription, JSON_PRETTY_PRINT) . PHP_EOL;
} catch (PayGateException $e) {
    if ($e->getErrorCode() === 'not_found') {
        echo "No subscription yet for '{$externalRef}'." . PHP_EOL;
        exit(0);
    }
    echo "PayGate error: {$e->getErrorCode()} — {$e->getMessage()}" . PHP_EOL;
    exit(1);
}
