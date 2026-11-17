<?php

declare(strict_types=1);

namespace Veyqo\Tests;

use PHPUnit\Framework\TestCase;
use Veyqo\SignatureException;
use Veyqo\Veyqo;
use Veyqo\VeyqoException;
use Veyqo\Webhook;

final class VeyqoTest extends TestCase
{
    /** @param list<array{0: int, 1: array<string, mixed>}> $answers */
    private function client(array $answers, array &$seen): Veyqo
    {
        return new Veyqo('kc_test_abc', ['max_retries' => 1, 'transport' => function ($method, $url, $headers, $body) use (&$answers, &$seen) {
            $seen[] = compact('method', 'url', 'headers', 'body');
            [$status, $json] = array_shift($answers);

            return [$status, [], json_encode($json)];
        }]);
    }

    public function test_it_creates_with_an_idempotency_key_and_unwraps_data(): void
    {
        $seen = [];
        $payment = $this->client([[201, ['success' => true, 'data' => ['id' => 'pay_1']]]], $seen)
            ->payments->create(['amount' => 50000, 'currency' => 'NGN']);
        $this->assertSame('pay_1', $payment['id']);
        $this->assertSame('Bearer kc_test_abc', $seen[0]['headers']['Authorization']);
        $this->assertNotEmpty($seen[0]['headers']['Idempotency-Key']);
    }

    public function test_actions_and_retries_keep_the_same_key_and_errors_are_typed(): void
    {
        $seen = [];
        $client = $this->client([[503, []], [422, ['success' => false, 'error' => ['code' => 'VALIDATION_FAILED', 'message' => 'Nope']]]], $seen);
        try {
            $client->subscriptions->cancel('csub_1', ['at_period_end' => false]);
            $this->fail('Expected an exception.');
        } catch (VeyqoException $e) {
            $this->assertSame('VALIDATION_FAILED', $e->errorCode);
            $this->assertSame(422, $e->status);
        }
        $this->assertStringEndsWith('/subscriptions/csub_1/cancel', $seen[0]['url']);
        $this->assertSame($seen[0]['headers']['Idempotency-Key'], $seen[1]['headers']['Idempotency-Key']);
    }

    public function test_transfers_list_banks_resolve_and_send(): void
    {
        $seen = [];
        $client = $this->client([
            [200, ['success' => true, 'data' => [['id' => '000013', 'name' => 'GTBank', 'providers' => ['paystack']]]]],
            [200, ['success' => true, 'data' => ['account_name' => 'ADA LOVELACE']]],
            [201, ['success' => true, 'data' => ['id' => 'trf_1', 'status' => 'pending']]],
            [200, ['success' => true, 'data' => ['id' => 'csub_1']]],
        ], $seen);

        $this->assertSame('000013', $client->transfers->banks('paystack')[0]['id']);
        $this->assertStringEndsWith('/transfers/banks?provider=paystack', $seen[0]['url']);
        $this->assertSame('ADA LOVELACE', $client->transfers->resolve(['bank' => '000013', 'account_number' => '0123456789'])['account_name']);
        $this->assertSame('trf_1', $client->transfers->create(['amount' => 500000, 'bank' => '000013', 'account_number' => '0123456789'])['id']);
        $this->assertNotEmpty($seen[2]['headers']['Idempotency-Key']);
        $client->subscriptions->changePlan('csub_1', ['plan' => 'rplan_2', 'at_period_end' => false]);
        $this->assertStringEndsWith('/subscriptions/csub_1/plan', $seen[3]['url']);
    }

    public function test_a_payment_can_be_cancelled(): void
    {
        $seen = [];
        $payment = $this->client([[200, ['success' => true, 'data' => ['id' => 'pay_1', 'status' => 'cancelled']]]], $seen)->payments->cancel('pay_1');
        $this->assertSame('cancelled', $payment['status']);
        $this->assertSame('POST', $seen[0]['method']);
        $this->assertStringEndsWith('/payments/pay_1/cancel', $seen[0]['url']);
    }

    public function test_webhooks_verify_and_reject(): void
    {
        $body = json_encode(['id' => 'evt_1', 'type' => 'payment.succeeded']);
        $time = time();
        $sign = fn (string $secret) => hash_hmac('sha256', $time.'.'.$body, $secret);
        $headers = ['Veyqo-Timestamp' => (string) $time, 'Veyqo-Signature' => $sign('old').','.$sign('whsec_new')];

        $this->assertSame('evt_1', Webhook::verify($body, $headers, 'whsec_new')['id']);
        // $_SERVER-style keys work too.
        $this->assertSame('evt_1', Webhook::verify($body, ['HTTP_VEYQO_TIMESTAMP' => (string) $time, 'HTTP_VEYQO_SIGNATURE' => $sign('whsec_new')], 'whsec_new')['id']);
        $this->expectException(SignatureException::class);
        Webhook::verify($body, $headers, 'wrong');
    }

    public function test_balances_beneficiaries_batches_virtual_accounts_and_split_recipients(): void
    {
        $seen = [];
        $ok = fn (array $data, int $status = 200) => [$status, ['success' => true, 'data' => $data]];
        $client = $this->client([
            $ok([['provider' => 'paystack']]),
            $ok(['id' => 'ben_1'], 201),
            $ok(['id' => 'ben_1', 'deleted' => true]),
            $ok(['id' => 'tbat_1'], 201),
            $ok(['id' => 'tbat_1', 'status' => 'done']),
            $ok(['id' => 'va_1', 'account_number' => '9930000001'], 201),
            $ok(['id' => 'va_1']),
            $ok(['id' => 'spr_1'], 201),
            $ok(['id' => 'pay_1'], 201),
        ], $seen);

        $this->assertSame('paystack', $client->balances->all()[0]['provider']);
        $client->beneficiaries->create(['bank' => '000013', 'account_number' => '0123456789']);
        $this->assertTrue($client->beneficiaries->delete('ben_1')['deleted']);
        $client->transfers->createBatch(['reference' => 'PAYROLL', 'transfers' => [['amount' => 100000, 'bank' => '000013', 'account_number' => '0123456789']]]);
        $this->assertSame('done', $client->transfers->retrieveBatch('tbat_1')['status']);
        $this->assertSame('9930000001', $client->virtualAccounts->create(['customer' => ['email' => 'ada@example.com']])['account_number']);
        $client->virtualAccounts->retrieve('CUST 42');
        $client->splitRecipients->create(['name' => 'Ada Stores', 'bank' => '000013', 'account_number' => '0123456789']);
        $client->payments->create(['amount' => 1000000, 'currency' => 'NGN', 'split' => [['recipient' => 'spr_1', 'amount' => 250000]]]);

        $calls = array_map(fn (array $c): string => $c['method'].' '.parse_url($c['url'], PHP_URL_PATH), $seen);
        $this->assertSame([
            'GET /api/v1/balances',
            'POST /api/v1/beneficiaries',
            'DELETE /api/v1/beneficiaries/ben_1',
            'POST /api/v1/transfers/batches',
            'GET /api/v1/transfers/batches/tbat_1',
            'POST /api/v1/virtual-accounts',
            'GET /api/v1/virtual-accounts/CUST%2042',
            'POST /api/v1/split-recipients',
            'POST /api/v1/payments',
        ], $calls);
        $this->assertArrayNotHasKey('Idempotency-Key', $seen[2]['headers']);
        $this->assertSame([['recipient' => 'spr_1', 'amount' => 250000]], json_decode((string) $seen[8]['body'], true)['split']);
    }
}
