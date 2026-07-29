<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Enum\NotificationStatus;
use Pulsenote\Internal\Payload;

/**
 * Aggregate send counters for the tenant.
 */
final class NotificationStats
{
    /**
     * @param array<string,int>          $counts Count keyed by status name, e.g. `['DELIVERED' => 120]`.
     * @param list<array<string,mixed>>  $daily  Per-day counts by status for the last 30 days,
     *                                           e.g. `[['date' => '2026-07-08', 'DELIVERED' => 40]]`.
     */
    public function __construct(
        /** Total notifications across all statuses. */
        public readonly int $total,
        public readonly array $counts,
        /** Total notifications sent this calendar month. */
        public readonly int $thisMonth,
        public readonly array $daily,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $counts = [];
        foreach (Payload::map($data, 'counts') as $status => $count) {
            if (is_numeric($count)) {
                $counts[(string) $status] = (int) $count;
            }
        }

        return new self(
            total: Payload::int($data, 'total'),
            counts: $counts,
            thisMonth: Payload::int($data, 'thisMonth'),
            daily: Payload::objectList($data, 'daily'),
        );
    }

    /**
     * Count for one status, or 0 when the API reported none.
     */
    public function countFor(NotificationStatus $status): int
    {
        return $this->counts[$status->value] ?? 0;
    }
}
