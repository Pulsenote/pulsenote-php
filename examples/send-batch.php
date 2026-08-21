<?php

declare(strict_types=1);

/*
 * Batch send example — many messages in one call, with per-message results.
 *
 *   PULSENOTE_API_KEY=pk_live_... php examples/send-batch.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Pulsenote\Exception\ApiException;
use Pulsenote\Exception\RateLimitException;
use Pulsenote\Model\BatchMessage;
use Pulsenote\Pulsenote;
use Pulsenote\Resource\Notifications;

$pulsenote = Pulsenote::fromEnvironment();

$recipients = ['greg@example.com', 'ada@example.com', 'nobody@unverified.invalid'];

$messages = array_map(
    static fn (string $to): BatchMessage => new BatchMessage(
        to: $to,
        subject: 'Hello from Pulsenote',
        html: '<h1>Hi</h1><p>Sent as part of a batch.</p>',
    ),
    $recipients,
);

try {
    // Batches larger than the cap have to be chunked — the API takes 500 at a time.
    foreach (array_chunk($messages, Notifications::MAX_BATCH) as $chunk) {
        $batch = $pulsenote->notifications->sendBatch($chunk);

        printf("%d/%d queued, %d rejected\n", $batch->queued, $batch->total, $batch->rejected);

        // A partly-failed batch still returns 202, so inspect it rather than assume success.
        foreach ($batch->rejections() as $failed) {
            printf("  rejected #%d (%s): %s\n", $failed->index, $recipients[$failed->index], $failed->error);
        }

        foreach ($batch->queuedIds() as $id) {
            printf("  queued %s\n", $id);
        }
    }
} catch (RateLimitException $e) {
    printf("Rate limited — retry in %ds.\n", $e->retryAfter ?? 60);
    exit(1);
} catch (ApiException $e) {
    printf("API error %d: %s\n", $e->status, $e->apiMessage() ?? '(no detail)');
    exit(1);
}
