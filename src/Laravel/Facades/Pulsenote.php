<?php

declare(strict_types=1);

namespace Pulsenote\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Pulsenote\Pulsenote as PulsenoteClient;

/**
 * Facade over the container-bound {@see PulsenoteClient}.
 *
 * ```php
 * use Pulsenote\Laravel\Facades\Pulsenote;
 *
 * Pulsenote::notifications()->send(to: 'greg@example.com', subject: 'Hi', html: '<b>Hi</b>');
 * ```
 *
 * The resource groups are readonly *properties* on the client, so the facade exposes
 * them as the methods below rather than through `__get`.
 *
 * @method static \Pulsenote\Resource\Notifications notifications()
 * @method static \Pulsenote\Resource\Templates     templates()
 * @method static \Pulsenote\Resource\Domains       domains()
 *
 * @see PulsenoteClient
 */
final class Pulsenote extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PulsenoteClient::class;
    }

    /**
     * Facade calls land here because the groups are properties, not methods.
     *
     * @param string     $method
     * @param array<mixed> $args
     */
    public static function __callStatic($method, $args): mixed
    {
        $client = static::getFacadeRoot();

        if ($client instanceof PulsenoteClient && $args === [] && property_exists($client, $method)) {
            return $client->{$method};
        }

        return parent::__callStatic($method, $args);
    }
}
