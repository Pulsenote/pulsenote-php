<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Enum\NotificationStatus;
use Pulsenote\Internal\Payload;

/**
 * Acknowledgement of an accepted send. The email is queued, not yet delivered —
 * poll {@see \Pulsenote\Resource\Notifications::get()} with {@see $id} to follow it.
 *
 * If the account has no verified sending domain the message is rendered but never
 * delivered, and {@see $sandbox} is `true`. Check it before assuming anything left
 * the building.
 */
final class SendEmailResponse
{
    public function __construct(
        /** ID of the queued notification. */
        public readonly string $id,
        /** Status at time of acceptance: `QUEUED` for a live send, `SANDBOX` otherwise. */
        public readonly NotificationStatus $status,
        /** Resolved sender address the email will be sent from. */
        public readonly string $from,
        /**
         * True when the message was rendered but NOT delivered, because no sending
         * domain is verified. Assert on this in integration tests to be sure you are
         * really sending.
         */
        public readonly bool $sandbox = false,
        /** Human-readable explanation, present in sandbox mode. */
        public readonly ?string $message = null,
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
            sandbox: Payload::boolOrDefault($data, 'sandbox'),
            message: Payload::stringOrNull($data, 'message'),
        );
    }
}
