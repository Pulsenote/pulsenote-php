<?php

declare(strict_types=1);

namespace Pulsenote\Laravel;

use Illuminate\Notifications\Notification;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Model\SendEmailResponse;
use Pulsenote\Pulsenote;

/**
 * Laravel notification channel. Put `'pulsenote'` in your notification's `via()` and
 * give it a `toPulsenote()` returning a {@see PulsenoteMessage}.
 *
 * ```php
 * public function via(object $notifiable): array { return ['pulsenote']; }
 *
 * public function toPulsenote(object $notifiable): PulsenoteMessage
 * {
 *     return PulsenoteMessage::make()->subject('Welcome')->html('<b>Hi</b>');
 * }
 * ```
 *
 * Failures propagate as {@see \Pulsenote\Exception\PulsenoteException} subclasses, so a
 * queued notification fails (and retries) on the usual Laravel path.
 */
final class PulsenoteChannel
{
    public function __construct(private readonly Pulsenote $pulsenote)
    {
    }

    public function send(mixed $notifiable, Notification $notification): SendEmailResponse
    {
        if (!method_exists($notification, 'toPulsenote')) {
            throw new ConfigurationException(sprintf(
                'Pulsenote: %s uses the "pulsenote" channel but has no toPulsenote() method.',
                $notification::class,
            ));
        }

        $message = $notification->toPulsenote($notifiable);

        if (!$message instanceof PulsenoteMessage) {
            throw new ConfigurationException(sprintf(
                'Pulsenote: %s::toPulsenote() must return a %s, got %s.',
                $notification::class,
                PulsenoteMessage::class,
                get_debug_type($message),
            ));
        }

        $to = $message->to ?? $this->resolveRecipient($notifiable, $notification);

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
