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
        'mode' => 'subscription',
        'price_id' => 'price_abc123', // from createPlan()/listPlans()
        'customer_id' => 'cus_abc123', // from createCustomer()
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
    // [
    //   'plan_ref' => 'premium', 'plan_id' => 'plan_…', 'price_id' => 'price_…', 'customer_id' => 'cus_…',
    //   'status' => 'active', 'current_period_end' => '2026-04-15 00:00:00',
    //   'in_trial' => false, 'trial_ends_at' => null,
    // ]
} catch (PayGateException $e) {
    if ($e->getErrorCode() === 'not_found') {
        // user has never checked out — not necessarily an error in your flow
    }
}
```

## Customers

A subscription checkout needs a customer. Writes use HMAC auth.

```php
$customer = $client->createCustomer([
    'name' => 'Nguyen Van A',
    'email' => 'a@example.com',
    'cc_emails' => ['billing@example.com'],
    'merchant_customer_id' => 'user-42', // your own id, optional
    'locale' => 'vi', // 'en' | 'vi'
]);

$client->getCustomer($customer['id']);
$client->updateCustomer($customer['id'], ['locale' => 'en']); // partial update
```

## Plans and prices

A plan holds many prices on sale at once (e.g. monthly, half-year, yearly). Plan writes are HMAC only.

```php
$plan = $client->createPlan([
    'code' => 'premium',
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

$active = $client->listPlans(['status' => 'active']); // or listPlans() for all
$client->getPlan($plan['id']);
$client->updatePlan($plan['id'], ['description' => 'All features']);
$client->addPlanPrice($plan['id'], ['amount' => 99000, 'currency' => 'VND', 'interval_unit' => 'week', 'interval_count' => 1]);
$client->updatePlanPrice($plan['id'], $plan['prices'][0]['id'], ['status' => 'archived']);
```

## Subscription checkout with a price

For `mode: 'subscription'`, send `price_id` + `customer_id` (optionally `skip_trial`, `payment_method`, `success_url`, `cancel_url`, `external_ref`). Amount, currency and interval come from the price — do **not** send `plan_ref`, `amount`, `currency`, `interval`, `interval_count` or `plan_id`. The response is `['checkout_url' => ..., 'activated' => ..., 'trial' => ...]`.

```php
$session = $client->createCheckoutSession([
    'mode' => 'subscription',
    'price_id' => $plan['prices'][2]['id'],
    'customer_id' => $customer['id'],
    'external_ref' => 'user-42',
    'skip_trial' => false,
]);
// $session['activated'] — true when no payment was needed; $session['trial'] — the applied trial or null
```

One-time payments (`mode: 'payment'`) keep `plan_ref`, `amount`, `currency`, `success_url` and `cancel_url`.

## Changing a subscription's customer or price

```php
$client->setSubscriptionCustomer('user-42', 'cus_abc123');
$client->setSubscriptionPrice('user-42', 'price_xyz789'); // HMAC only, effective from the next renewal
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

`createCheckoutSession()` never accepts a `test_card_code` and never resolves a payment result itself — it only ever returns a `checkout_url`. To test without a real Stripe account, call it on an app whose environment allows the Test Payment Method (`test`/`staging`), with or without `payment_method: 'test'`; open the returned `checkout_url` (PayGate's own hosted picker page) and choose **Test Payment Method** there with one of the documented card codes — see [Testing without a real Stripe account](https://github.com/cuongcds/paygate-docs/blob/main/documents/03.04-testing.md).

```php
$session = $client->createCheckoutSession([
    'external_ref' => 'user-42',
    'plan_ref' => 'premium_1m',
    'amount' => 100000,
    'currency' => 'VND',
    'mode' => 'payment',
    'success_url' => 'https://yourapp.com/success',
    'cancel_url' => 'https://yourapp.com/cancel',
    'payment_method' => 'test',
]);
// Open $session['checkout_url'] in a browser to pick Test Payment Method there.
```

## Requirements

- PHP >= 7.4
- `ext-curl`, `ext-json`

## More examples

See [`example/`](example/) for runnable scripts, including [`subscription-flow.php`](example/subscription-flow.php) (customer, plan with 3 prices, checkout, poll) and [`verify-transaction.php`](example/verify-transaction.php) for the redirect-verification flow above.
