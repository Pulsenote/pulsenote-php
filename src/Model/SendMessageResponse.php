<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Enum\DestinationType;
use Pulsenote\Internal\Payload;

/**
 * A message accepted for delivery to a destination.
 */
final class SendMessageResponse
{
    public function __construct(
        /** The notification id — the message shows up in the log under it. */
        public readonly string $id,
        /** `QUEUED`: delivery happens moments later, with retries. */
        public readonly string $status,
        public readonly DestinationType $channel,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var DestinationType $channel */
        $channel = Payload::enum($data, 'channel', DestinationType::class);

        return new self(
            id: Payload::string($data, 'id'),
            status: Payload::string($data, 'status'),
            channel: $channel,
        );
    }
}
