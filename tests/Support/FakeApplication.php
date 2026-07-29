<?php

declare(strict_types=1);

namespace Pulsenote\Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\ServiceProvider;

/**
 * The smallest thing that satisfies `Illuminate\Contracts\Foundation\Application`.
 *
 * Testing the service provider needs an *Application*, not just a container, and
 * pulling in `laravel/framework` or Testbench to get one would triple this package's
 * dev dependencies for ~40 lines of wiring. Everything below the first two methods is
 * inert — the provider only ever touches `configPath()` and `runningInConsole()`.
 */
final class FakeApplication extends Container implements Application
{
    public bool $console = true;

    public function configPath($path = ''): string
    {
        return '/app/config/' . ltrim((string) $path, '/');
    }

    public function runningInConsole(): bool
    {
        return $this->console;
    }

    public function version(): string
    {
        return '12.0.0-fake';
    }

    public function basePath($path = ''): string
    {
        return '/app/' . ltrim((string) $path, '/');
    }

    public function bootstrapPath($path = ''): string
    {
        return $this->basePath('bootstrap/' . ltrim((string) $path, '/'));
    }

    public function databasePath($path = ''): string
    {
        return $this->basePath('database/' . ltrim((string) $path, '/'));
    }

    public function langPath($path = ''): string
    {
        return $this->basePath('lang/' . ltrim((string) $path, '/'));
    }

    public function publicPath($path = ''): string
    {
        return $this->basePath('public/' . ltrim((string) $path, '/'));
    }

    public function resourcePath($path = ''): string
    {
        return $this->basePath('resources/' . ltrim((string) $path, '/'));
    }

    public function storagePath($path = ''): string
    {
        return $this->basePath('storage/' . ltrim((string) $path, '/'));
    }

    /**
     * @param string ...$environments
     */
    public function environment(...$environments): string|bool
    {
        return $environments === [] ? 'testing' : in_array('testing', $environments, true);
    }

    public function runningUnitTests(): bool
    {
        return true;
    }

    public function hasDebugModeEnabled(): bool
    {
        return false;
    }

    public function maintenanceMode(): MaintenanceMode
    {
        throw new \BadMethodCallException('FakeApplication does not implement maintenance mode.');
    }

    public function isDownForMaintenance(): bool
    {
        return false;
    }

    public function registerConfiguredProviders(): void
    {
    }

    public function register($provider, $force = false): ServiceProvider
    {
        throw new \BadMethodCallException('FakeApplication does not register providers; call them directly.');
    }

    public function registerDeferredProvider($provider, $service = null): void
    {
    }

    public function resolveProvider($provider): ServiceProvider
    {
        throw new \BadMethodCallException('FakeApplication does not resolve providers; construct them directly.');
    }

    public function boot(): void
    {
    }

    public function booting($callback): void
    {
    }

    public function booted($callback): void
    {
    }

    /**
     * @param list<class-string> $bootstrappers
     */
    public function bootstrapWith(array $bootstrappers): void
    {
    }

    public function getLocale(): string
    {
        return 'en';
    }

    public function getNamespace(): string
    {
        return 'App\\';
    }

    /**
     * @return array<int,ServiceProvider>
     */
    public function getProviders($provider): array
    {
        return [];
    }

    public function hasBeenBootstrapped(): bool
    {
        return true;
    }

    public function loadDeferredProviders(): void
    {
    }

    public function setLocale($locale): void
    {
    }

    public function shouldSkipMiddleware(): bool
    {
        return false;
    }

    public function terminating($callback): self
    {
        return $this;
    }

    public function terminate(): void
    {
    }
}
