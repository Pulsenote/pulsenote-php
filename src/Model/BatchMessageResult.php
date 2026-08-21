<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Enum\BatchMessageStatus;
use Pulsenote\Internal\Payload;

/**
 * What happened to one message in a batch.
 *
 * {@see $index} is the position in the array you passed to
 * {@see \Pulsenote\Resource\Notifications::sendBatch()}, so a rejection can be traced
 * back to the message that caused it.
 */
final class BatchMessageResult
{
    public function __construct(
        /** Position of this message in the submitted batch, 0-based. */
        public readonly int $index,
        /** Whether the message entered the queue. */
        public readonly BatchMessageStatus $status,
        /** ID of the queued notification — null when rejected. */
        public readonly ?string $id = null,
        /** Why the message was rejected — null when queued. */
        public readonly ?string $error = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var BatchMessageStatus $status */
        $status = Payload::enum($data, 'status', BatchMessageStatus::class);

        return new self(
            index: Payload::int($data, 'index'),
            status: $status,
            id: Payload::stringOrNull($data, 'id'),
            error: Payload::stringOrNull($data, 'error'),
        );
    }

    public function isQueued(): bool
    {
        return $this->status === BatchMessageStatus::Queued;
    }
}
