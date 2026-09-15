<?php

/**
 * Run: php example/verify-transaction.php <transaction_id> <expected_external_ref>
 * Requires PAYGATE_BASE_URL / PAYGATE_API_KEY / PAYGATE_API_SECRET env vars.
 *
 * Simulates what your success_url handler should do with the
 * paygate_transaction_id/paygate_external_ref query params PayGate appends
 * to the redirect — never trust the redirect itself as proof of payment.
 */

require __DIR__ . '/../vendor/autoload.php';

use PayGate\Client;
use PayGate\Exceptions\PayGateException;

$transactionId = $argv[1] ?? null;
$expectedExternalRef = $argv[2] ?? null;
if ($transactionId === null || $expectedExternalRef === null) {
    fwrite(STDERR, "Usage: php verify-transaction.php <transaction_id> <expected_external_ref>" . PHP_EOL);
    exit(1);
}

$client = Client::withHmac(
    getenv('PAYGATE_BASE_URL') ?: 'https://payments.example.com',
    getenv('PAYGATE_API_KEY') ?: 'pgk_your_api_key',
    getenv('PAYGATE_API_SECRET') ?: 'your_api_secret'
);

try {
    $transaction = $client->getTransaction($transactionId);
} catch (PayGateException $e) {
    if ($e->getErrorCode() === 'not_found') {
        echo "No transaction found for id '{$transactionId}' — do not unlock anything." . PHP_EOL;
        exit(1);
    }
    echo "PayGate error: {$e->getErrorCode()} — {$e->getMessage()}" . PHP_EOL;
    exit(1);
}

if ($transaction['external_ref'] !== $expectedExternalRef) {
    echo "Mismatch: transaction belongs to '{$transaction['external_ref']}', not '{$expectedExternalRef}' — do not unlock anything." . PHP_EOL;
    exit(1);
}

if ($transaction['status'] !== 'completed') {
    echo "Transaction status is '{$transaction['status']}', not completed — do not unlock anything." . PHP_EOL;
    exit(0);
}

echo "Verified: transaction {$transaction['transaction_id']} for '{$transaction['external_ref']}' is completed." . PHP_EOL;
echo json_encode($transaction, JSON_PRETTY_PRINT) . PHP_EOL;
