<?php

declare(strict_types=1);

namespace Pulsenote\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Pulsenote\Pulsenote;

abstract class TestCase extends BaseTestCase
{
    protected MockHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new MockHttpClient();
    }

    /**
     * A client wired to {@see $http} — no network, explicit PSR-17 factories so the
     * tests never depend on what discovery happens to find.
     *
     * @param array<string,string> $headers
     */
    protected function client(?string $baseUrl = null, array $headers = []): Pulsenote
    {
        $factory = new HttpFactory();

        return new Pulsenote(
            apiKey: 'pk_test_abc',
            baseUrl: $baseUrl,
            headers: $headers,
            httpClient: $this->http,
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }
}
