<?php

declare(strict_types=1);

namespace Pulsenote\Tests\Laravel;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Bus\Dispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventsDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher as Events;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Testing\Fakes\BusFake;
use PHPUnit\Framework\TestCase;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Exception\ServerException;
use Pulsenote\Laravel\PulsenoteChannel;
use Pulsenote\Laravel\PulsenoteChatChannel;
use Pulsenote\Laravel\PulsenoteChatMessage;
use Pulsenote\Laravel\PulsenoteMessage;
use Pulsenote\Laravel\PulsenoteServiceProvider;
use Pulsenote\Pulsenote;
use Pulsenote\Tests\Support\FakeApplication;
use Pulsenote\Tests\Support\MockHttpClient;

/**
 * Container wiring, checked against {@see FakeApplication} rather than a booted app —
 * enough to catch a broken binding without pulling in Testbench.
 *
 * Also pins the two notification channels side by side through Laravel's own
 * ChannelManager: `pulsenote` (email) and `pulsenote-chat` each resolve, and a
 * notification on both is queued as two jobs, so a failed chat post retries alone.
 */
final class PulsenoteServiceProviderTest extends TestCase
{
    /**
     * @param array<string,mixed> $config
     */
    private function boot(array $config): FakeApplication
    {
        $app = new FakeApplication();
        $app->instance('config', new Repository(['pulsenote' => $config]));

        $provider = new PulsenoteServiceProvider($app);
        $provider->register();
        $provider->boot();

        return $app;
    }

    public function testBindsTheClientAsASingleton(): void
    {
        $app = $this->boot(['api_key' => 'pk_test_abc']);

        $client = $app->make(Pulsenote::class);

        self::assertInstanceOf(Pulsenote::class, $client);
        self::assertSame($client, $app->make(Pulsenote::class), 'The binding should be a singleton.');
        self::assertSame($client, $app->make('pulsenote'), 'The `pulsenote` alias should resolve to the client.');
    }

    public function testPassesTheConfiguredBaseUrlAndHeadersThrough(): void
    {
        $app = $this->boot([
            'api_key' => 'pk_test_abc',
            'base_url' => 'https://staging.example.test',
            'headers' => ['X-Trace-Id' => 'abc-123'],
        ]);

        // A bad base URL or non-string header would throw inside the constructor.
        self::assertInstanceOf(Pulsenote::class, $app->make(Pulsenote::class));
    }

    public function testFailsLoudlyWhenTheApiKeyIsMissing(): void
    {
        $app = $this->boot(['api_key' => null]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('PULSENOTE_API_KEY');

        $app->make(Pulsenote::class);
    }

    public function testRegistersTheConfigForPublishing(): void
    {
        $this->boot(['api_key' => 'pk_test_abc']);

        $groups = PulsenoteServiceProvider::pathsToPublish(PulsenoteServiceProvider::class, 'pulsenote-config');

        self::assertSame(['/app/config/pulsenote.php'], array_values($groups));
    }

    public function testTheClientBindingIsDeclaredAsProvided(): void
    {
        $provider = new PulsenoteServiceProvider(new FakeApplication());

        self::assertSame([Pulsenote::class, 'pulsenote'], $provider->provides());
    }

    public function testMergesTheShippedConfigDefaults(): void
    {
        // `headers` and `base_url` are absent here; the package config supplies them.
        $app = new FakeApplication();
        $app->instance('config', new Repository([]));
        putenv('PULSENOTE_API_KEY=pk_test_merged');

        try {
            (new PulsenoteServiceProvider($app))->register();

            /** @var Repository $config */
            $config = $app->make('config');
            self::assertSame([], $config->get('pulsenote.headers'));
            self::assertInstanceOf(Pulsenote::class, $app->make(Pulsenote::class));
        } finally {
            putenv('PULSENOTE_API_KEY');
        }
    }

    /**
     * A booted provider plus what Laravel's own NotificationServiceProvider would
     * contribute — the channel manager, an event dispatcher and a fake bus — with the
     * client pointed at a mock HTTP client.
     *
     * @param array<string,mixed> $config
     *
     * @return array{0: FakeApplication, 1: MockHttpClient, 2: BusFake}
     */
    private function bootForNotifications(array $config): array
    {
        $app = $this->boot($config);

        $http = new MockHttpClient();
        $factory = new HttpFactory();
        $app->instance(Pulsenote::class, new Pulsenote(
            apiKey: 'pk_test_abc',
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        ));

        $bus = new BusFake(new Dispatcher($app));
        $app->instance(BusDispatcher::class, $bus);
        $app->instance(EventsDispatcher::class, new Events($app));
        $app->singleton(ChannelManager::class, static fn (FakeApplication $app): ChannelManager => new ChannelManager($app));

        return [$app, $http, $bus];
    }

    public function testRegistersBothNotificationChannels(): void
    {
        [$app] = $this->bootForNotifications(['api_key' => 'pk_test_abc']);

        $manager = $app->make(ChannelManager::class);

        self::assertInstanceOf(PulsenoteChannel::class, $manager->driver('pulsenote'));
        self::assertInstanceOf(PulsenoteChatChannel::class, $manager->driver('pulsenote-chat'));
    }

    /**
     * @return iterable<string,array{0: mixed, 1: string|null}>
     */
    public static function chatDestinationConfig(): iterable
    {
        yield 'set' => ['ops-alerts', 'ops-alerts'];
        yield 'padded' => ['  ops-alerts ', 'ops-alerts'];
        yield 'empty' => ['', null];
        yield 'unset' => [null, null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('chatDestinationConfig')]
    public function testTheChatChannelGetsTheConfiguredDefaultDestination(mixed $configured, ?string $expected): void
    {
        [$app, $http] = $this->bootForNotifications(['api_key' => 'pk_test_abc', 'chat_destination' => $configured]);

        $channel = $app->make(ChannelManager::class)->driver('pulsenote-chat');
        self::assertInstanceOf(PulsenoteChatChannel::class, $channel);

        $notification = new class () extends Notification {
            public function toPulsenoteChat(mixed $notifiable): PulsenoteChatMessage
            {
                return PulsenoteChatMessage::make('Hi');
            }
        };

        if ($expected === null) {
            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessage('PULSENOTE_CHAT_DESTINATION');
        } else {
            $http->push(202, ['id' => 'm-1', 'status' => 'QUEUED', 'channel' => 'slack']);
        }

        $channel->send(new \stdClass(), $notification);

        self::assertSame($expected, json_decode((string) $http->lastRequest()->getBody(), true)['to']);
    }

    public function testANotificationOnBothChannelsSendsTheEmailAndTheChatMessage(): void
    {
        [$app, $http] = $this->bootForNotifications(['api_key' => 'pk_test_abc']);
        $http->push(202, ['id' => 'e-1', 'status' => 'QUEUED', 'from' => 'noreply@acme.com']);
        $http->push(202, ['id' => 'm-1', 'status' => 'QUEUED', 'channel' => 'teams']);

        $app->make(ChannelManager::class)->sendNow(new OrderCustomer(), new OrderPlaced());

        self::assertSame(
            ['/api/v1/notifications/send', '/api/v1/messages'],
            array_map(static fn ($r): string => $r->getUri()->getPath(), $http->requests),
        );
        self::assertSame('customer@example.com', json_decode((string) $http->requests[0]->getBody(), true)['to']);
        self::assertSame('sales-teams', json_decode((string) $http->requests[1]->getBody(), true)['to']);
    }

    public function testQueuedTheTwoChannelsAreSeparateJobsSoAChatRetryNeverResendsTheEmail(): void
    {
        [$app, $http, $bus] = $this->bootForNotifications(['api_key' => 'pk_test_abc']);
        $manager = $app->make(ChannelManager::class);

        $manager->send(new OrderCustomer(), new OrderPlaced());

        // Laravel queues one job per channel.
        $jobs = [];
        foreach ($bus->dispatched(SendQueuedNotifications::class) as $job) {
            self::assertInstanceOf(SendQueuedNotifications::class, $job);
            self::assertIsArray($job->channels);
            self::assertCount(1, $job->channels);
            $jobs[$job->channels[0]] = $job;
        }
        self::assertSame(['pulsenote', 'pulsenote-chat'], array_keys($jobs));
        self::assertSame(0, $http->requestCount(), 'Queueing alone must not send anything.');

        // The email job succeeds; the chat job fails (Teams is down), then succeeds on retry.
        $http->push(202, ['id' => 'e-1', 'status' => 'QUEUED', 'from' => 'noreply@acme.com']);
        $jobs['pulsenote']->handle($manager);

        $http->push(503, ['message' => 'Service unavailable']);
        try {
            $jobs['pulsenote-chat']->handle($manager);
            self::fail('Expected the chat failure to propagate so the job retries.');
        } catch (ServerException) {
        }

        $http->push(202, ['id' => 'm-1', 'status' => 'QUEUED', 'channel' => 'teams']);
        $jobs['pulsenote-chat']->handle($manager);

        self::assertSame(
            ['/api/v1/notifications/send', '/api/v1/messages', '/api/v1/messages'],
            array_map(static fn ($r): string => $r->getUri()->getPath(), $http->requests),
            'The email is sent exactly once, however often the chat job retries.',
        );
    }
}

final class OrderCustomer
{
    public string $email = 'customer@example.com';

    public function routeNotificationFor(string $channel, ?Notification $notification = null): ?string
    {
        return $channel === 'mail' ? $this->email : null;
    }
}

/**
 * The README example: one notification, an email to the customer and a post to Teams,
 * on two channels.
 */
final class OrderPlaced extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['pulsenote', 'pulsenote-chat'];
    }

    public function toPulsenote(mixed $notifiable): PulsenoteMessage
    {
        return PulsenoteMessage::make()->subject('Thanks for your order')->html('<b>Order #1042</b>');
    }

    public function toPulsenoteChat(mixed $notifiable): PulsenoteChatMessage
    {
        return PulsenoteChatMessage::make('Order #1042 — €240.00')->title('New order')->to('sales-teams');
    }
}
