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
}
