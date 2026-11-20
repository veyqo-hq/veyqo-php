<?php

declare(strict_types=1);

namespace Veyqo;

/**
 * Your card authorization endpoint: Veyqo asks it before approving each card
 * payment. Hold the amount in your own ledger (never just read a balance:
 * two payments at once would both pass), then answer within about 1.5
 * seconds with HTTP 200 and CardAuthorization::response(...).
 */
final class CardAuthorization
{
    /**
     * Checks the request's signature (exactly like a webhook, with the
     * endpoint's secret) and returns it.
     *
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, mixed>
     *
     * @throws SignatureException
     */
    public static function verify(string $rawBody, array $headers, string $secret, int $tolerance = 300, ?int $now = null): array
    {
        $request = Webhook::verify($rawBody, $headers, $secret, $tolerance, $now);
        if (($request['type'] ?? null) !== 'card.authorization.request') {
            throw new SignatureException('Not a card authorization request.');
        }

        return $request;
    }

    /** @return array{approved: bool, reference?: string, decline_reason?: string} */
    public static function response(bool $approved, ?string $reference = null, ?string $declineReason = null): array
    {
        $body = ['approved' => $approved];
        if ($reference !== null && $reference !== '') {
            $body['reference'] = $reference;
        }
        if (! $approved && $declineReason !== null && $declineReason !== '') {
            $body['decline_reason'] = $declineReason;
        }

        return $body;
    }
}
