<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Enum\DestinationType;
use Pulsenote\Internal\Payload;

/**
 * A registered Slack, Teams, Discord, Google Chat or webhook destination.
 */
final class Destination
{
    public function __construct(
        public readonly string $id,
        public readonly DestinationType $type,
        /** What messages are sent to: `$pulsenote->messages->send(to: $name, …)`. */
        public readonly string $name,
        /** Where it delivers, with everything secret left out. */
        public readonly string $displayHint,
        public readonly \DateTimeImmutable $createdAt,
        /** Set when the platform said the destination will not accept messages any more. */
        public readonly ?\DateTimeImmutable $brokenAt = null,
        public readonly ?string $brokenReason = null,
        public readonly ?\DateTimeImmutable $lastDeliveredAt = null,
        /**
         * Webhook destinations only, and only in the result of
         * {@see \Pulsenote\Resource\Destinations::create()}: the key every request
         * to your endpoint is signed with (`X-Pulsenote-Signature: sha256=…`).
         * It is not shown again.
         */
        public readonly ?string $signingSecret = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var DestinationType $type */
        $type = Payload::enum($data, 'type', DestinationType::class);

        return new self(
            id: Payload::string($data, 'id'),
            type: $type,
            name: Payload::string($data, 'name'),
            displayHint: Payload::string($data, 'displayHint'),
            createdAt: Payload::dateTime($data, 'createdAt'),
            brokenAt: Payload::dateTimeOrNull($data, 'brokenAt'),
            brokenReason: Payload::stringOrNull($data, 'brokenReason'),
            lastDeliveredAt: Payload::dateTimeOrNull($data, 'lastDeliveredAt'),
            signingSecret: Payload::stringOrNull($data, 'signingSecret'),
        );
    }

    /** Whether messages sent to it will currently be refused. */
    public function isBroken(): bool
    {
        return $this->brokenAt !== null;
    }
}
