<?php

declare(strict_types=1);

namespace Pulsenote\Tests;

use Pulsenote\Enum\NotificationStatus;
use Pulsenote\Model\Notification;
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
}
