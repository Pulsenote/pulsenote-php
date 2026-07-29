<?php

declare(strict_types=1);

/*
 * Publish with:
 *   php artisan vendor:publish --tag=pulsenote-config
 */

return [
    /*
     * Tenant API key (pk_live_… / pk_test_…), sent as the X-API-Key header.
     * Keep it in .env — never commit it.
     */
    'api_key' => env('PULSENOTE_API_KEY'),

    /*
     * Override the API base URL. Leave null for production.
     */
    'base_url' => env('PULSENOTE_BASE_URL'),

    /*
     * Extra headers sent on every request, e.g. ['X-Trace-Id' => '…'].
     *
     * @var array<string,string>
     */
    'headers' => [],
];
