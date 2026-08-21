<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Enum\NotificationStatus;
use Pulsenote\Internal\Payload;

/**
 * A single email notification and its delivery state.
 */
final class Notification
{
    public function __construct(
        public readonly string $id,
        /** Recipient email address. */
        public readonly string $recipient,
        /** Current delivery status. */
        public readonly NotificationStatus $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        /** Email subject line. */
        public readonly ?string $subject = null,
        /** ID of the template used, if any. */
        public readonly ?string $templateId = null,
        /** Human-readable name of that template, if any. */
        public readonly ?string $templateName = null,
        /** Sender the email was actually sent from, after the tenant default was resolved. */
        public readonly ?string $fromAddress = null,
        /** Upstream provider (SES) message ID. */
        public readonly ?string $providerMessageId = null,
        /** When the email was handed to the provider. */
        public readonly ?\DateTimeImmutable $sentAt = null,
        /** When delivery was confirmed. */
        public readonly ?\DateTimeImmutable $deliveredAt = null,
        /** When the send failed. */
        public readonly ?\DateTimeImmutable $failedAt = null,
        /** Reason for failure, if failed or bounced. */
        public readonly ?string $failureReason = null,
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
            recipient: Payload::string($data, 'recipient'),
            status: $status,
            createdAt: Payload::dateTime($data, 'createdAt'),
            updatedAt: Payload::dateTime($data, 'updatedAt'),
            subject: Payload::stringOrNull($data, 'subject'),
            templateId: Payload::stringOrNull($data, 'templateId'),
            templateName: Payload::stringOrNull($data, 'templateName'),
            fromAddress: Payload::stringOrNull($data, 'fromAddress'),
            providerMessageId: Payload::stringOrNull($data, 'providerMessageId'),
            sentAt: Payload::dateTimeOrNull($data, 'sentAt'),
            deliveredAt: Payload::dateTimeOrNull($data, 'deliveredAt'),
            failedAt: Payload::dateTimeOrNull($data, 'failedAt'),
            failureReason: Payload::stringOrNull($data, 'failureReason'),
        );
    }
}
