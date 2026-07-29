<?php

declare(strict_types=1);

namespace Pulsenote\Tests\Laravel;

use Illuminate\Notifications\Notification;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Laravel\PulsenoteChannel;
use Pulsenote\Laravel\PulsenoteMessage;
use Pulsenote\Tests\Support\TestCase;

/**
 * The channel is exercised without booting a Laravel app — it only needs a notifiable
 * and an `Illuminate\Notifications\Notification`, both of which stand alone.
 */
final class PulsenoteChannelTest extends TestCase
{
    private function channel(): PulsenoteChannel
    {
        return new PulsenoteChannel($this->client());
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

        self::assertSame('abc', $response->id);
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
