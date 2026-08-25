<?php

declare(strict_types=1);

namespace Pulsenote\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Mail\MailManager;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\ServiceProvider;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Pulsenote;

/**
 * Laravel integration — registered automatically by package discovery.
 *
 * Binds {@see Pulsenote} as a singleton (inject it anywhere), publishes
 * `config/pulsenote.php`, and registers both the `pulsenote` notification channel
 * and the `pulsenote` mail transport (`MAIL_MAILER=pulsenote`).
 *
 * This class is only loaded inside a Laravel application; nothing else in the SDK
 * touches Illuminate.
 */
final class PulsenoteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'pulsenote');

        $this->app->singleton(Pulsenote::class, static function (Container $app): Pulsenote {
            /** @var Repository $config */
            $config = $app->make('config');

            $apiKey = $config->get('pulsenote.api_key');
            if (!is_string($apiKey) || trim($apiKey) === '') {
                throw new ConfigurationException(
                    'Pulsenote: set PULSENOTE_API_KEY in your environment (or pulsenote.api_key in config).',
                );
            }

            $baseUrl = $config->get('pulsenote.base_url');
            $headers = $config->get('pulsenote.headers');

            return new Pulsenote(
                apiKey: $apiKey,
                baseUrl: is_string($baseUrl) && trim($baseUrl) !== '' ? $baseUrl : null,
                headers: is_array($headers) ? array_map(strval(...), $headers) : [],
            );
        });

        $this->app->alias(Pulsenote::class, 'pulsenote');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::configPath() => $this->app->configPath('pulsenote.php')], 'pulsenote-config');
        }

        // Deferred: only resolves once something actually sends a notification, so
        // apps that don't use the channel never pay for the manager.
        $this->app->resolving(ChannelManager::class, static function (ChannelManager $manager, Container $app): void {
            $manager->extend('pulsenote', static fn (): PulsenoteChannel => $app->make(PulsenoteChannel::class));
        });

        // Same deferral for the mail transport. Registering the driver is free; the
        // Pulsenote client (and therefore the API-key check) is only resolved if the
        // app actually selects this mailer.
        $this->app->resolving(MailManager::class, static function (MailManager $manager, Container $app): void {
            $manager->extend('pulsenote', static fn (): PulsenoteTransport => new PulsenoteTransport(
                $app->make(Pulsenote::class),
            ));
        });
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [Pulsenote::class, 'pulsenote'];
    }

    private static function configPath(): string
    {
        return __DIR__ . '/../../config/pulsenote.php';
    }
}
