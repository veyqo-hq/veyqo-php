<?php

declare(strict_types=1);

namespace Veyqo;

/** How this environment issues cards, and its authorization endpoint. */
final class CardProgrammes
{
    public function __construct(private readonly Veyqo $client) {}

    public function all(): mixed
    {
        return $this->client->request('GET', '/card-programmes');
    }

    /** `$type` is veyqo or imported. Returns signing_secret the first time only. */
    public function setAuthorizationEndpoint(string $type, ?string $url): mixed
    {
        return $this->client->request('PUT', '/card-programmes/'.rawurlencode($type).'/authorization-endpoint', ['url' => $url]);
    }

    public function rotateSecret(string $type): mixed
    {
        return $this->client->request('POST', '/card-programmes/'.rawurlencode($type).'/authorization-endpoint/rotate-secret', []);
    }

    /** A signed sample request; reports what your endpoint answered and how fast. */
    public function testEndpoint(string $type): mixed
    {
        return $this->client->request('POST', '/card-programmes/'.rawurlencode($type).'/authorization-endpoint/test', []);
    }

    /** Adopt the cards already on your Sudo account. Run with $dryRun = true first. */
    public function importCards(bool $dryRun): mixed
    {
        return $this->client->request('POST', '/card-programmes/imported/import', ['dry_run' => $dryRun]);
    }
}
