<?php

declare(strict_types=1);

namespace Pulsenote\Tests;

use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Model\Attachment;
use Pulsenote\Tests\Support\TestCase;

final class AttachmentTest extends TestCase
{
    public function testFromContentsEncodesTheBytes(): void
    {
        $attachment = Attachment::fromContents('notes.txt', 'hello world', 'text/plain');

        self::assertSame('notes.txt', $attachment->filename);
        self::assertSame(base64_encode('hello world'), $attachment->content);
        self::assertSame('text/plain', $attachment->contentType);
        self::assertNull($attachment->contentId);
    }

    public function testFromPathReadsTheFileAndDefaultsItsName(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pulsenote-') . '.txt';
        file_put_contents($path, 'invoice bytes');

        try {
            $attachment = Attachment::fromPath($path);

            self::assertSame(basename($path), $attachment->filename);
            self::assertSame('invoice bytes', base64_decode($attachment->content, true));
        } finally {
            @unlink($path);
        }
    }

    public function testFromPathFailsLoudlyOnAnUnreadableFile(): void
    {
        // Silence beats an exception nowhere near the cause: a missing invoice
        // must not become an email that quietly ships without it.
        $this->expectException(ConfigurationException::class);

        Attachment::fromPath('/definitely/not/here.pdf');
    }

    public function testSizeReportsTheDecodedLength(): void
    {
        $bytes = random_bytes(1024);

        self::assertSame(1024, Attachment::fromContents('blob.bin', $bytes)->size());
    }

    public function testPayloadDropsUnsetOptions(): void
    {
        $payload = Attachment::fromContents('notes.txt', 'hi')->toPayload();

        self::assertSame(['filename', 'content'], array_keys($payload));
    }

    public function testPayloadKeepsTheContentIdForInlineFiles(): void
    {
        $payload = Attachment::fromContents('logo.png', 'png', 'image/png', 'logo')->toPayload();

        self::assertSame('logo', $payload['contentId']);
    }
}
