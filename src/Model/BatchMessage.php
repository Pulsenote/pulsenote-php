<?php

declare(strict_types=1);

namespace Pulsenote\Model;

/**
 * One message in a batch send.
 *
 * Mirrors the arguments of {@see \Pulsenote\Resource\Notifications::send()} — a batch is
 * just many of those in one request. Same rule applies to each: supply either a body
 * (`html` and/or `text`) or a template (`templateId` or `templateSlug`).
 *
 * ```php
 * new BatchMessage(to: 'greg@example.com', subject: 'Welcome', html: '<b>Hi</b>');
 * ```
 */
final class BatchMessage
{
    /**
     * @param string                   $to           Recipient email address.
     * @param string|null              $subject      Subject line. Ignored when the template supplies its own.
     * @param string|null              $from         Sender on a verified domain. Defaults to the tenant default sender.
     * @param string|null              $html         Raw HTML body (when not using a template).
     * @param string|null              $text         Plain-text body.
     * @param string|null              $templateId   Send using a stored template by ID.
     * @param string|null              $templateSlug Send using a stored template by slug.
     * @param string|null              $locale       Locale of the template variant to use (e.g. `en`, `pl`).
     * @param array<string,mixed>|null $templateData Variables interpolated into the template.
     */
    public function __construct(
        public readonly string $to,
        public readonly ?string $subject = null,
        public readonly ?string $from = null,
        public readonly ?string $html = null,
        public readonly ?string $text = null,
        public readonly ?string $templateId = null,
        public readonly ?string $templateSlug = null,
        public readonly ?string $locale = null,
        public readonly ?array $templateData = null,
    ) {
    }

    /**
     * The wire representation, with unset options dropped.
     *
     * The transport only prunes nulls at the top level of a body, and these sit nested
     * under `messages`, so each message prunes its own.
     *
     * @internal
     *
     * @return array<string,mixed>
     */
    public function toPayload(): array
    {
        return array_filter([
            'to' => $this->to,
            'subject' => $this->subject,
            'from' => $this->from,
            'html' => $this->html,
            'text' => $this->text,
            'templateId' => $this->templateId,
            'templateSlug' => $this->templateSlug,
            'locale' => $this->locale,
            'templateData' => $this->templateData,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
