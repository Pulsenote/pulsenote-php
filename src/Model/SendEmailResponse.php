<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Enum\NotificationStatus;
use Pulsenote\Internal\Payload;

/**
 * Acknowledgement of an accepted send. The email is queued, not yet delivered —
 * poll {@see \Pulsenote\Resource\Notifications::get()} with {@see $id} to follow it.
 */
final class SendEmailResponse
{
    public function __construct(
        /** ID of the queued notification. */
        public readonly string $id,
        /** Status at time of acceptance (always `QUEUED`). */
        public readonly NotificationStatus $status,
        /** Resolved sender address the email will be sent from. */
        public readonly string $from,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var NotificationStatus $status */
        $status = Payload::enum($data, 'status', NotificationStatus::class);

        return new self(
            id: Payload::string($data, 'id'),
            status: $status,
            from: Payload::string($data, 'from'),
        );
    }
}
