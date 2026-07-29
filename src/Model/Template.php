<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Internal\Payload;

/**
 * A stored email template. One row per (slug, locale) pair — the same `slug` in `en`
 * and `pl` is two templates with two IDs.
 */
final class Template
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        /** URL-safe identifier, unique per tenant + locale. */
        public readonly string $slug,
        /** Locale of this variant (e.g. `en`, `pl`). */
        public readonly string $locale,
        /** Template body (HTML). */
        public readonly string $body,
        public readonly bool $isActive,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        /** Subject line. Supports template variables. */
        public readonly ?string $subject = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            name: Payload::string($data, 'name'),
            slug: Payload::string($data, 'slug'),
            locale: Payload::string($data, 'locale'),
            body: Payload::string($data, 'body'),
            isActive: Payload::bool($data, 'isActive'),
            createdAt: Payload::dateTime($data, 'createdAt'),
            updatedAt: Payload::dateTime($data, 'updatedAt'),
            subject: Payload::stringOrNull($data, 'subject'),
        );
    }
}
