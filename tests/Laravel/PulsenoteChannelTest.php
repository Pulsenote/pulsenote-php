<?php

declare(strict_types=1);

namespace Pulsenote\Tests\Laravel;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Pulsenote\Enum\DestinationType;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Exception\NotFoundException;
use Pulsenote\Exception\RateLimitException;
use Pulsenote\Exception\ServerException;
use Pulsenote\Laravel\PulsenoteChannel;
use Pulsenote\Laravel\PulsenoteChatMessage;
use Pulsenote\Laravel\PulsenoteMessage;
use Pulsenote\Laravel\PulsenoteSent;
use Pulsenote\Model\SendEmailResponse;
use Pulsenote\Model\SendMessageResponse;
use Pulsenote\Tests\Support\TestCase;

/**
 * Pins the `pulsenote` notification channel: `toPulsenote()` sends email exactly as it
 * always has, `toPulsenoteChat()` posts to a destination, and a notification with both
 * sends both — email first, nothing at all when either half is misconfigured, and every
 * API failure thrown rather than swallowed so queued notifications retry.
 *
 * The channel is exercised without booting a Laravel app — it only needs a notifiable
 * and an `Illuminate\Notifications\Notification`, both of which stand alone.
 */
final class PulsenoteChannelTest extends TestCase
{
    private function channel(?string $defaultChatDestination = null): PulsenoteChannel
    {
        return $defaultChatDestination === null
            ? new PulsenoteChannel($this->client())
            : new PulsenoteChannel($this->client(), $defaultChatDestination);
    }

    private function messageAccepted(string $id = 'msg-1'): void
    {
        $this->http->push(202, ['id' => $id, 'status' => 'QUEUED', 'channel' => 'teams']);
    }

    private function queueAccepted(): void
    {
        $this->http->push(202, ['id' => 'abc', 'status' => 'QUEUED', 'from' => 'noreply@sysgp.eu']);
    }

    public function testSendsUsingTheMailRouteOfTheNotifiable(): void
    {
        $this->queueAccepted();

        $response = $this->channel()->send(
            new NotifiableUser('greg@example.com'),
            new WelcomeNotification(),
        );

        // Unchanged for email-only notifications: the email response, one request.
        self::assertInstanceOf(SendEmailResponse::class, $response);
        self::assertSame('abc', $response->id);
        self::assertSame(1, $this->http->requestCount());
        self::assertSame('/api/v1/notifications/send', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame([
            'to' => 'greg@example.com',
            'subject' => 'Welcome',
            'templateSlug' => 'welcome',
            'locale' => 'pl',
            'templateData' => ['name' => 'Greg'],
        ], $this->http->lastBody());
    }

    public function testAnExplicitRecipientWinsOverTheRoute(): void
    {
        $this->queueAccepted();

        $this->channel()->send(
            new NotifiableUser('route@example.com'),
            new RawNotification(PulsenoteMessage::make()->to('override@example.com')->html('<b>Hi</b>')),
        );

        self::assertSame('override@example.com', $this->http->lastBody()['to']);
    }

    public function testAcceptsAKeyedMailRoute(): void
    {
        $this->queueAccepted();

        // Laravel lets `routeNotificationFor('mail')` return ['addr' => 'Display Name'].
        $this->channel()->send(
            new NotifiableUser(['greg@example.com' => 'Greg']),
            new RawNotification(PulsenoteMessage::make()->html('<b>Hi</b>')),
        );

        self::assertSame('greg@example.com', $this->http->lastBody()['to']);
    }

    public function testFallsBackToAnEmailAttribute(): void
    {
        $this->queueAccepted();

        $this->channel()->send(
            new PlainUser('plain@example.com'),
            new RawNotification(PulsenoteMessage::make()->html('<b>Hi</b>')),
        );

        self::assertSame('plain@example.com', $this->http->lastBody()['to']);
    }

    public function testRejectsANotificationWithoutToPulsenote(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('has no toPulsenote() method');

        $this->channel()->send(new NotifiableUser('greg@example.com'), new Notification());
    }

    public function testRejectsAnUnroutableNotifiable(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('could not work out a recipient');

        $this->channel()->send(new \stdClass(), new RawNotification(PulsenoteMessage::make()->html('<b>Hi</b>')));
    }

    public function testApiFailuresPropagateSoQueuedJobsRetry(): void
    {
        $this->http->push(429, ['message' => 'Rate limit exceeded'], ['Retry-After' => '15']);

        try {
            $this->channel()->send(new NotifiableUser('greg@example.com'), new WelcomeNotification());
            self::fail('Expected the rate-limit error to propagate.');
        } catch (\Pulsenote\Exception\RateLimitException $e) {
            self::assertSame(15, $e->retryAfter);
        }
    }

    // --- Chat: toPulsenoteChat() ---------------------------------------------------

    public function testChatOnlyPostsToTheDestinationAndSendsNoEmail(): void
    {
        $this->messageAccepted();

        $response = $this->channel()->send(
            new NotifiableUser('greg@example.com'),
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
            new NotifiableUser('greg@example.com'),
            new ChatNotification(PulsenoteChatMessage::make()->text('Deploy finished')->to('ops')),
        );

        self::assertSame(['to' => 'ops', 'text' => 'Deploy finished'], $this->http->lastBody());
    }

    public function testANotificationWithBothSendsTheEmailAndThenTheChatMessage(): void
    {
        $this->queueAccepted();
        $this->messageAccepted('msg-9');

        $response = $this->channel()->send(new NotifiableUser('greg@example.com'), new OrderPlaced());

        self::assertInstanceOf(PulsenoteSent::class, $response);
        self::assertSame('abc', $response->email->id);
        self::assertSame('msg-9', $response->chat->id);

        self::assertSame(2, $this->http->requestCount());
        [$first, $second] = $this->http->requests;
        self::assertSame('/api/v1/notifications/send', $first->getUri()->getPath());
        self::assertSame('greg@example.com', json_decode((string) $first->getBody(), true)['to']);
        self::assertSame('/api/v1/messages', $second->getUri()->getPath());
        self::assertSame(
            ['to' => 'sales-teams', 'title' => 'New order', 'text' => 'Order #1042 — €240.00'],
            json_decode((string) $second->getBody(), true),
        );
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

        // NotifiableUser only routes `mail`, so its `pulsenote-chat` route is null.
        $this->channel('from-config')->send(
            new NotifiableUser('greg@example.com'),
            new ChatNotification(PulsenoteChatMessage::make('Hi')),
        );

        self::assertSame('from-config', $this->http->lastBody()['to']);
    }

    public function testNoDestinationAnywhereFailsBeforeAnythingIsSent(): void
    {
        try {
            // Email + chat: the email half is fine, the chat half has nowhere to go.
            $this->channel()->send(new NotifiableUser('greg@example.com'), new OrderPlaced(chatTo: null));
            self::fail('Expected a missing destination to throw.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('no destination', $e->getMessage());
        }

        self::assertSame(0, $this->http->requestCount(), 'The email must not go out when the chat half cannot.');
    }

    public function testAChatMessageWithoutTextIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('without text');

        $this->channel()->send(
            new NotifiableUser('greg@example.com'),
            new ChatNotification(PulsenoteChatMessage::make()->to('ops')),
        );
    }

    public function testToPulsenoteChatMustReturnAChatMessage(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('toPulsenoteChat() must return a ' . PulsenoteChatMessage::class);

        $this->channel()->send(new NotifiableUser('greg@example.com'), new ChatReturningEmail());
    }

    public function testAnUnknownDestinationThrows(): void
    {
        $this->http->push(404, ['statusCode' => 404, 'message' => 'No destination named "nope"']);

        try {
            $this->channel()->send(
                new NotifiableUser('greg@example.com'),
                new ChatNotification(PulsenoteChatMessage::make('Hi')->to('nope')),
            );
            self::fail('Expected the 404 to propagate.');
        } catch (NotFoundException $e) {
            self::assertStringContainsString('No destination named', $e->getMessage());
        }
    }

    public function testAChatApiFailurePropagatesSoQueuedJobsRetry(): void
    {
        $this->http->push(429, ['message' => 'Rate limit exceeded'], ['Retry-After' => '30']);

        try {
            $this->channel()->send(
                new NotifiableUser('greg@example.com'),
                new ChatNotification(PulsenoteChatMessage::make('Hi')->to('ops')),
            );
            self::fail('Expected the rate-limit error to propagate.');
        } catch (RateLimitException $e) {
            self::assertSame(30, $e->retryAfter);
        }
    }

    public function testAnEmailFailureStopsTheChatMessage(): void
    {
        $this->http->push(500, ['message' => 'boom']);

        try {
            $this->channel()->send(new NotifiableUser('greg@example.com'), new OrderPlaced());
            self::fail('Expected the email failure to propagate.');
        } catch (ServerException) {
        }

        self::assertSame(1, $this->http->requestCount(), 'The chat message must not go out after the email failed.');
    }

    public function testAChatFailureAfterTheEmailStillThrows(): void
    {
        $this->queueAccepted();
        $this->http->push(404, ['statusCode' => 404, 'message' => 'No destination named "sales-teams"']);

        $this->expectException(NotFoundException::class);

        $this->channel()->send(new NotifiableUser('greg@example.com'), new OrderPlaced());
    }
}

final class NotifiableUser
{
    /**
     * @param string|array<string,string> $route
     */
    public function __construct(private readonly string|array $route)
    {
    }

    /**
     * @return string|array<string,string>|null
     */
    public function routeNotificationFor(string $channel, ?Notification $notification = null): string|array|null
    {
        return $channel === 'mail' ? $this->route : null;
    }
}

final class PlainUser
{
    public function __construct(public readonly string $email)
    {
    }
}

final class WelcomeNotification extends Notification
{
    public function toPulsenote(mixed $notifiable): PulsenoteMessage
    {
        return PulsenoteMessage::make()
            ->subject('Welcome')
            ->template('welcome', locale: 'pl')
            ->data(['name' => 'Greg']);
    }
}

final class RawNotification extends Notification
{
    public function __construct(private readonly PulsenoteMessage $message)
    {
    }

    public function toPulsenote(mixed $notifiable): PulsenoteMessage
    {
        return $this->message;
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

/**
 * The README example: one notification, an email to the customer and a post to Teams.
 */
final class OrderPlaced extends Notification
{
    public function __construct(private readonly ?string $chatTo = 'sales-teams')
    {
    }

    public function toPulsenote(mixed $notifiable): PulsenoteMessage
    {
        return PulsenoteMessage::make()->subject('Thanks for your order')->html('<b>Order #1042</b>');
    }

    public function toPulsenoteChat(mixed $notifiable): PulsenoteChatMessage
    {
        $message = PulsenoteChatMessage::make('Order #1042 — €240.00')->title('New order');

        return $this->chatTo === null ? $message : $message->to($this->chatTo);
    }
}
