<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Internal\Payload;

/**
 * What happened to a test message.
 */
final class DestinationTestResult
{
    public function __construct(
        /** `sent`, `broken` (the destination will not work until fixed) or `failed`. */
        public readonly string $status,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: Payload::string($data, 'status'),
            reason: Payload::stringOrNull($data, 'reason'),
        );
    }

    public function sent(): bool
    {
        return $this->status === 'sent';
    }
}
