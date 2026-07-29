<?php

declare(strict_types=1);

namespace Pulsenote\Http;

use Pulsenote\Pulsenote;

/**
 * `User-Agent` sent on every request — lets Pulsenote support correlate issues with
 * an SDK and runtime version.
 *
 * @internal
 */
final class UserAgent
{
    private static ?string $cached = null;

    public static function value(): string
    {
        return self::$cached ??= sprintf('pulsenote-php/%s (PHP %s)', Pulsenote::VERSION, \PHP_VERSION);
    }
}
