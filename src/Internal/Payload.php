<?php

declare(strict_types=1);

namespace Pulsenote\Internal;

use Pulsenote\Exception\TransportException;

/**
 * Typed accessors for decoded API payloads.
 *
 * The API is trusted but not assumed: a field that the spec marks required and the
 * response omits is a bug worth surfacing loudly rather than a silent null, so the
 * non-`…OrNull` readers throw {@see TransportException}.
 *
 * @internal
 */
final class Payload
{
    /**
     * @param array<string,mixed> $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw self::missing($key, 'string', $value);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function stringOrNull(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) && !(is_float($value) && floor($value) === $value)) {
            throw self::missing($key, 'number', $value);
        }

        return (int) $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw self::missing($key, 'boolean', $value);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function boolOrDefault(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function dateTime(array $data, string $key): \DateTimeImmutable
    {
        $value = self::dateTimeOrNull($data, $key);
        if ($value === null) {
            throw self::missing($key, 'date-time string', $data[$key] ?? null);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function dateTimeOrNull(array $data, string $key): ?\DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new TransportException(
                sprintf('Pulsenote: field "%s" is not a parsable date-time (%s).', $key, $value),
                0,
                $e,
            );
        }
    }

    /**
     * A nested object, defaulting to an empty array when absent.
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    public static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        /** @var array<string,mixed> $value */
        return $value;
    }

    /**
     * A list of nested objects, defaulting to an empty list when absent.
     *
     * @param array<string,mixed> $data
     *
     * @return list<array<string,mixed>>
     */
    public static function objectList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string,mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<string,mixed>  $data
     * @param class-string<T>      $enum
     *
     * @return T
     */
    public static function enum(array $data, string $key, string $enum): \BackedEnum
    {
        $raw = self::string($data, $key);
        $case = $enum::tryFrom($raw);
        if ($case === null) {
            throw new TransportException(sprintf(
                'Pulsenote: unknown value "%s" for %s. Your SDK version may be older than the API — upgrade pulsenote/pulsenote-php.',
                $raw,
                $enum,
            ));
        }

        return $case;
    }

    private static function missing(string $key, string $expected, mixed $actual): TransportException
    {
        return new TransportException(sprintf(
            'Pulsenote: malformed API response — expected %s at "%s", got %s.',
            $expected,
            $key,
            get_debug_type($actual),
        ));
    }
}
