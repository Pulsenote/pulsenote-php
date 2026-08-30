<?php

declare(strict_types=1);

namespace Pulsenote\Enum;

/**
 * Which kind of mail a message or suppression belongs to.
 *
 * Suppression is scoped per stream on purpose: an address that asked to stop
 * receiving your newsletter should still get its password reset.
 */
enum MessageStream: string
{
    /** One-to-one mail the recipient asked for: receipts, resets, alerts. */
    case Transactional = 'transactional';

    /** Bulk or marketing mail. */
    case Broadcast = 'broadcast';
}
