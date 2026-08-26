<?php

declare(strict_types=1);

namespace Pulsenote\Laravel;

use Pulsenote\Model\Attachment;

/**
 * What a notification's `toPulsenote()` returns — a fluent builder over the arguments
 * of {@see \Pulsenote\Resource\Notifications::send()}.
 *
 * ```php
 * public function toPulsenote(object $notifiable): PulsenoteMessage
 * {
 *     return PulsenoteMessage::make()
 *         ->subject('Welcome')
 *         ->template('welcome', locale: 'pl')
 *         ->data(['name' => $notifiable->name]);
 * }
 * ```
 */
final class PulsenoteMessage
{
    public ?string $to = null;
    public ?string $subject = null;
    public ?string $from = null;
    public ?string $html = null;
    public ?string $text = null;
    public ?string $templateId = null;
    public ?string $templateSlug = null;
    public ?string $locale = null;

    /** @var list<string>|null */
    public ?array $cc = null;

    /** @var list<string>|null */
    public ?array $bcc = null;

    /** @var list<string>|null */
    public ?array $replyTo = null;

    /** @var list<Attachment>|null */
    public ?array $attachments = null;

    /** @var array<string,mixed>|null */
    public ?array $templateData = null;

    public static function make(): self
    {
        return new self();
    }

    /**
     * Override the recipient. Defaults to the notifiable's `pulsenote` (or `mail`)
     * notification route.
     */
    public function to(string $email): self
    {
        $this->to = $email;

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Sender on a verified domain. Defaults to the tenant default sender.
     */
    public function from(string $email): self
    {
        $this->from = $email;

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Carbon-copy recipients, visible to everyone on the message.
     *
     * Repeated calls add to the list rather than replacing it, so building the
     * recipients up conditionally reads naturally.
     */
    public function cc(string ...$email): self
    {
        $this->cc = array_values([...($this->cc ?? []), ...$email]);

        return $this;
    }

    /**
     * Blind-carbon-copy recipients, hidden from the other recipients.
     */
    public function bcc(string ...$email): self
    {
        $this->bcc = array_values([...($this->bcc ?? []), ...$email]);

        return $this;
    }

    /**
     * Where replies should go, when that differs from the sender. Unlike `from`,
     * these addresses need no verified domain.
     */
    public function replyTo(string ...$email): self
    {
        $this->replyTo = array_values([...($this->replyTo ?? []), ...$email]);

        return $this;
    }

    /**
     * Attach a file.
     *
     * ```php
     * ->attach(Attachment::fromPath(storage_path('invoices/2026-08.pdf')))
     * ```
     */
    public function attach(Attachment ...$attachment): self
    {
        $this->attachments = array_values([...($this->attachments ?? []), ...$attachment]);

        return $this;
    }

    /**
     * Send using a stored template, by slug.
     */
    public function template(string $slug, ?string $locale = null): self
    {
        $this->templateSlug = $slug;
        $this->locale = $locale ?? $this->locale;

        return $this;
    }

    /**
     * Send using a stored template, by ID.
     */
    public function templateId(string $id, ?string $locale = null): self
    {
        $this->templateId = $id;
        $this->locale = $locale ?? $this->locale;

        return $this;
    }

    public function locale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Variables interpolated into the template.
     *
     * @param array<string,mixed> $data
     */
    public function data(array $data): self
    {
        $this->templateData = $data;

        return $this;
    }
}
