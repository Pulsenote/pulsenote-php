<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Internal\Payload;

/**
 * Page counters attached to a paginated list response.
 */
final class PaginationMeta
{
    public function __construct(
        /** Total number of matching records. */
        public readonly int $total,
        /** Current page (1-based). */
        public readonly int $page,
        /** Page size. */
        public readonly int $limit,
        /** Total number of pages. */
        public readonly int $pages,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            total: Payload::int($data, 'total'),
            page: Payload::int($data, 'page'),
            limit: Payload::int($data, 'limit'),
            pages: Payload::int($data, 'pages'),
        );
    }

    /**
     * Whether another page follows this one.
     */
    public function hasNextPage(): bool
    {
        return $this->page < $this->pages;
    }
}
