<?php

declare(strict_types=1);

/*
 * Laravel usage — not runnable standalone; these are the shapes you drop into an app.
 *
 * Install:
 *   composer require pulsenote/pulsenote-php
 *   php artisan vendor:publish --tag=pulsenote-config   # optional
 *
 * .env:
 *   PULSENOTE_API_KEY=pk_live_...
 */

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Pulsenote\Laravel\PulsenoteMessage;

/**
 * 1. As a notification channel.
 */
final class OrderShipped extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $trackingCode)
    {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['pulsenote'];
    }

    public function toPulsenote(object $notifiable): PulsenoteMessage
    {
        return PulsenoteMessage::make()
            ->subject('Your order is on its way')
            ->template('order-shipped', locale: $notifiable->locale ?? 'en')
            ->data(['tracking' => $this->trackingCode]);
    }
}

// $user->notify(new OrderShipped('PL123456789'));
//
// The recipient comes from the notifiable's `pulsenote` route, falling back to its
// `mail` route, then to an `email` attribute:
//
//   public function routeNotificationForPulsenote(): string { return $this->work_email; }

/**
 * 2. Injected directly — the container binds the client as a singleton.
 */
final class SendWelcomeEmail
{
    public function __construct(private readonly \Pulsenote\Pulsenote $pulsenote)
    {
    }

    public function handle(string $email, string $name): void
    {
        $this->pulsenote->notifications->send(
            to: $email,
            templateSlug: 'welcome',
            templateData: ['name' => $name],
        );
    }
}

/**
 * 3. Via the facade.
 */
// use Pulsenote\Laravel\Facades\Pulsenote;
//
// Pulsenote::notifications()->send(to: 'greg@example.com', subject: 'Hi', html: '<b>Hi</b>');
// Pulsenote::templates()->list(locale: 'pl');
// Pulsenote::domains()->verify($domainId);
