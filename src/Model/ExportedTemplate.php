<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Internal\Payload;

/**
 * One template inside an export file.
 *
 * Unlike {@see Template} this carries no `id` and no timestamps: outside the
 * account it came from they mean nothing. Identity here is `slug` + `locale`,
 * which is exactly the unique index the API enforces — and what makes importing
 * the same file twice a no-op rather than a pile of duplicates.
 */
final class ExportedTemplate implements \JsonSerializable
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        /** URL-safe identifier, unique per locale. */
        public readonly string $slug,
        /** Locale of this variant (e.g. `en`, `pl`). */
        public readonly string $locale,
        public readonly string $name,
        /** Template body (HTML). */
        public readonly string $body,
        /** Subject line. Supports template variables. */
        public readonly ?string $subject = null,
        public readonly array $metadata = [],
        public readonly bool $isActive = true,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            slug: Payload::string($data, 'slug'),
            locale: Payload::string($data, 'locale'),
            name: Payload::string($data, 'name'),
            body: Payload::string($data, 'body'),
            subject: Payload::stringOrNull($data, 'subject'),
            metadata: Payload::map($data, 'metadata'),
            isActive: Payload::boolOrDefault($data, 'isActive', true),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'slug' => $this->slug,
            'locale' => $this->locale,
            'name' => $this->name,
            'body' => $this->body,
            'subject' => $this->subject,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
            'isActive' => $this->isActive,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
