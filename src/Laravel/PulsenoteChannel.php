<?php

declare(strict_types=1);

namespace Pulsenote\Laravel;

use Illuminate\Notifications\Notification;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Model\SendEmailResponse;
use Pulsenote\Model\SendMessageResponse;
use Pulsenote\Pulsenote;

/**
 * Laravel notification channel. Put `'pulsenote'` in your notification's `via()` and
 * give it a `toPulsenote()` returning a {@see PulsenoteMessage} (email), a
 * `toPulsenoteChat()` returning a {@see PulsenoteChatMessage} (Slack, Teams, Discord,
 * Google Chat or a webhook), or both — then both are sent.
 *
 * ```php
 * public function via(object $notifiable): array { return ['pulsenote']; }
 *
 * public function toPulsenote(object $notifiable): PulsenoteMessage
 * {
 *     return PulsenoteMessage::make()->subject('Welcome')->html('<b>Hi</b>');
 * }
 *
 * public function toPulsenoteChat(object $notifiable): PulsenoteChatMessage
 * {
 *     return PulsenoteChatMessage::make('New sign-up')->to('sales-teams');
 * }
 * ```
 *
 * Both messages are built and addressed before anything is sent, so a missing
 * recipient or destination fails without half the notification going out. Then the
 * email goes first and the chat message second.
 *
 * Failures propagate as {@see \Pulsenote\Exception\PulsenoteException} subclasses, so a
 * queued notification fails (and retries) on the usual Laravel path. When both are
 * defined and the chat message fails after the email was accepted, a retry sends the
 * email again — the API has no idempotency key to prevent that.
 */
final class PulsenoteChannel
{
    /**
     * @param string|null $defaultChatDestination Used when neither the chat message nor
     *                                            the notifiable names a destination —
     *                                            `pulsenote.chat_destination` in config.
     */
    public function __construct(
        private readonly Pulsenote $pulsenote,
        private readonly ?string $defaultChatDestination = null,
    ) {
    }

    /**
     * @return SendEmailResponse|SendMessageResponse|PulsenoteSent The email response for
     *         an email-only notification (as before), the message response for a
     *         chat-only one, and both together when the notification defines both.
     */
    public function send(mixed $notifiable, Notification $notification): SendEmailResponse|SendMessageResponse|PulsenoteSent
    {
        // Build and address everything before sending anything: a mistake in the chat
        // half must not surface only after the email has already gone out.
        $email = null;
        if (method_exists($notification, 'toPulsenote')) {
            $message = $this->checkEmail($notification->toPulsenote($notifiable), $notification);
            $to = $message->to ?? $this->resolveRecipient($notifiable, $notification);
            $email = fn (): SendEmailResponse => $this->sendEmail($message, $to);
        }

        $chat = null;
        if (method_exists($notification, 'toPulsenoteChat')) {
            $chatMessage = $this->checkChat($notification->toPulsenoteChat($notifiable), $notification);
            $text = $this->chatText($chatMessage, $notification);
            $destination = $this->resolveDestination($chatMessage, $notifiable, $notification);
            $chat = fn (): SendMessageResponse => $this->pulsenote->messages->send(
                to: $destination,
                text: $text,
                title: $chatMessage->title,
            );
        }

        if ($email !== null && $chat !== null) {
            // Email first: if it fails, nothing has gone out and a retry starts clean.
            return new PulsenoteSent($email(), $chat());
        }
        if ($email !== null) {
            return $email();
        }
        if ($chat !== null) {
            return $chat();
        }

        throw new ConfigurationException(sprintf(
            'Pulsenote: %s uses the "pulsenote" channel but has no toPulsenote() method (email) '
            . 'or toPulsenoteChat() method (Slack, Teams, Discord, Google Chat, webhook).',
            $notification::class,
        ));
    }

    private function checkEmail(mixed $message, Notification $notification): PulsenoteMessage
    {
        if (!$message instanceof PulsenoteMessage) {
            throw new ConfigurationException(sprintf(
                'Pulsenote: %s::toPulsenote() must return a %s, got %s.',
                $notification::class,
                PulsenoteMessage::class,
                get_debug_type($message),
            ));
        }

        return $message;
    }

    private function sendEmail(PulsenoteMessage $message, string $to): SendEmailResponse
    {
        return $this->pulsenote->notifications->send(
            to: $to,
            subject: $message->subject,
            from: $message->from,
            html: $message->html,
            text: $message->text,
            cc: $message->cc,
            bcc: $message->bcc,
            replyTo: $message->replyTo,
            attachments: $message->attachments,
            templateId: $message->templateId,
            templateSlug: $message->templateSlug,
            locale: $message->locale,
            templateData: $message->templateData,
        );
    }

    private function checkChat(mixed $message, Notification $notification): PulsenoteChatMessage
    {
        if (!$message instanceof PulsenoteChatMessage) {
            throw new ConfigurationException(sprintf(
                'Pulsenote: %s::toPulsenoteChat() must return a %s, got %s.',
                $notification::class,
                PulsenoteChatMessage::class,
                get_debug_type($message),
            ));
        }

        return $message;
    }

    private function chatText(PulsenoteChatMessage $message, Notification $notification): string
    {
        if ($message->text === null || $message->text === '') {
            throw new ConfigurationException(sprintf(
                'Pulsenote: %s::toPulsenoteChat() returned a message without text. '
                . 'Pass it to PulsenoteChatMessage::make() or ->text().',
                $notification::class,
            ));
        }

        return $message->text;
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

        if ($this->defaultChatDestination !== null && $this->defaultChatDestination !== '') {
            return $this->defaultChatDestination;
        }

        throw new ConfigurationException(sprintf(
            'Pulsenote: no destination for %s::toPulsenoteChat(). Set ->to() on the PulsenoteChatMessage, '
            . 'add routeNotificationForPulsenoteChat() to the notifiable, or set PULSENOTE_CHAT_DESTINATION.',
            $notification::class,
        ));
    }

    /**
     * Prefer an explicit `pulsenote` route, fall back to the `mail` route so existing
     * notifiables work unchanged, then to a plain `email` attribute.
     */
    private function resolveRecipient(mixed $notifiable, Notification $notification): string
    {
        if (is_object($notifiable) && method_exists($notifiable, 'routeNotificationFor')) {
            foreach (['pulsenote', 'mail'] as $channel) {
                $route = $notifiable->routeNotificationFor($channel, $notification);

                if (is_string($route) && $route !== '') {
                    return $route;
                }

                // Laravel's mail route may be ['addr' => 'Name'] or a list of addresses.
                if (is_array($route) && $route !== []) {
                    $first = array_key_first($route);

                    if (is_string($first) && str_contains($first, '@')) {
                        return $first;
                    }
                    if (is_string($route[$first])) {
                        return $route[$first];
                    }
                }
            }
        }

        if (is_object($notifiable) && isset($notifiable->email) && is_string($notifiable->email)) {
            return $notifiable->email;
        }

        throw new ConfigurationException(sprintf(
            'Pulsenote: could not work out a recipient for %s. Add routeNotificationFor("pulsenote") to the '
            . 'notifiable, or set ->to() on the PulsenoteMessage.',
            is_object($notifiable) ? $notifiable::class : get_debug_type($notifiable),
        ));
    }
}
