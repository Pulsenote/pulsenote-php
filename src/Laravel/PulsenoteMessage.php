<?php

declare(strict_types=1);

namespace Pulsenote\Laravel;

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
