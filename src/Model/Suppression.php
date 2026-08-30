<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Enum\MessageStream;
use Pulsenote\Enum\SuppressionReason;
use Pulsenote\Internal\Payload;

/**
 * An address this tenant will not send to, and why.
 */
final class Suppression
{
    public function __construct(
        public readonly string $id,
        /** Suppressed address, stored lower-cased and trimmed. */
        public readonly string $email,
        /** Suppression is per stream — see {@see MessageStream}. */
        public readonly MessageStream $stream,
        /** How the entry got here. */
        public readonly SuppressionReason $reason,
        public readonly \DateTimeImmutable $createdAt,
        /** Provider text for an automatic entry, or a note for a manual one. */
        public readonly ?string $detail = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var MessageStream $stream */
        $stream = Payload::enum($data, 'stream', MessageStream::class);
        /** @var SuppressionReason $reason */
        $reason = Payload::enum($data, 'reason', SuppressionReason::class);

        return new self(
            id: Payload::string($data, 'id'),
            email: Payload::string($data, 'email'),
            stream: $stream,
            reason: $reason,
            createdAt: Payload::dateTime($data, 'createdAt'),
            detail: Payload::stringOrNull($data, 'detail'),
        );
    }

    /**
     * Whether this entry came from the provider rather than from you.
     *
     * Removing one of these re-enables sending to an address that already
     * bounced or complained, which is usually a mistake — the bounce will
     * simply happen again and count against your reputation.
     */
    public function isAutomatic(): bool
    {
        return $this->reason !== SuppressionReason::Manual;
    }
}
