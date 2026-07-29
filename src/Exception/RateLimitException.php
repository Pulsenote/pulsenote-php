<?php

declare(strict_types=1);

namespace Pulsenote\Exception;

/**
 * 429 Too Many Requests — the tenant's send/API quota was exceeded.
 *
 * The SDK does not retry for you. Back off for {@see $retryAfter} seconds (when the
 * API supplied a `Retry-After` header) and try again.
 */
final class RateLimitException extends ApiException
{
    /**
     * @param array<string,mixed>|null $body
     * @param int|null                 $retryAfter Seconds to wait, from the `Retry-After` header.
     */
    public function __construct(
        string $message,
        int $status,
        ?array $body = null,
        ?string $rawBody = null,
        ?string $requestId = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status, $body, $rawBody, $requestId);
    }
}
