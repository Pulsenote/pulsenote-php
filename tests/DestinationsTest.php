<?php

declare(strict_types=1);

namespace Pulsenote\Tests;

use Pulsenote\Enum\DestinationType;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Exception\ConflictException;
use Pulsenote\Tests\Support\TestCase;

final class DestinationsTest extends TestCase
{
    /**
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    private static function payload(array $over = []): array
    {
        return $over + [
            'id' => 'd-1',
            'type' => 'slack',
            'name' => 'ops-alerts',
            'displayHint' => 'Acme · #ops-alerts',
            'brokenAt' => null,
            'brokenReason' => null,
            'lastDeliveredAt' => null,
            'createdAt' => '2026-10-20T10:00:00.000Z',
        ];
    }

    public function testRegistersSlackThroughYourOwnApp(): void
    {
        $this->http->push(201, self::payload());

        $d = $this->client()->destinations->create(
            type: DestinationType::Slack,
            name: 'ops-alerts',
            token: 'xoxb-123',
            channel: '#ops-alerts',
        );

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v1/destinations', $request->getUri()->getPath());
        self::assertSame(
            ['type' => 'slack', 'name' => 'ops-alerts', 'token' => 'xoxb-123', 'channel' => '#ops-alerts'],
            json_decode((string) $request->getBody(), true),
        );
        self::assertSame(DestinationType::Slack, $d->type);
        self::assertSame('Acme · #ops-alerts', $d->displayHint);
        self::assertFalse($d->isBroken());
    }

    public function testAWebhookComesBackWithItsSigningSecret(): void
    {
        $this->http->push(201, self::payload(['type' => 'webhook', 'signingSecret' => 'whsec_abc']));

        $d = $this->client()->destinations->create(
            type: DestinationType::Webhook,
            name: 'orders',
            url: 'https://example.com/hook',
        );

        self::assertSame(
            ['type' => 'webhook', 'name' => 'orders', 'url' => 'https://example.com/hook'],
            json_decode((string) $this->http->lastRequest()->getBody(), true),
        );
        self::assertSame('whsec_abc', $d->signingSecret);
    }

    /**
     * @return iterable<string,array{0: callable}>
     */
    public static function invalidCombinations(): iterable
    {
        yield 'token for Discord' => [static fn ($r) => $r->create(DestinationType::Discord, 'x', token: 'xoxb-1', channel: '#x')];
        yield 'token without channel' => [static fn ($r) => $r->create(DestinationType::Slack, 'x', token: 'xoxb-1')];
        yield 'token and url' => [static fn ($r) => $r->create(DestinationType::Slack, 'x', url: 'https://hooks.slack.com/services/a/b/c', token: 'xoxb-1', channel: '#x')];
        yield 'nothing at all' => [static fn ($r) => $r->create(DestinationType::Teams, 'x')];
    }

    /**
     * @dataProvider invalidCombinations
     */
    public function testRefusesCombinationsTheApiWouldRejectWithoutCallingIt(callable $call): void
    {
        $this->expectException(ConfigurationException::class);
        try {
            $call($this->client()->destinations);
        } finally {
            self::assertSame(0, $this->http->requestCount());
        }
    }

    public function testDuplicateNameIsAConflict(): void
    {
        $this->http->push(409, ['message' => 'exists']);

        $this->expectException(ConflictException::class);
        $this->client()->destinations->create(DestinationType::Discord, 'ops', url: 'https://discord.com/api/webhooks/1/a');
    }

    public function testListTestAndDelete(): void
    {
        $this->http->push(200, [self::payload(['brokenAt' => '2026-10-20T11:00:00.000Z', 'brokenReason' => 'not_in_channel'])]);
        $list = $this->client()->destinations->list();
        self::assertTrue($list[0]->isBroken());
        self::assertSame('not_in_channel', $list[0]->brokenReason);

        $this->http->push(200, ['status' => 'sent']);
        self::assertTrue($this->client()->destinations->test('a/b')->sent());
        self::assertSame('/api/v1/destinations/a%2Fb/test', $this->http->lastRequest()->getUri()->getPath());

        $this->http->push(200, ['message' => 'Destination removed']);
        $this->client()->destinations->delete('d-1');
        self::assertSame('DELETE', $this->http->lastRequest()->getMethod());
    }

    public function testSendsAMessageByName(): void
    {
        $this->http->push(202, ['id' => 'n-1', 'status' => 'QUEUED', 'channel' => 'slack']);

        $res = $this->client()->messages->send(to: 'ops-alerts', text: '€240', title: 'New order');

        self::assertSame('/api/v1/messages', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame(
            ['to' => 'ops-alerts', 'title' => 'New order', 'text' => '€240'],
            json_decode((string) $this->http->lastRequest()->getBody(), true),
        );
        self::assertSame('n-1', $res->id);
        self::assertSame(DestinationType::Slack, $res->channel);
    }
}
