<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Internal\Payload;

/**
 * Outcome of an import.
 *
 * Like a batch send, this is **partial-success by design**: the call does not
 * throw when templates are skipped, so a non-throwing `import()` does not mean
 * everything landed. Check {@see $skipped} (or {@see skipped()}) before
 * assuming it did — the default conflict policy skips, so a re-import into an
 * account that already has the templates is skips all the way down, and that
 * is correct rather than a failure.
 *
 * @implements \IteratorAggregate<int,ImportedTemplateResult>
 */
final class TemplateImportResult implements \IteratorAggregate, \Countable
{
    /**
     * @param list<ImportedTemplateResult> $results
     */
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly int $skipped,
        public readonly array $results,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            created: Payload::int($data, 'created'),
            updated: Payload::int($data, 'updated'),
            skipped: Payload::int($data, 'skipped'),
            results: array_map(
                static fn (array $row): ImportedTemplateResult => ImportedTemplateResult::fromArray($row),
                Payload::objectList($data, 'results'),
            ),
        );
    }

    /**
     * Only the templates that were left alone — the ones to look at if you
     * expected the import to change something.
     *
     * @return list<ImportedTemplateResult>
     */
    public function skipped(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (ImportedTemplateResult $r): bool => $r->wasSkipped(),
        ));
    }

    /**
     * @return \ArrayIterator<int,ImportedTemplateResult>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->results);
    }

    public function count(): int
    {
        return count($this->results);
    }
}
