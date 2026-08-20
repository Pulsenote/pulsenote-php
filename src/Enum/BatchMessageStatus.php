<?php

declare(strict_types=1);

namespace Pulsenote\Enum;

/**
 * Outcome of a single message inside a batch send.
 *
 * Note the lowercase wire values: the batch endpoint reports per-message acceptance,
 * not delivery state, and deliberately uses its own vocabulary rather than
 * {@see NotificationStatus} (`QUEUED`, `DELIVERED`, …). A `Queued` message here has an
 * `id` you can follow with {@see \Pulsenote\Resource\Notifications::get()}; a
 * `Rejected` one never entered the queue.
 */
enum BatchMessageStatus: string
{
    case Queued = 'queued';
    case Rejected = 'rejected';
}
