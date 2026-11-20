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

### Saved accounts, batches and balances

```php
$balances = $veyqo->balances->all();

// Save an account once (name-checked), then pay it by id
$ada = $veyqo->beneficiaries->create(['bank' => '000013', 'account_number' => '0123456789', 'label' => 'Ada, supplier']);
$veyqo->transfers->create(['amount' => 250000, 'beneficiary' => $ada['id'], 'reference' => 'INV-88']);

// Payroll: up to 200 transfers, sent in the background
$batch = $veyqo->transfers->createBatch([
    'reference' => 'PAYROLL-2026-11',
    'transfers' => [
        ['amount' => 450000, 'bank' => '000013', 'account_number' => '0123456789', 'narration' => 'November salary'],
        ['amount' => 380000, 'bank' => '000014', 'account_number' => '9876543210', 'narration' => 'November salary'],
    ],
]);
// later: $veyqo->transfers->retrieveBatch($batch['id'])['progress']
```

A transfer at or above your organization's approval limit comes back as `awaiting_approval`. It is sent once an owner or admin approves it in the dashboard, or rejected after 7 days.

### Virtual accounts

```php
$account = $veyqo->virtualAccounts->create([
    'customer' => ['email' => 'ada@example.com', 'name' => 'Ada Obi'], // Flutterwave also needs bvn
    'reference' => 'CUSTOMER-42',
    'metadata' => ['customer_id' => '42'],
]);
// Show $account['account_number'] and $account['bank_name'] to the customer.
// Each confirmed deposit arrives as payment.succeeded with metadata.virtual_account_id.
```


### Cards

Virtual cards on Sudo, decided in real time. On your own Sudo programme every
payment is asked of your authorization endpoint: hold the amount (never just
read a balance) and answer within about 1.5 seconds.

```php
$holder = $veyqo->cardholders->create([
    'first_name' => 'Ada', 'last_name' => 'Obi',
    'identity' => ['type' => 'BVN', 'number' => '22222222222'], // passed to Sudo, never stored by Veyqo
    'billing_address' => ['line1' => '1 Marina', 'city' => 'Lagos', 'state' => 'Lagos', 'postal_code' => '100001', 'country' => 'NG'],
]);
$card = $veyqo->cards->create(['cardholder' => $holder['id'], 'brand' => 'Verve', 'currency' => 'NGN']);
$veyqo->cards->update($card['id'], ['status' => 'inactive']); // freeze at once
```

```php
use Veyqo\CardAuthorization;

$request = CardAuthorization::verify(file_get_contents('php://input'), getallheaders(), getenv('VEYQO_CARD_SECRET'));
$hold = $wallets->hold($request['data']['card']['metadata']['user_id'], $request['data']['authorization']['amount'], $request['id']);
header('Content-Type: application/json');
echo json_encode($hold ? CardAuthorization::response(true, $hold->id) : CardAuthorization::response(false, null, 'insufficient_funds'));
// Release the hold on card.authorization.declined; settle it on card.transaction.created.
```

### Split payments

```php
$seller = $veyqo->splitRecipients->create(['name' => 'Ada Obi Stores', 'bank' => '000013', 'account_number' => '0123456789', 'reference' => 'SELLER-7']);
$payment = $veyqo->payments->create([
    'amount' => 1000000,
    'currency' => 'NGN',
    'customer' => ['email' => 'buyer@example.com'],
    'split' => [['recipient' => $seller['id'], 'amount' => 850000]], // the seller gets 8,500; the rest is yours
]);
```

### Itemised checkout with tax

```php
$payment = $veyqo->payments->create([
    'amount' => 38400, // line items + shipping, in cents
    'currency' => 'CAD',
    'provider' => 'stripe',
    'reference' => 'ORDER-1001',
    'customer' => ['email' => 'buyer@example.ca', 'name' => 'Ada Buyer'],
    'line_items' => [
        ['name' => 'Sole Pod', 'unit_amount' => 19900],
        ['name' => 'Re-Up', 'unit_amount' => 15000, 'quantity' => 1],
    ],
    'shipping' => [
        'name' => 'Ada Buyer',
        'amount' => 3500, // charged and taxed as shipping
        'label' => 'Standard shipping',
        'address' => ['line1' => '1 King St W', 'city' => 'Toronto', 'state' => 'ON', 'postal_code' => 'M5H 1A1', 'country' => 'CA'],
    ],
    'tax' => ['automatic' => true], // Stripe adds GST/HST/sales tax at checkout
    'callback_url' => 'https://shop.example.com/thanks',
]);
// once paid: $payment['amount'] is the total, $payment['amount_tax'] the tax,
// $payment['risk'] Stripe Radar's assessment ('review' => true means look before shipping)

$veyqo->payments->cancel($payment['id']); // order cancelled: close the checkout
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
