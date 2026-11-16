<?php

declare(strict_types=1);

namespace Veyqo;

/**
 * create / all / retrieve / update for one collection, plus its actions
 * (refund, verify, cancel).
 */
final class Resource
{
    /** @param array<string, array{0: string, 1: string}> $actions */
    public function __construct(private readonly Veyqo $client, private readonly string $path, private readonly array $actions = []) {}

    /** @param array<string, mixed> $params */
    public function create(array $params, ?string $idempotencyKey = null): mixed
    {
        return $this->client->request('POST', $this->path, $params, [], $idempotencyKey);
    }

    /** @param array<string, mixed> $query */
    public function all(array $query = []): mixed
    {
        return $this->client->request('GET', $this->path, null, $query);
    }

    public function retrieve(string $id): mixed
    {
        return $this->client->request('GET', $this->path.'/'.rawurlencode($id));
    }

    /** @param array<string, mixed> $params */
    public function update(string $id, array $params): mixed
    {
        return $this->client->request('PATCH', $this->path.'/'.rawurlencode($id), $params);
    }

    /** @param array{0?: string, 1?: array<string, mixed>} $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        if (! isset($this->actions[$name])) {
            throw new \BadMethodCallException("No {$name}() on {$this->path}.");
        }
        [$method, $suffix] = $this->actions[$name];
        $id = (string) ($arguments[0] ?? '');

        return $this->client->request($method, $this->path.str_replace('{id}', rawurlencode($id), $suffix), $arguments[1] ?? ($method === 'POST' ? [] : null));
    }
}
