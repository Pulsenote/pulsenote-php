<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Internal\Payload;

/**
 * Outcome of a batch send.
 *
 * A batch is **partial-success by design**: the call returns `202` even when some
 * messages were rejected, so a non-throwing `sendBatch()` does not mean everything was
 * queued. Check {@see $rejected} (or {@see rejections()}) before assuming it did.
 *
 * ```php
 * $batch = $pulsenote->notifications->sendBatch($messages);
 *
 * foreach ($batch->rejections() as $failed) {
 *     echo $failed->index, ': ', $failed->error, PHP_EOL;
 * }
 * ```
 *
 * @implements \IteratorAggregate<int,BatchMessageResult>
 */
final class BatchSendResult implements \IteratorAggregate, \Countable
{
    /**
     * @param list<BatchMessageResult> $results Per-message outcomes, in submission order.
     */
    public function __construct(
        /** Messages submitted. */
        public readonly int $total,
        /** Messages that entered the queue. */
        public readonly int $queued,
        /** Messages the API refused. */
        public readonly int $rejected,
        public readonly array $results,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            total: Payload::int($data, 'total'),
            queued: Payload::int($data, 'queued'),
            rejected: Payload::int($data, 'rejected'),
            results: array_map(
                static fn (array $row): BatchMessageResult => BatchMessageResult::fromArray($row),
                Payload::objectList($data, 'results'),
            ),
        );
    }

    /**
     * Whether every submitted message was queued.
     */
    public function isCompletelySuccessful(): bool
    {
        return $this->rejected === 0;
    }

    /**
     * Only the messages that were refused — the ones worth retrying or logging.
     *
     * @return list<BatchMessageResult>
     */
    public function rejections(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (BatchMessageResult $r): bool => !$r->isQueued(),
        ));
    }

    /**
     * IDs of the queued notifications, in submission order.
     *
     * @return list<string>
     */
    public function queuedIds(): array
    {
        $ids = [];
        foreach ($this->results as $result) {
            if ($result->isQueued() && $result->id !== null) {
                $ids[] = $result->id;
            }
        }

        return $ids;
    }

    /**
     * @return \ArrayIterator<int,BatchMessageResult>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->results);
    }

    /**
     * Per-message results returned — normally equal to {@see $total}.
     */
    public function count(): int
    {
        return count($this->results);
    }
}
