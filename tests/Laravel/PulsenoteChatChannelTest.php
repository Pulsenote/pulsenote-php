<?php

declare(strict_types=1);

namespace Pulsenote\Tests\Laravel;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Pulsenote\Enum\DestinationType;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Exception\NotFoundException;
use Pulsenote\Exception\RateLimitException;
use Pulsenote\Laravel\PulsenoteChatChannel;
use Pulsenote\Laravel\PulsenoteChatMessage;
use Pulsenote\Laravel\PulsenoteMessage;
use Pulsenote\Model\SendMessageResponse;
use Pulsenote\Tests\Support\TestCase;

/**
 * Pins the `pulsenote-chat` channel: `toPulsenoteChat()` posts to a destination named
 * by the message, then the notifiable's route, then config; a missing destination or
 * text sends nothing; and every API failure is thrown rather than swallowed, so queued
 * notifications retry.
 *
 * Like the email channel test, this runs without booting a Laravel app. How the two
 * channels behave side by side through Laravel's ChannelManager is pinned in
 * {@see PulsenoteServiceProviderTest}.
 */
final class PulsenoteChatChannelTest extends TestCase
{
    private function channel(?string $defaultDestination = null): PulsenoteChatChannel
    {
        return new PulsenoteChatChannel($this->client(), $defaultDestination);
    }

    private function messageAccepted(string $id = 'msg-1'): void
    {
        $this->http->push(202, ['id' => $id, 'status' => 'QUEUED', 'channel' => 'teams']);
    }

    public function testPostsTheMessageToTheDestination(): void
    {
        $this->messageAccepted();

        $response = $this->channel()->send(
            new ChatUser(),
            new ChatNotification(PulsenoteChatMessage::make('Order #1042 — €240.00')->title('New order')->to('sales-teams')),
        );

        self::assertInstanceOf(SendMessageResponse::class, $response);
        self::assertSame('msg-1', $response->id);
        self::assertSame(DestinationType::Teams, $response->channel);
        self::assertSame(1, $this->http->requestCount());

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v1/messages', $request->getUri()->getPath());
        self::assertSame(
            ['to' => 'sales-teams', 'title' => 'New order', 'text' => 'Order #1042 — €240.00'],
            $this->http->lastBody(),
        );
    }

    public function testAnUnsetTitleIsLeftOutOfTheRequest(): void
    {
        $this->messageAccepted();

        $this->channel()->send(
            new ChatUser(),
            new ChatNotification(PulsenoteChatMessage::make()->text('Deploy finished')->to('ops')),
        );

        self::assertSame(['to' => 'ops', 'text' => 'Deploy finished'], $this->http->lastBody());
    }

    public function testTheMessageDestinationWinsOverTheRouteAndTheConfigDefault(): void
    {
        $this->messageAccepted();

        $this->channel('from-config')->send(
            new ChatRoutedUser('from-route'),
            new ChatNotification(PulsenoteChatMessage::make('Hi')->to('from-message')),
        );

        self::assertSame('from-message', $this->http->lastBody()['to']);
    }

    public function testTheNotifiableRouteWinsOverTheConfigDefault(): void
    {
        $this->messageAccepted();

        $this->channel('from-config')->send(
            new ChatRoutedUser('from-route'),
            new ChatNotification(PulsenoteChatMessage::make('Hi')),
        );

        self::assertSame('from-route', $this->http->lastBody()['to']);
    }

    public function testAnOnDemandRouteIsUsed(): void
    {
        $this->messageAccepted();

        $this->channel('from-config')->send(
            (new AnonymousNotifiable())->route('pulsenote-chat', 'on-demand'),
            new ChatNotification(PulsenoteChatMessage::make('Hi')),
        );

        self::assertSame('on-demand', $this->http->lastBody()['to']);
    }

    public function testTheConfigDefaultIsTheLastResort(): void
    {
        $this->messageAccepted();

        // ChatUser routes `mail` only, so its `pulsenote-chat` route is null.
        $this->channel('from-config')->send(new ChatUser(), new ChatNotification(PulsenoteChatMessage::make('Hi')));

        self::assertSame('from-config', $this->http->lastBody()['to']);
    }

    public function testNoDestinationAnywhereThrowsAndSendsNothing(): void
    {
        try {
            $this->channel()->send(new ChatUser(), new ChatNotification(PulsenoteChatMessage::make('Hi')));
            self::fail('Expected a missing destination to throw.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('PULSENOTE_CHAT_DESTINATION', $e->getMessage());
        }

        self::assertSame(0, $this->http->requestCount());
    }

    public function testAMessageWithoutTextIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('without text');

        $this->channel()->send(new ChatUser(), new ChatNotification(PulsenoteChatMessage::make()->to('ops')));
    }

    public function testRejectsANotificationWithoutToPulsenoteChat(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('has no toPulsenoteChat() method');

        $this->channel('ops')->send(new ChatUser(), new Notification());
    }

    public function testToPulsenoteChatMustReturnAChatMessage(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('toPulsenoteChat() must return a ' . PulsenoteChatMessage::class);

        $this->channel('ops')->send(new ChatUser(), new ChatReturningEmail());
    }

    public function testAnUnknownDestinationThrows(): void
    {
        $this->http->push(404, ['statusCode' => 404, 'message' => 'No destination named "nope"']);

        try {
            $this->channel()->send(new ChatUser(), new ChatNotification(PulsenoteChatMessage::make('Hi')->to('nope')));
            self::fail('Expected the 404 to propagate.');
        } catch (NotFoundException $e) {
            self::assertStringContainsString('No destination named', $e->getMessage());
        }
    }

    public function testApiFailuresPropagateSoQueuedJobsRetry(): void
    {
        $this->http->push(429, ['message' => 'Rate limit exceeded'], ['Retry-After' => '30']);

        try {
            $this->channel()->send(new ChatUser(), new ChatNotification(PulsenoteChatMessage::make('Hi')->to('ops')));
            self::fail('Expected the rate-limit error to propagate.');
        } catch (RateLimitException $e) {
            self::assertSame(30, $e->retryAfter);
        }
    }
}

final class ChatUser
{
    public string $email = 'greg@example.com';

    /**
     * A mail route only — the chat channel must not mistake it for a destination.
     */
    public function routeNotificationFor(string $channel, ?Notification $notification = null): ?string
    {
        return $channel === 'mail' ? $this->email : null;
    }
}

final class ChatRoutedUser
{
    public function __construct(private readonly string $destination)
    {
    }

    public function routeNotificationFor(string $channel, ?Notification $notification = null): ?string
    {
        return $channel === 'pulsenote-chat' ? $this->destination : null;
    }
}

final class ChatNotification extends Notification
{
    public function __construct(private readonly PulsenoteChatMessage $message)
    {
    }

    public function toPulsenoteChat(mixed $notifiable): PulsenoteChatMessage
    {
        return $this->message;
    }
}

final class ChatReturningEmail extends Notification
{
    public function toPulsenoteChat(mixed $notifiable): PulsenoteMessage
    {
        return PulsenoteMessage::make()->html('<b>Hi</b>');
    }
}
