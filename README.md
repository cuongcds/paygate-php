# PayGate PHP SDK

PHP client for [PayGate](https://github.com/cuongcds/paygate-docs), a multi-tenant payment gateway. See that repo for the full, language-agnostic API reference this SDK wraps.

## Install

```bash
composer require cuongcds/paygate-php
```

## Usage — HMAC (server-to-server)

Use this when your own backend calls PayGate — never embed `api_secret` in a mobile app or browser bundle.

```php
use PayGate\Client;
use PayGate\Exceptions\PayGateException;

$client = Client::withHmac(
    'https://payments.example.com',
    'pgk_your_api_key',
    'your_api_secret'
);

try {
    $result = $client->createCheckoutSession([
        'external_ref' => 'user-42',
        'plan_ref' => 'premium_1m',
        'amount' => 199000,
        'currency' => 'VND',
        'mode' => 'subscription',
        'interval' => 'month',
        'interval_count' => 1,
        'success_url' => 'https://yourapp.com/success',
        'cancel_url' => 'https://yourapp.com/cancel',
    ]);

    header('Location: ' . $result['checkout_url']);
} catch (PayGateException $e) {
    // $e->getErrorCode() is one of the codes in
    // https://github.com/cuongcds/paygate-docs/blob/main/documents/05-errors.md
    echo "Could not start checkout: {$e->getErrorCode()} — {$e->getMessage()}";
}
```

## Usage — Firebase ID Token (client calls PayGate directly)

Use this when your client already authenticates end users with Firebase Auth. `external_ref` is derived from the token automatically — never pass it.

```php
use PayGate\Client;

$client = Client::withFirebaseIdToken(
    'https://payments.example.com',
    $firebaseIdToken // from your client, e.g. a mobile app's Authorization header
);

$subscription = $client->getSubscription($currentUserUid);
```

## Checking subscription status

```php
use PayGate\Client;
use PayGate\Exceptions\PayGateException;

try {
    $subscription = $client->getSubscription('user-42');
    // ['plan_ref' => 'premium_1m', 'status' => 'active', 'current_period_end' => '2026-04-15 00:00:00']
} catch (PayGateException $e) {
    if ($e->getErrorCode() === 'not_found') {
        // user has never checked out — not necessarily an error in your flow
    }
}
```

## Verifying a checkout redirect

`success_url`/`cancel_url` come back with `paygate_transaction_id`/`paygate_external_ref`/`paygate_status` appended — but that redirect alone is never proof of payment (it's a client-side navigation, not a signed confirmation). Verify server-side before unlocking anything:

```php
use PayGate\Client;
use PayGate\Exceptions\PayGateException;

// From your success_url handler: $_GET['paygate_transaction_id'], $_GET['paygate_external_ref']
try {
    $transaction = $client->getTransaction($_GET['paygate_transaction_id']);

    if ($transaction['external_ref'] !== $_GET['paygate_external_ref']) {
        throw new RuntimeException('Transaction does not belong to the expected user.');
    }
    if ($transaction['status'] !== 'completed') {
        // 'pending'/'failed'/'canceled' — do not unlock anything yet
    }
} catch (PayGateException $e) {
    // 'not_found' — id doesn't exist, or belongs to a different app; treat as unverified
}
```

This also covers one-time payments (`mode: 'payment'`), which never create a subscription record — `getSubscription()` alone can't verify those.

## Error handling

Every non-2xx PayGate response throws `PayGate\Exceptions\PayGateException`:

```php
try {
    $client->createCheckoutSession([...]);
} catch (PayGateException $e) {
    $e->getErrorCode();   // e.g. "invalid_card_details"
    $e->getMessage();     // human-readable message from PayGate
    $e->getHttpStatus();  // e.g. 400
}
```

A request that never reached PayGate at all (DNS, timeout, connection refused) throws `PayGate\Exceptions\TransportException` instead — treat this as a network problem, not a PayGate-side rejection.

## Testing your integration

Pass `payment_method: 'test'` with one of the documented test card codes — see [Testing without a real Stripe account](https://github.com/cuongcds/paygate-docs/blob/main/documents/03.04-testing.md). No real Stripe account or network call needed; resolves synchronously.

```php
$client->createCheckoutSession([
    'external_ref' => 'user-42',
    'plan_ref' => 'premium_1m',
    'amount' => 100000,
    'currency' => 'VND',
    'mode' => 'payment',
    'success_url' => 'https://yourapp.com/success',
    'cancel_url' => 'https://yourapp.com/cancel',
    'payment_method' => 'test',
    'test_card_code' => '4242424242424242',
]);
```

## Requirements

- PHP >= 7.4
- `ext-curl`, `ext-json`

## More examples

See [`example/`](example/) for runnable scripts, including [`verify-transaction.php`](example/verify-transaction.php) for the redirect-verification flow above.
