<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Internal\Payload;

/**
 * What happened to one template in an import.
 *
 * Reported per template rather than as a single count, so a partial import can
 * be explained instead of guessed at — "3 of 5" without saying which three is
 * not an answer anyone can act on.
 */
final class ImportedTemplateResult
{
    public function __construct(
        public readonly string $slug,
        public readonly string $locale,
        /** One of `created`, `updated`, `skipped`. */
        public readonly string $result,
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
            result: Payload::string($data, 'result'),
        );
    }

    public function wasSkipped(): bool
    {
        return $this->result === 'skipped';
    }
}
