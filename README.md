# Veyqo for PHP

The official PHP library for the [Veyqo](https://veyqo.org) payments API.
PHP 8.1+, no dependencies beyond ext-curl and ext-json.

```bash
composer require veyqo/veyqo-php
```

```php
$veyqo = new Veyqo\Veyqo(getenv('VEYQO_SECRET_KEY'));

$payment = $veyqo->payments->create([
    'amount' => 500000,            // ₦5,000.00 in kobo
    'currency' => 'NGN',
    'reference' => 'ORDER-1001',
    'customer' => ['email' => 'customer@example.com'],
    'callback_url' => 'https://shop.example.com/thanks',
]);
header('Location: '.$payment['checkout_url']);

// After the redirect, confirm server-side: never trust the browser.
$payment = $veyqo->payments->verify($payment['id']);

// Recurring billing
$plan = $veyqo->plans->create(['name' => 'Pro', 'amount' => 250000, 'currency' => 'NGN', 'interval' => 'month']);
$subscription = $veyqo->subscriptions->create(['plan' => $plan['id'], 'customer' => ['email' => 'customer@example.com']]);
$veyqo->subscriptions->cancel($subscription['id'], ['at_period_end' => true]);

// Bank transfers from your provider balance
$account = $veyqo->transfers->resolve(['bank' => '000013', 'account_number' => '0123456789']);
$transfer = $veyqo->transfers->create([
    'amount' => 500000,
    'bank' => '000013', // GTBank's NIBSS code: works on every provider
    'account_number' => '0123456789',
    'account_name' => $account['account_name'], // refused if the bank's name differs
    'reference' => 'PAYOUT-1001',
]);
// $transfer['status']: successful, failed or pending (then transfer.successful / transfer.failed webhooks)
```

## Webhooks

```php
try {
    $event = Veyqo\Webhook::verify(file_get_contents('php://input'), getallheaders(), getenv('VEYQO_WEBHOOK_SECRET'));
} catch (Veyqo\SignatureException $e) {
    http_response_code(400);
    exit;
}
if ($event['type'] === 'payment.succeeded') {
    // fulfil $event['data']['reference'], once (dedupe on $event['id'])
}
http_response_code(200);
```

Errors throw `Veyqo\VeyqoException` with `errorCode`, `status`, `details` and
`requestId`. Reads and POSTs (which carry an `Idempotency-Key`) are retried on
network errors, 429 and 5xx.
