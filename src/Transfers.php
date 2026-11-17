<?php

declare(strict_types=1);

namespace Veyqo;

/**
 * Bank transfers from your own provider balance (veyqo.org/docs/transfers).
 *
 * Banks are named by their NIBSS code ("000013" is GTBank), the same on
 * every provider; Veyqo translates it to the provider's own code.
 */
final class Transfers
{
    public function __construct(private readonly Veyqo $client) {}

    /**
     * Every bank and which of your providers reach it; with a provider,
     * exactly that provider's banks and codes.
     */
    public function banks(?string $provider = null): mixed
    {
        return $this->client->request('GET', '/transfers/banks', null, $provider === null ? [] : ['provider' => $provider]);
    }

    /**
     * Whose account it is, before you send.
     *
     * @param  array{bank?: string, bank_code?: string, account_number: string, provider?: string}  $params
     */
    public function resolve(array $params): mixed
    {
        return $this->client->request('POST', '/transfers/resolve', $params);
    }

    /**
     * Checked by name enquiry, then sent once. Pass account_name to refuse it
     * when the bank's name for the account differs.
     *
     * @param  array<string, mixed>  $params
     */
    public function create(array $params, ?string $idempotencyKey = null): mixed
    {
        return $this->client->request('POST', '/transfers', $params, [], $idempotencyKey);
    }

    /** @param array<string, mixed> $query */
    public function all(array $query = []): mixed
    {
        return $this->client->request('GET', '/transfers', null, $query);
    }

    /** By id (trf_…) or your reference. A pending one is checked with the provider first. */
    public function retrieve(string $id): mixed
    {
        return $this->client->request('GET', '/transfers/'.rawurlencode($id));
    }

    /**
     * Up to 200 transfers sent one by one in the background (payroll, vendor
     * payouts). The batch reference is required: the same one twice is refused.
     *
     * @param  array{reference: string, provider?: string, transfers: list<array<string, mixed>>}  $params
     */
    public function createBatch(array $params, ?string $idempotencyKey = null): mixed
    {
        return $this->client->request('POST', '/transfers/batches', $params, [], $idempotencyKey);
    }

    /** Its status (queued, sending, done), counts, and each transfer's outcome. */
    public function retrieveBatch(string $id): mixed
    {
        return $this->client->request('GET', '/transfers/batches/'.rawurlencode($id));
    }
}
