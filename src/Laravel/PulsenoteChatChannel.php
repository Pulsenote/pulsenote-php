<?php

declare(strict_types=1);

namespace Pulsenote\Laravel;

use Illuminate\Notifications\Notification;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Model\SendMessageResponse;
use Pulsenote\Pulsenote;

/**
 * Laravel notification channel for destinations — Slack, Teams, Discord, Google Chat
 * and webhooks. Put `'pulsenote-chat'` in your notification's `via()` and give it a
 * `toPulsenoteChat()` returning a {@see PulsenoteChatMessage}.
 *
 * ```php
 * public function via(object $notifiable): array { return ['pulsenote', 'pulsenote-chat']; }
 *
 * public function toPulsenoteChat(object $notifiable): PulsenoteChatMessage
 * {
 *     return PulsenoteChatMessage::make('New sign-up')->to('sales-teams');
 * }
 * ```
 *
 * It is a channel of its own, next to the email channel `pulsenote`, so Laravel sends
 * (and, for a queued notification, retries) each one separately: a failed chat post
 * never sends the email again.
 *
 * The destination resolves from the message's `->to()`, then the notifiable's
 * `pulsenote-chat` route, then `pulsenote.chat_destination` in config.
 *
 * Failures propagate as {@see \Pulsenote\Exception\PulsenoteException} subclasses — an
 * unknown destination is a {@see \Pulsenote\Exception\NotFoundException} — so a queued
 * notification fails (and retries) on the usual Laravel path.
 */
final class PulsenoteChatChannel
{
    /**
     * @param string|null $defaultDestination Used when neither the message nor the
     *                                        notifiable names a destination —
     *                                        `pulsenote.chat_destination` in config.
     */
    public function __construct(
        private readonly Pulsenote $pulsenote,
        private readonly ?string $defaultDestination = null,
    ) {
    }

    public function send(mixed $notifiable, Notification $notification): SendMessageResponse
    {
        if (!method_exists($notification, 'toPulsenoteChat')) {
            throw new ConfigurationException(sprintf(
                'Pulsenote: %s uses the "pulsenote-chat" channel but has no toPulsenoteChat() method.',
                $notification::class,
            ));
        }

        $message = $notification->toPulsenoteChat($notifiable);

        if (!$message instanceof PulsenoteChatMessage) {
            throw new ConfigurationException(sprintf(
                'Pulsenote: %s::toPulsenoteChat() must return a %s, got %s.',
                $notification::class,
                PulsenoteChatMessage::class,
                get_debug_type($message),
            ));
        }

        if ($message->text === null || $message->text === '') {
            throw new ConfigurationException(sprintf(
                'Pulsenote: %s::toPulsenoteChat() returned a message without text. '
                . 'Pass it to PulsenoteChatMessage::make() or ->text().',
                $notification::class,
            ));
        }

        return $this->pulsenote->messages->send(
            to: $this->resolveDestination($message, $notifiable, $notification),
            text: $message->text,
            title: $message->title,
        );
    }

    /**
     * The message's own `->to()`, then the notifiable's `pulsenote-chat` route
     * (`routeNotificationForPulsenoteChat()`, or `Notification::route('pulsenote-chat', …)`
     * on demand), then `pulsenote.chat_destination` in config.
     */
    private function resolveDestination(PulsenoteChatMessage $message, mixed $notifiable, Notification $notification): string
    {
        if ($message->to !== null && $message->to !== '') {
            return $message->to;
        }

        if (is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor')) {
            $route = $notifiable->routeNotificationFor('pulsenote-chat', $notification);

            if (is_string($route) && $route !== '') {
                return $route;
            }
        }

        if ($this->defaultDestination !== null && $this->defaultDestination !== '') {
            return $this->defaultDestination;
        }

        throw new ConfigurationException(sprintf(
            'Pulsenote: no destination for %s::toPulsenoteChat(). Set ->to() on the PulsenoteChatMessage, '
            . 'add routeNotificationForPulsenoteChat() to the notifiable, or set PULSENOTE_CHAT_DESTINATION.',
            $notification::class,
        ));
    }
}
