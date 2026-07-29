<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Internal\Payload;

/**
 * A template with sample data interpolated — a preview, nothing is sent.
 */
final class RenderedTemplate
{
    public function __construct(
        /** Rendered HTML body. */
        public readonly string $html,
        public readonly ?string $subject = null,
        /** Rendered plain-text body. */
        public readonly ?string $text = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            html: Payload::string($data, 'html'),
            subject: Payload::stringOrNull($data, 'subject'),
            text: Payload::stringOrNull($data, 'text'),
        );
    }
}
