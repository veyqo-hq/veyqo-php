<?php

declare(strict_types=1);

namespace Veyqo;

final class Webhook
{
    /**
     * Checks a webhook from Veyqo and returns the event.
     *
     * Pass the raw body (file_get_contents('php://input'), before decoding),
     * the request headers, and the endpoint's signing secret.
     *
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, mixed>
     */
    public static function verify(string $rawBody, array $headers, string $secret, int $tolerance = 300, ?int $now = null): array
    {
        $header = static function (string $name) use ($headers): ?string {
            foreach ($headers as $key => $value) {
                if (strtolower((string) $key) === strtolower($name) || strtolower((string) $key) === 'http_'.str_replace('-', '_', strtolower($name))) {
                    return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
                }
            }

            return null;
        };

        $timestamp = $header('Veyqo-Timestamp');
        $signatures = array_filter(array_map('trim', explode(',', (string) $header('Veyqo-Signature'))));
        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            throw new SignatureException('Missing Veyqo-Timestamp or Veyqo-Signature header.');
        }
        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            throw new SignatureException('Webhook timestamp is outside the tolerance; possible replay.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        foreach ($signatures as $signature) {
            // Several during a secret rotation: any one matching is enough.
            if (hash_equals($expected, $signature)) {
                return json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        throw new SignatureException('Webhook signature does not match. Check the signing secret.');
    }
}
