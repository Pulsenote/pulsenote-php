<?php

declare(strict_types=1);

namespace Pulsenote\Laravel;

/**
 * What a notification's `toPulsenoteChat()` returns — a fluent builder over the
 * arguments of {@see \Pulsenote\Resource\Messages::send()}: a message to a
 * registered destination (Slack, Teams, Discord, Google Chat or a webhook).
 *
 * ```php
 * public function toPulsenoteChat(object $notifiable): PulsenoteChatMessage
 * {
 *     return PulsenoteChatMessage::make('Order #1042 — €240.00')
 *         ->title('New order')
 *         ->to('sales-teams');
 * }
 * ```
 *
 * The fields are exactly what `POST /api/v1/messages` accepts: a destination name,
 * the text, and an optional title.
 */
final class PulsenoteChatMessage
{
    /** The destination's name, as registered with `$pulsenote->destinations->create()`. */
    public ?string $to = null;
    public ?string $text = null;
    public ?string $title = null;

    public function __construct(?string $text = null)
    {
        $this->text = $text;
    }

    public static function make(?string $text = null): self
    {
        return new self($text);
    }

    /**
     * The destination to post to, by name. Defaults to the notifiable's
     * `pulsenote-chat` route (`routeNotificationForPulsenoteChat()`), then to
     * `pulsenote.chat_destination` in config.
     */
    public function to(string $destination): self
    {
        $this->to = $destination;

        return $this;
    }

    /**
     * The message body, up to 4000 characters.
     */
    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Optional bold first line, up to 150 characters.
     */
    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }
}
