<?php

declare(strict_types=1);

namespace Pulsenote\Enum;

/**
 * Where a destination delivers.
 */
enum DestinationType: string
{
    /** Your own Slack app (bot token and channel) or an incoming-webhook URL. */
    case Slack = 'slack';

    /** A Microsoft Teams Workflows webhook. */
    case Teams = 'teams';

    case Discord = 'discord';

    case GoogleChat = 'google_chat';

    /** An HTTPS endpoint of yours; each request is signed with the destination's secret. */
    case Webhook = 'webhook';
}
