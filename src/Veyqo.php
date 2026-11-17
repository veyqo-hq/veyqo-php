<?php

declare(strict_types=1);

namespace Veyqo;

/**
 * The Veyqo API client.
 *
 *     $veyqo = new Veyqo\Veyqo(getenv('VEYQO_SECRET_KEY'));
 *     $payment = $veyqo->payments->create(['amount' => 500000, 'currency' => 'NGN', 'customer' => ['email' => 'a@b.co']]);
 */
final class Veyqo
{
    public const VERSION = '0.3.0';

    public readonly Resource $payments;

    public readonly Resource $refunds;

    public readonly Resource $checkoutSessions;

    public readonly Resource $plans;

    public readonly Resource $subscriptions;

    public readonly Resource $events;

    public readonly Transfers $transfers;

    private string $baseUrl;

    /** @var callable(string, string, array<string, string>, ?string): array{0: int, 1: array<string, string>, 2: string} */
    private $transport;

    /**
     * @param  array{base_url?: string, timeout?: int, max_retries?: int, api_version?: string, transport?: callable}  $options
     */
    public function __construct(private readonly string $secretKey, private readonly array $options = [])
    {
        if ($secretKey === '') {
            throw new \InvalidArgumentException('Pass your Veyqo secret key: new Veyqo(getenv("VEYQO_SECRET_KEY"))');
        }
        $this->baseUrl = rtrim($options['base_url'] ?? 'https://api.veyqo.org/api/v1', '/');
        $this->transport = $options['transport'] ?? $this->curl(...);

        $this->payments = new Resource($this, '/payments', ['refund' => ['POST', '/{id}/refunds'], 'verify' => ['POST', '/{id}/verify'], 'cancel' => ['POST', '/{id}/cancel']]);
        $this->refunds = new Resource($this, '/refunds');
        $this->checkoutSessions = new Resource($this, '/checkout/sessions');
        $this->plans = new Resource($this, '/plans');
        $this->subscriptions = new Resource($this, '/subscriptions', ['cancel' => ['POST', '/{id}/cancel'], 'changePlan' => ['POST', '/{id}/plan']]);
        $this->events = new Resource($this, '/events');
        $this->transfers = new Transfers($this);
    }

    /**
     * Any endpoint. Returns `data`, or ['data' => …, 'meta' => …] for lists.
     * POSTs carry an Idempotency-Key, so the built-in retries are safe.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], ?string $idempotencyKey = null): mixed
    {
        $url = $this->baseUrl.$path.($query === [] ? '' : '?'.http_build_query(array_filter($query, fn ($v) => $v !== null)));
        $headers = [
            'Authorization' => 'Bearer '.$this->secretKey,
            'Accept' => 'application/json',
            'User-Agent' => 'veyqo-php/'.self::VERSION,
        ];
        if (isset($this->options['api_version'])) {
            $headers['Veyqo-Version'] = $this->options['api_version'];
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($method === 'POST') {
            $headers['Idempotency-Key'] = $idempotencyKey ?? bin2hex(random_bytes(16));
        }
        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR);
        $retries = (int) ($this->options['max_retries'] ?? 2);

        for ($attempt = 0; ; $attempt++) {
            try {
                [$status, $responseHeaders, $raw] = ($this->transport)($method, $url, $headers, $payload);
            } catch (\RuntimeException $e) {
                if ($attempt < $retries) {
                    usleep(500_000 * 2 ** $attempt);

                    continue;
                }
                throw new VeyqoException('Could not reach Veyqo: '.$e->getMessage(), 'NETWORK_ERROR', 0);
            }
            if (($status === 429 || $status >= 500) && $attempt < $retries) {
                usleep(500_000 * 2 ** $attempt);

                continue;
            }
            $decoded = json_decode($raw, true);
            if ($status >= 400 || ($decoded['success'] ?? true) === false) {
                $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
                throw new VeyqoException(
                    (string) ($error['message'] ?? "Veyqo answered {$status}"),
                    (string) ($error['code'] ?? 'INTERNAL_ERROR'),
                    $status,
                    is_array($error['details'] ?? null) ? $error['details'] : null,
                    $responseHeaders['x-request-id'] ?? null,
                );
            }

            return isset($decoded['meta']) ? ['data' => $decoded['data'], 'meta' => $decoded['meta']] : ($decoded['data'] ?? null);
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $handle = curl_init($url);
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($this->options['timeout'] ?? 30),
            CURLOPT_HTTPHEADER => array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($key))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($handle);
        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new \RuntimeException($error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [$status, $responseHeaders, (string) $raw];
    }
}
