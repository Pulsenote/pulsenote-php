<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Internal\Payload;

/**
 * A portable set of templates — the thing `templates->export()` hands back and
 * `templates->import()` takes.
 *
 * `version` is not decoration. An export file sits on disk for months, and
 * without a version the first change to the format breaks every old file with
 * no way to tell what went wrong.
 *
 * ```php
 * $file = $source->templates->export();
 * $result = $target->templates->import($file->templates);
 * ```
 *
 * @implements \IteratorAggregate<int,ExportedTemplate>
 */
final class TemplateExport implements \IteratorAggregate, \Countable
{
    /**
     * @param list<ExportedTemplate> $templates
     */
    public function __construct(
        /** Format version of this file. */
        public readonly int $version,
        public readonly \DateTimeImmutable $exportedAt,
        public readonly array $templates,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            version: Payload::int($data, 'version'),
            exportedAt: Payload::dateTime($data, 'exportedAt'),
            templates: array_map(
                static fn (array $row): ExportedTemplate => ExportedTemplate::fromArray($row),
                Payload::objectList($data, 'templates'),
            ),
        );
    }

    /**
     * @return \ArrayIterator<int,ExportedTemplate>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->templates);
    }

    public function count(): int
    {
        return count($this->templates);
    }
}
