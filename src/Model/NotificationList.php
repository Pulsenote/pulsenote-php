<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Internal\Payload;

/**
 * One page of notifications. Iterate it directly to walk the page's rows:
 *
 * ```php
 * foreach ($pulsenote->notifications->list(limit: 50) as $notification) { … }
 * ```
 *
 * @implements \IteratorAggregate<int,Notification>
 */
final class NotificationList implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Notification> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly PaginationMeta $meta,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            data: array_map(
                static fn (array $row): Notification => Notification::fromArray($row),
                Payload::objectList($payload, 'data'),
            ),
            meta: PaginationMeta::fromArray(Payload::map($payload, 'meta')),
        );
    }

    /**
     * @return \ArrayIterator<int,Notification>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    /**
     * Rows on this page — not the total across all pages (see `$meta->total`).
     */
    public function count(): int
    {
        return count($this->data);
    }
}
