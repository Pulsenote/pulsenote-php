<?php

declare(strict_types=1);

namespace Pulsenote\Tests\Laravel;

use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Config\Repository;
use Illuminate\Notifications\ChannelManager;
use PHPUnit\Framework\TestCase;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Laravel\PulsenoteChannel;
use Pulsenote\Laravel\PulsenoteChatMessage;
use Pulsenote\Laravel\PulsenoteServiceProvider;
use Pulsenote\Pulsenote;
use Pulsenote\Tests\Support\FakeApplication;
use Pulsenote\Tests\Support\MockHttpClient;

/**
 * Container wiring, checked against {@see FakeApplication} rather than a booted app —
 * enough to catch a broken binding without pulling in Testbench.
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
    public function testTheChannelGetsTheConfiguredChatDestination(mixed $configured, ?string $expected): void
    {
        $app = $this->boot(['api_key' => 'pk_test_abc', 'chat_destination' => $configured]);

        $http = new MockHttpClient();
        $factory = new HttpFactory();
        $app->instance(Pulsenote::class, new Pulsenote(
            apiKey: 'pk_test_abc',
            httpClient: $http,
            requestFactory: $factory,
            streamFactory: $factory,
        ));

        // Resolved the way Laravel does it: Illuminate's NotificationServiceProvider binds
        // the manager like this, and ours extends it as it resolves.
        $app->singleton(ChannelManager::class, static fn (FakeApplication $app): ChannelManager => new ChannelManager($app));
        $channel = $app->make(ChannelManager::class)->driver('pulsenote');
        self::assertInstanceOf(PulsenoteChannel::class, $channel);

        $notification = new class () extends \Illuminate\Notifications\Notification {
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
}
