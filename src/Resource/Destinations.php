<?php

declare(strict_types=1);

namespace Pulsenote\Resource;

use Pulsenote\Enum\DestinationType;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Internal\Operation;
use Pulsenote\Model\Destination;
use Pulsenote\Model\DestinationTestResult;

/**
 * Places other than an inbox: a Slack, Microsoft Teams, Discord or Google Chat
 * channel, or an HTTPS endpoint of yours. Register one once, then send to it
 * by name with {@see Messages::send()}.
 *
 * Messages count towards the same monthly allowance as email and need no
 * sending domain.
 *
 * Reached as `$pulsenote->destinations`.
 */
final class Destinations extends Resource
{
    /**
     * Every destination on this account, oldest first.
     *
     * @return list<Destination>
     */
    #[Operation('listDestinations', 'GET', '/api/v1/destinations')]
    public function list(): array
    {
        return array_map(
            static fn (array $row): Destination => Destination::fromArray($row),
            $this->transport->requestList('GET', '/api/v1/destinations'),
        );
    }

    /**
     * Register a destination.
     *
     * Slack takes either your own Slack app's Bot User OAuth Token (`xoxb-…`)
     * and a channel — checked with Slack before it is saved — or an
     * incoming-webhook URL. Every other type takes the URL its platform gives
     * you. For {@see DestinationType::Webhook} the result carries
     * `signingSecret`, shown this once.
     *
     * ```php
     * $pulsenote->destinations->create(
     *     type: DestinationType::Slack,
     *     name: 'ops-alerts',
     *     token: getenv('SLACK_BOT_TOKEN'),
     *     channel: '#ops-alerts',
     * );
     * ```
     *
     * @throws ConfigurationException Neither a URL nor a token and channel were given, or both.
     * @throws \Pulsenote\Exception\ConflictException A destination with that name already exists.
     * @throws \Pulsenote\Exception\ValidationException The URL, token or channel was not accepted.
     */
    #[Operation('createDestination', 'POST', '/api/v1/destinations')]
    public function create(
        DestinationType $type,
        string $name,
        ?string $url = null,
        ?string $token = null,
        ?string $channel = null,
    ): Destination {
        $viaApp = $token !== null || $channel !== null;
        if ($viaApp && ($type !== DestinationType::Slack || $token === null || $channel === null || $url !== null)) {
            throw new ConfigurationException(
                'Pulsenote: `token` and `channel` go together, for Slack only, and instead of `url`.',
            );
        }
        if (!$viaApp && ($url === null || trim($url) === '')) {
            throw new ConfigurationException('Pulsenote: `url` is required unless Slack is given a `token` and `channel`.');
        }

        return Destination::fromArray($this->transport->requestObject(
            'POST',
            '/api/v1/destinations',
            body: array_filter([
                'type' => $type->value,
                'name' => $name,
                'url' => $url,
                'token' => $token,
                'channel' => $channel,
            ], static fn (?string $v): bool => $v !== null),
        ));
    }

    /**
     * Send a test message right away — outside the queue and the allowance.
     * A success clears a destination that was marked as not delivering.
     */
    #[Operation('testDestination', 'POST', '/api/v1/destinations/{id}/test')]
    public function test(string $id): DestinationTestResult
    {
        return DestinationTestResult::fromArray($this->transport->requestObject(
            'POST',
            $this->path('/api/v1/destinations/{id}/test', ['id' => $id]),
        ));
    }

    /**
     * Remove a destination. Messages already queued for it will fail.
     *
     * @throws \Pulsenote\Exception\NotFoundException No destination with that id belongs to this tenant.
     */
    #[Operation('deleteDestination', 'DELETE', '/api/v1/destinations/{id}')]
    public function delete(string $id): void
    {
        $this->transport->requestVoid(
            'DELETE',
            $this->path('/api/v1/destinations/{id}', ['id' => $id]),
        );
    }
}
