<?php

declare(strict_types=1);

namespace Pulsenote\Tests;

use Pulsenote\Enum\BatchMessageStatus;
use Pulsenote\Enum\NotificationStatus;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Exception\TransportException;
use Pulsenote\Model\BatchMessage;
use Pulsenote\Model\Notification;
use Pulsenote\Resource\Notifications;
use Pulsenote\Tests\Support\TestCase;

final class NotificationsTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private static function notificationPayload(string $id = 'n-1'): array
    {
        return [
            'id' => $id,
            'recipient' => 'greg@example.com',
            'subject' => 'Welcome',
            'status' => 'DELIVERED',
            'templateId' => 't-1',
            'templateName' => 'Welcome email',
            'fromAddress' => 'noreply@sysgp.eu',
            'providerMessageId' => 'ses-123',
            'sentAt' => '2026-07-28T10:00:00.000Z',
            'deliveredAt' => '2026-07-28T10:00:04.000Z',
            'createdAt' => '2026-07-28T09:59:59.000Z',
            'updatedAt' => '2026-07-28T10:00:04.000Z',
        ];
    }

    public function testSendPostsTheBodyAndReturnsTheQueuedId(): void
    {
        $this->http->push(202, ['id' => 'abc', 'status' => 'QUEUED', 'from' => 'noreply@sysgp.eu']);

        $res = $this->client()->notifications->send(
            to: 'greg@example.com',
            subject: 'Welcome',
            html: '<b>Hi</b>',
        );

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v1/notifications/send', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        self::assertSame('abc', $res->id);
        self::assertSame(NotificationStatus::Queued, $res->status);
        self::assertSame('noreply@sysgp.eu', $res->from);
    }

    /**
     * The SpecCoverageTest guard only fails on new *operations*, so response fields
     * can drift in unnoticed. These pin the sandbox contract.
     *
     * Before SANDBOX was added to the enum, this very payload made Payload::enum()
     * throw a TransportException — a hard failure on a new user's first send, which
     * is precisely the case sandbox exists to serve.
     */
    public function testSendSurfacesSandboxWhenNoDomainIsVerified(): void
    {
        $this->http->push(202, [
            'id' => 'n-sbx',
            'status' => 'SANDBOX',
            'from' => 'noreply@not-yet-verified.com',
            'sandbox' => true,
            'message' => 'Sandbox mode: the email was rendered but NOT delivered…',
        ]);

        $res = $this->client()->notifications->send(
            to: 'greg@example.com',
            from: 'noreply@not-yet-verified.com',
            subject: 'Welcome',
            html: '<b>Hi</b>',
        );

        self::assertTrue($res->sandbox);
        self::assertSame(NotificationStatus::Sandbox, $res->status);
        self::assertStringContainsString('NOT delivered', (string) $res->message);
        // Echoed back untouched — that is what makes going live a domain
        // verification rather than a code change.
        self::assertSame('noreply@not-yet-verified.com', $res->from);
        // Nothing will move it, so it is terminal.
        self::assertTrue($res->status->isTerminal());
    }

    public function testSendLeavesSandboxFalseOnALiveSend(): void
    {
        $this->http->push(202, ['id' => 'abc', 'status' => 'QUEUED', 'from' => 'noreply@sysgp.eu']);

        $res = $this->client()->notifications->send(
            to: 'greg@example.com',
            subject: 'Welcome',
            html: '<b>Hi</b>',
        );

        self::assertFalse($res->sandbox);
        self::assertNull($res->message);
    }

    public function testSendOmitsUnsetOptionalFields(): void
    {
        $this->http->push(202, ['id' => 'abc', 'status' => 'QUEUED', 'from' => 'noreply@sysgp.eu']);

        $this->client()->notifications->send(to: 'greg@example.com', html: '<b>Hi</b>');

        // An explicit null would clobber the tenant's server-side defaults (sender, locale).
        self::assertSame(['to' => 'greg@example.com', 'html' => '<b>Hi</b>'], $this->http->lastBody());
    }

    public function testSendCarriesTemplateData(): void
    {
        $this->http->push(202, ['id' => 'abc', 'status' => 'QUEUED', 'from' => 'noreply@sysgp.eu']);

        $this->client()->notifications->send(
            to: 'greg@example.com',
            templateSlug: 'welcome',
            locale: 'pl',
            templateData: ['name' => 'Greg', 'plan' => 'pro'],
        );

        self::assertSame([
            'to' => 'greg@example.com',
            'templateSlug' => 'welcome',
            'locale' => 'pl',
            'templateData' => ['name' => 'Greg', 'plan' => 'pro'],
        ], $this->http->lastBody());
    }

    public function testListSerialisesQueryParameters(): void
    {
        $this->http->push(200, [
            'data' => [self::notificationPayload()],
            'meta' => ['total' => 1, 'page' => 2, 'limit' => 25, 'pages' => 1],
        ]);

        $page = $this->client()->notifications->list(page: 2, limit: 25, status: NotificationStatus::Delivered);

        self::assertSame('page=2&limit=25&status=DELIVERED', $this->http->lastRequest()->getUri()->getQuery());
        self::assertCount(1, $page);
        self::assertSame(1, $page->meta->total);
        self::assertFalse($page->meta->hasNextPage());

        $first = $page->data[0];
        self::assertInstanceOf(Notification::class, $first);
        self::assertSame(NotificationStatus::Delivered, $first->status);
        self::assertTrue($first->status->isTerminal());
        self::assertSame('2026-07-28T10:00:04+00:00', $first->deliveredAt?->format(\DateTimeInterface::ATOM));
        self::assertSame('Welcome email', $first->templateName);
        self::assertSame('noreply@sysgp.eu', $first->fromAddress);
        self::assertNull($first->failureReason);
    }

    public function testListOmitsUnsetQueryParameters(): void
    {
        $this->http->push(200, ['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'limit' => 20, 'pages' => 0]]);

        $this->client()->notifications->list();

        self::assertSame('', $this->http->lastRequest()->getUri()->getQuery());
    }

    public function testListIsIterable(): void
    {
        $this->http->push(200, [
            'data' => [self::notificationPayload('n-1'), self::notificationPayload('n-2')],
            'meta' => ['total' => 2, 'page' => 1, 'limit' => 20, 'pages' => 1],
        ]);

        $ids = [];
        foreach ($this->client()->notifications->list() as $notification) {
            $ids[] = $notification->id;
        }

        self::assertSame(['n-1', 'n-2'], $ids);
    }

    public function testGetUrlEncodesThePathParameter(): void
    {
        $this->http->push(200, self::notificationPayload('n/1'));

        $this->client()->notifications->get('n/1');

        self::assertSame('/api/v1/notifications/n%2F1', $this->http->lastRequest()->getUri()->getPath());
    }

    public function testGetRejectsAnEmptyId(): void
    {
        $this->expectException(\Pulsenote\Exception\ConfigurationException::class);

        $this->client()->notifications->get('  ');
    }

    public function testStats(): void
    {
        $this->http->push(200, [
            'total' => 128,
            'counts' => ['DELIVERED' => 120, 'BOUNCED' => 3, 'QUEUED' => 5],
            'thisMonth' => 42,
            'daily' => [['date' => '2026-07-08', 'DELIVERED' => 40]],
        ]);

        $stats = $this->client()->notifications->stats();

        self::assertSame('/api/v1/notifications/stats', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame(128, $stats->total);
        self::assertSame(42, $stats->thisMonth);
        self::assertSame(120, $stats->countFor(NotificationStatus::Delivered));
        self::assertSame(0, $stats->countFor(NotificationStatus::Failed));
        self::assertSame([['date' => '2026-07-08', 'DELIVERED' => 40]], $stats->daily);
    }

    public function testAllWalksEveryPage(): void
    {
        $this->http
            ->push(200, [
                'data' => [self::notificationPayload('n-1')],
                'meta' => ['total' => 2, 'page' => 1, 'limit' => 1, 'pages' => 2],
            ])
            ->push(200, [
                'data' => [self::notificationPayload('n-2')],
                'meta' => ['total' => 2, 'page' => 2, 'limit' => 1, 'pages' => 2],
            ]);

        $ids = [];
        foreach ($this->client()->notifications->all(pageSize: 1) as $notification) {
            $ids[] = $notification->id;
        }

        self::assertSame(['n-1', 'n-2'], $ids);
        self::assertCount(2, $this->http->requests);
        self::assertSame('page=2&limit=1', $this->http->requests[1]->getUri()->getQuery());
    }

    public function testAllStopsOnAnEmptyPage(): void
    {
        // A server that ignores `page` would otherwise keep the generator spinning forever.
        $this->http->push(200, [
            'data' => [],
            'meta' => ['total' => 5, 'page' => 1, 'limit' => 1, 'pages' => 5],
        ]);

        $ids = iterator_to_array($this->client()->notifications->all(pageSize: 1), false);

        self::assertSame([], $ids);
        self::assertCount(1, $this->http->requests);
    }
    public function testListSendsTheSearchTerm(): void
    {
        $this->http->push(200, ['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'limit' => 20, 'pages' => 0]]);

        $this->client()->notifications->list(search: 'greg@example.com');

        self::assertSame('search=greg%40example.com', $this->http->lastRequest()->getUri()->getQuery());
    }

    public function testAllForwardsTheSearchTermToEveryPage(): void
    {
        $this->http
            ->push(200, [
                'data' => [self::notificationPayload('n-1')],
                'meta' => ['total' => 2, 'page' => 1, 'limit' => 1, 'pages' => 2],
            ])
            ->push(200, [
                'data' => [self::notificationPayload('n-2')],
                'meta' => ['total' => 2, 'page' => 2, 'limit' => 1, 'pages' => 2],
            ]);

        iterator_to_array($this->client()->notifications->all(pageSize: 1, search: 'welcome'), false);

        self::assertCount(2, $this->http->requests);
        self::assertSame('page=1&limit=1&search=welcome', $this->http->requests[0]->getUri()->getQuery());
        self::assertSame('page=2&limit=1&search=welcome', $this->http->requests[1]->getUri()->getQuery());
    }

    public function testSendBatchPostsEveryMessageAndPrunesUnsetFields(): void
    {
        $this->http->push(202, [
            'total' => 2,
            'queued' => 2,
            'rejected' => 0,
            'results' => [
                ['index' => 0, 'status' => 'queued', 'id' => 'n-1'],
                ['index' => 1, 'status' => 'queued', 'id' => 'n-2'],
            ],
        ]);

        $this->client()->notifications->sendBatch([
            new BatchMessage(to: 'a@example.com', subject: 'Hi', html: '<b>Hi</b>'),
            new BatchMessage(to: 'b@example.com', templateSlug: 'welcome', locale: 'pl', templateData: ['name' => 'Greg']),
        ]);

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v1/notifications/batch', $request->getUri()->getPath());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));

        // Nested nulls are pruned by the message itself — the transport only prunes the top level.
        self::assertSame([
            'messages' => [
                ['to' => 'a@example.com', 'subject' => 'Hi', 'html' => '<b>Hi</b>'],
                ['to' => 'b@example.com', 'templateSlug' => 'welcome', 'locale' => 'pl', 'templateData' => ['name' => 'Greg']],
            ],
        ], $this->http->lastBody());
    }

    public function testSendBatchReportsPartialSuccess(): void
    {
        $this->http->push(202, [
            'total' => 3,
            'queued' => 2,
            'rejected' => 1,
            'results' => [
                ['index' => 0, 'status' => 'queued', 'id' => 'n-1'],
                ['index' => 1, 'status' => 'rejected', 'error' => 'Domain not verified'],
                ['index' => 2, 'status' => 'queued', 'id' => 'n-3'],
            ],
        ]);

        $batch = $this->client()->notifications->sendBatch([
            new BatchMessage(to: 'a@example.com', html: 'a'),
            new BatchMessage(to: 'b@nope.com', html: 'b'),
            new BatchMessage(to: 'c@example.com', html: 'c'),
        ]);

        // A 202 with rejections must not read as success.
        self::assertFalse($batch->isCompletelySuccessful());
        self::assertSame(3, $batch->total);
        self::assertSame(2, $batch->queued);
        self::assertSame(1, $batch->rejected);
        self::assertCount(3, $batch);
        self::assertSame(['n-1', 'n-3'], $batch->queuedIds());

        $rejections = $batch->rejections();
        self::assertCount(1, $rejections);
        self::assertSame(1, $rejections[0]->index);
        self::assertSame(BatchMessageStatus::Rejected, $rejections[0]->status);
        self::assertSame('Domain not verified', $rejections[0]->error);
        self::assertNull($rejections[0]->id);
        self::assertFalse($rejections[0]->isQueued());
    }

    public function testSendBatchIsIterable(): void
    {
        $this->http->push(202, [
            'total' => 1,
            'queued' => 1,
            'rejected' => 0,
            'results' => [['index' => 0, 'status' => 'queued', 'id' => 'n-1']],
        ]);

        $batch = $this->client()->notifications->sendBatch([new BatchMessage(to: 'a@example.com', html: 'a')]);

        $ids = [];
        foreach ($batch as $result) {
            $ids[] = $result->id;
        }

        self::assertSame(['n-1'], $ids);
        self::assertTrue($batch->isCompletelySuccessful());
    }

    public function testSendBatchRejectsAnEmptyBatch(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->client()->notifications->sendBatch([]);
    }

    public function testSendBatchRejectsAnOversizedBatchWithoutCallingTheApi(): void
    {
        $messages = array_fill(0, Notifications::MAX_BATCH + 1, new BatchMessage(to: 'a@example.com', html: 'a'));

        try {
            $this->client()->notifications->sendBatch($messages);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('at most 500 messages', $e->getMessage());
        }

        self::assertSame([], $this->http->requests);
    }

    public function testSendBatchSurfacesAnUnknownPerMessageStatus(): void
    {
        // Guards against a silently-dropped outcome if the API grows a third status.
        $this->http->push(202, [
            'total' => 1,
            'queued' => 0,
            'rejected' => 0,
            'results' => [['index' => 0, 'status' => 'deferred']],
        ]);

        $this->expectException(TransportException::class);

        $this->client()->notifications->sendBatch([new BatchMessage(to: 'a@example.com', html: 'a')]);
    }
}