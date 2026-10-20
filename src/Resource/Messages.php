<?php

declare(strict_types=1);

namespace Pulsenote\Resource;

use Pulsenote\Internal\Operation;
use Pulsenote\Model\SendMessageResponse;

/**
 * Messages to a destination — see {@see Destinations}.
 *
 * Reached as `$pulsenote->messages`.
 */
final class Messages extends Resource
{
    /**
     * Queue a message for a registered destination and return at once.
     *
     * ```php
     * $pulsenote->messages->send(to: 'ops-alerts', text: 'Order #1042 — €240.00', title: 'New order');
     * ```
     *
     * @param string      $to    The destination's name.
     * @param string      $text  Up to 4000 characters.
     * @param string|null $title Optional bold first line, up to 150 characters.
     *
     * @throws \Pulsenote\Exception\NotFoundException No destination has that name.
     * @throws \Pulsenote\Exception\ValidationException The destination stopped accepting messages; the message says why.
     */
    #[Operation('sendMessage', 'POST', '/api/v1/messages')]
    public function send(string $to, string $text, ?string $title = null): SendMessageResponse
    {
        return SendMessageResponse::fromArray($this->transport->requestObject(
            'POST',
            '/api/v1/messages',
            body: array_filter(
                ['to' => $to, 'title' => $title, 'text' => $text],
                static fn (?string $v): bool => $v !== null,
            ),
        ));
    }
}
