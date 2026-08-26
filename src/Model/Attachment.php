<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Exception\ConfigurationException;

/**
 * A file attached to an outgoing email.
 *
 * The API takes attachment contents base64-encoded, but you rarely have base64 to
 * hand — you have bytes, or a path. The named constructors do that encoding for
 * you, so reach for {@see fromContents()} or {@see fromPath()} and let
 * {@see __construct()} alone if you already hold an encoded string.
 *
 * ```php
 * $pulsenote->notifications->send(
 *     to: 'greg@example.com',
 *     subject: 'Your invoice',
 *     html: '<p>Attached.</p>',
 *     attachments: [Attachment::fromPath('/tmp/invoice.pdf')],
 * );
 * ```
 *
 * Inline images work the same way, with a content id the HTML references:
 *
 * ```php
 * Attachment::fromPath('/tmp/logo.png', contentId: 'logo');
 * // ...then in the body: <img src="cid:logo">
 * ```
 */
final class Attachment
{
    /**
     * Combined decoded size the API accepts across all attachments on one message.
     */
    public const MAX_TOTAL_BYTES = 10 * 1024 * 1024;

    /**
     * @param string      $filename    File name shown to the recipient.
     * @param string      $content     Base64-encoded contents.
     * @param string|null $contentType MIME type. The API defaults to `application/octet-stream`.
     * @param string|null $contentId   Set to embed the file in the HTML body, referenced as `cid:<contentId>`.
     */
    public function __construct(
        public readonly string $filename,
        public readonly string $content,
        public readonly ?string $contentType = null,
        public readonly ?string $contentId = null,
    ) {
    }

    /**
     * Build an attachment from raw bytes, encoding them for you.
     */
    public static function fromContents(
        string $filename,
        string $contents,
        ?string $contentType = null,
        ?string $contentId = null,
    ): self {
        return new self($filename, base64_encode($contents), $contentType, $contentId);
    }

    /**
     * Build an attachment by reading a file from disk.
     *
     * The name defaults to the file's own, and the MIME type is detected when the
     * fileinfo extension is available — pass either explicitly to override.
     *
     * @throws ConfigurationException The file cannot be read.
     */
    public static function fromPath(
        string $path,
        ?string $filename = null,
        ?string $contentType = null,
        ?string $contentId = null,
    ): self {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new ConfigurationException(sprintf('Cannot read attachment file "%s".', $path));
        }

        return self::fromContents(
            $filename ?? basename($path),
            $contents,
            $contentType ?? self::detectMimeType($path),
            $contentId,
        );
    }

    /**
     * Decoded size in bytes — what the API measures against its limit.
     */
    public function size(): int
    {
        return strlen(base64_decode($this->content, true) ?: '');
    }

    /**
     * The wire representation, with unset options dropped.
     *
     * @internal
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return array_filter([
            'filename' => $this->filename,
            'content' => $this->content,
            'contentType' => $this->contentType,
            'contentId' => $this->contentId,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Best-effort MIME detection. Returns null when fileinfo is unavailable, in
     * which case the API applies its own default rather than guessing here.
     */
    private static function detectMimeType(string $path): ?string
    {
        if (!function_exists('mime_content_type')) {
            return null;
        }

        $detected = @mime_content_type($path);

        return $detected === false ? null : $detected;
    }
}
