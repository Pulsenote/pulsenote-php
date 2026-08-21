<?php

declare(strict_types=1);

namespace Pulsenote\Resource;

use Pulsenote\Enum\NotificationStatus;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Internal\Operation;
use Pulsenote\Model\BatchMessage;
use Pulsenote\Model\BatchSendResult;
use Pulsenote\Model\Notification;
use Pulsenote\Model\NotificationList;
use Pulsenote\Model\NotificationStats;
use Pulsenote\Model\SendEmailResponse;

/**
 * Sending email and inspecting what happened to it.
 *
 * Reached as `$pulsenote->notifications`.
 */
final class Notifications extends Resource
{
    /**
     * Most messages the API accepts in one {@see sendBatch()} call.
     */
    public const MAX_BATCH = 500;

    /**
     * Queue an email for delivery.
     *
     * Supply either a body (`html` and/or `text`) or a template (`templateId` or
     * `templateSlug`); the API rejects a request with neither. The call returns as
     * soon as the email is queued — it is not yet delivered.
     *
     * ```php
     * $pulsenote->notifications->send(
     *     to: 'greg@example.com',
     *     subject: 'Welcome',
     *     html: '<b>Hello</b>',
     * );
     * ```
     *
     * @param string                   $to           Recipient email address.
     * @param string|null              $subject      Subject line. Ignored when the template supplies its own.
     * @param string|null              $from         Sender on a verified domain. Defaults to the tenant default sender.
     * @param string|null              $html         Raw HTML body (when not using a template).
     * @param string|null              $text         Plain-text body.
     * @param string|null              $templateId   Send using a stored template by ID.
     * @param string|null              $templateSlug Send using a stored template by slug.
     * @param string|null              $locale       Locale of the template variant to use (e.g. `en`, `pl`).
     * @param array<string,mixed>|null $templateData Variables interpolated into the template.
     *
     * @throws \Pulsenote\Exception\ValidationException The payload was rejected (bad address, no body, unverified sender).
     * @throws \Pulsenote\Exception\RateLimitException  The tenant's send quota is exhausted.
     * @throws \Pulsenote\Exception\ApiException        Any other non-2xx response.
     */
    #[Operation('sendNotification', 'POST', '/api/v1/notifications/send')]
    public function send(
        string $to,
        ?string $subject = null,
        ?string $from = null,
        ?string $html = null,
        ?string $text = null,
        ?string $templateId = null,
        ?string $templateSlug = null,
        ?string $locale = null,
        ?array $templateData = null,
    ): SendEmailResponse {
        return SendEmailResponse::fromArray($this->transport->requestObject(
            'POST',
            '/api/v1/notifications/send',
            body: [
                'to' => $to,
                'subject' => $subject,
                'from' => $from,
                'html' => $html,
                'text' => $text,
                'templateId' => $templateId,
                'templateSlug' => $templateSlug,
                'locale' => $locale,
                'templateData' => $templateData,
            ],
        ));
    }

    /**
     * Queue many emails in one request.
     *
     * Each message is validated and queued independently, so this is **partial-success**:
     * a bad recipient or an unverified sender rejects that one message and the rest still
     * go out. The call only throws for whole-request failures (bad API key, empty or
     * oversized batch, exhausted quota) — per-message failures come back in the result.
     *
     * ```php
     * $batch = $pulsenote->notifications->sendBatch([
     *     new BatchMessage(to: 'a@example.com', subject: 'Hi', html: '<b>Hi</b>'),
     *     new BatchMessage(to: 'b@example.com', templateSlug: 'welcome', locale: 'pl'),
     * ]);
     *
     * echo $batch->queued, '/', $batch->total, ' queued';
     * foreach ($batch->rejections() as $failed) {
     *     echo $failed->index, ': ', $failed->error, PHP_EOL;
     * }
     * ```
     *
     * @param list<BatchMessage> $messages Between 1 and {@see MAX_BATCH} messages.
     *
     * @throws ConfigurationException                   The batch is empty or larger than {@see MAX_BATCH}.
     * @throws \Pulsenote\Exception\RateLimitException   The tenant's send quota is exhausted.
     * @throws \Pulsenote\Exception\ApiException         Any other non-2xx response.
     */
    #[Operation('sendNotificationBatch', 'POST', '/api/v1/notifications/batch')]
    public function sendBatch(array $messages): BatchSendResult
    {
        // Caught here rather than at the API: an empty batch is always a caller bug, and a
        // 501-message batch would otherwise cost a round trip to be told the obvious.
        if ($messages === []) {
            throw new ConfigurationException('Pulsenote: sendBatch() needs at least one message.');
        }

        if (count($messages) > self::MAX_BATCH) {
            throw new ConfigurationException(sprintf(
                'Pulsenote: sendBatch() accepts at most %d messages, got %d. Chunk the list and send it in parts.',
                self::MAX_BATCH,
                count($messages),
            ));
        }

        return BatchSendResult::fromArray($this->transport->requestObject(
            'POST',
            '/api/v1/notifications/batch',
            body: [
                'messages' => array_map(
                    static fn (BatchMessage $message): array => $message->toPayload(),
                    array_values($messages),
                ),
            ],
        ));
    }
    /**
     * List notifications, newest first.
     *
     * @param int|null                $page   1-based page number.
     * @param int|null                $limit  Page size.
     * @param NotificationStatus|null $status Only return notifications in this state.
     * @param string|null             $search Match recipient or subject, case-insensitive.
     */
    #[Operation('listNotifications', 'GET', '/api/v1/notifications')]
    public function list(
        ?int $page = null,
        ?int $limit = null,
        ?NotificationStatus $status = null,
        ?string $search = null,
    ): NotificationList {
        return NotificationList::fromArray($this->transport->requestObject(
            'GET',
            '/api/v1/notifications',
            query: [
                'page' => $page,
                'limit' => $limit,
                'status' => $status?->value,
                'search' => $search,
            ],
        ));
    }

    /**
     * Fetch one notification by ID.
     *
     * @throws \Pulsenote\Exception\NotFoundException No such notification for this tenant.
     */
    #[Operation('getNotification', 'GET', '/api/v1/notifications/{id}')]
    public function get(string $id): Notification
    {
        return Notification::fromArray($this->transport->requestObject(
            'GET',
            $this->path('/api/v1/notifications/{id}', ['id' => $id]),
        ));
    }

    /**
     * Aggregate send counters for the tenant.
     */
    #[Operation('getNotificationStats', 'GET', '/api/v1/notifications/stats')]
    public function stats(): NotificationStats
    {
        return NotificationStats::fromArray($this->transport->requestObject('GET', '/api/v1/notifications/stats'));
    }

    /**
     * Walk every notification across all pages, fetching each page lazily.
     *
     * ```php
     * foreach ($pulsenote->notifications->all(status: NotificationStatus::Bounced) as $n) { … }
     * ```
     *
     * @param int                     $pageSize Rows per underlying request.
     * @param NotificationStatus|null $status   Only return notifications in this state.
     * @param string|null             $search   Match recipient or subject, case-insensitive.
     *
     * @return \Generator<int,Notification>
     */
    public function all(int $pageSize = 100, ?NotificationStatus $status = null, ?string $search = null): \Generator
    {
        $page = 1;

        do {
            $result = $this->list(page: $page, limit: $pageSize, status: $status, search: $search);

            yield from $result->data;

            // Stop on an empty page too — a server that ignores `page` would otherwise loop forever.
            $page++;
        } while ($result->data !== [] && $result->meta->hasNextPage());
    }
}
