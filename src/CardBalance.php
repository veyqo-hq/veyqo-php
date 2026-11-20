<?php

declare(strict_types=1);

namespace Veyqo;

/** The prepaid card balance on Veyqo's card programme (veyqo.org/docs/api-card_balance-show). */
final class CardBalance
{
    public function __construct(private readonly Veyqo $client) {}

    /** Each currency's balance (available, posted, held) and your fees. */
    public function retrieve(): mixed
    {
        return $this->client->request('GET', '/card-balance');
    }

    /** @param array<string, mixed> $query */
    public function entries(array $query = []): mixed
    {
        return $this->client->request('GET', '/card-balance/entries', null, $query);
    }

    /**
     * Live: returns a checkout_url to pay; credited once that payment
     * succeeds. Sandbox: test money, credited at once.
     *
     * @param  array{currency: string, amount: int, email: string}  $params
     */
    public function topUp(array $params, ?string $idempotencyKey = null): mixed
    {
        return $this->client->request('POST', '/card-balance/top-ups', $params, [], $idempotencyKey);
    }

    public function setLowThreshold(string $currency, ?int $lowThreshold): mixed
    {
        return $this->client->request('PUT', '/card-balance/low-threshold', ['currency' => $currency, 'low_threshold' => $lowThreshold]);
    }

    /**
     * Set aside now; paid to the account once Veyqo approves.
     *
     * @param  array{currency: string, amount: int, bank_code: string, bank_name?: string, account_number: string, account_name: string}  $params
     */
    public function withdraw(array $params, ?string $idempotencyKey = null): mixed
    {
        return $this->client->request('POST', '/card-balance/withdrawals', $params, [], $idempotencyKey);
    }

    /** @param array<string, mixed> $query */
    public function withdrawals(array $query = []): mixed
    {
        return $this->client->request('GET', '/card-balance/withdrawals', null, $query);
    }
}
