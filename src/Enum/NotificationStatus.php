<?php

declare(strict_types=1);

namespace Pulsenote\Enum;

/**
 * Delivery lifecycle of a notification.
 */
enum NotificationStatus: string
{
    case Pending = 'PENDING';
    case Queued = 'QUEUED';
    case Sent = 'SENT';
    case Delivered = 'DELIVERED';
    case Failed = 'FAILED';
    case Bounced = 'BOUNCED';
    /**
     * Rendered but deliberately never delivered, because the account has no
     * verified sending domain yet. Not a failure — see
     * {@see \Pulsenote\Model\SendEmailResponse::$sandbox}.
     */
    case Sandbox = 'SANDBOX';

    /**
     * Whether the notification reached a state it will not move out of.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Delivered, self::Failed, self::Bounced, self::Sandbox => true,
            default => false,
        };
    }
}
