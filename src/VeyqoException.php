<?php

declare(strict_types=1);

namespace Veyqo;

/** An error the API returned, with its documented code (docs/API.md). */
class VeyqoException extends \RuntimeException
{
    /** @param array<string, mixed>|null $details */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly ?array $details = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $status);
    }
}
