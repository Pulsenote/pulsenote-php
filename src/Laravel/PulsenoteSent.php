<?php

declare(strict_types=1);

namespace Pulsenote\Laravel;

use Pulsenote\Model\SendEmailResponse;
use Pulsenote\Model\SendMessageResponse;

/**
 * What {@see PulsenoteChannel::send()} returns when a notification defines both
 * `toPulsenote()` and `toPulsenoteChat()`: one response per message. Laravel hands it
 * to listeners as `NotificationSent::$response`.
 *
 * A notification with only one of the two methods gets that call's own response
 * instead, so the email-only path returns a {@see SendEmailResponse} as before.
 */
final class PulsenoteSent
{
    public function __construct(
        public readonly SendEmailResponse $email,
        public readonly SendMessageResponse $chat,
    ) {
    }
}
