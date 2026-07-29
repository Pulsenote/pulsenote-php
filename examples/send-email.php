<?php

declare(strict_types=1);

/*
 * Minimal send + list example.
 *
 *   PULSENOTE_API_KEY=pk_live_... php examples/send-email.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Pulsenote\Exception\ApiException;
use Pulsenote\Exception\RateLimitException;
use Pulsenote\Pulsenote;

$pulsenote = Pulsenote::fromEnvironment();

try {
    $res = $pulsenote->notifications->send(
        to: 'greg@example.com',
        subject: 'Hello from Pulsenote',
        html: '<h1>Hi</h1><p>Sent via the Pulsenote PHP SDK.</p>',
    );

    printf("Queued %s (%s) from %s\n", $res->id, $res->status->value, $res->from);

    $page = $pulsenote->notifications->list(limit: 5);
    printf("You have %d notifications total.\n", $page->meta->total);

    foreach ($page as $notification) {
        printf("  %s  %-28s %s\n", $notification->id, $notification->recipient, $notification->status->value);
    }
} catch (RateLimitException $e) {
    printf("Rate limited — retry in %ds.\n", $e->retryAfter ?? 60);
    exit(1);
} catch (ApiException $e) {
    printf("API error %d: %s\n", $e->status, $e->apiMessage() ?? '(no detail)');
    exit(1);
}
