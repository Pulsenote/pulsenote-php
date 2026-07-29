<?php

declare(strict_types=1);

namespace Pulsenote\Tests;

use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Pulsenote;
use Pulsenote\Resource\Domains;
use Pulsenote\Resource\Notifications;
use Pulsenote\Resource\Templates;
use Pulsenote\Tests\Support\TestCase;

final class PulsenoteTest extends TestCase
{
    public function testExposesTheDataPlaneGroups(): void
    {
        $client = $this->client();

        self::assertInstanceOf(Notifications::class, $client->notifications);
        self::assertInstanceOf(Templates::class, $client->templates);
        self::assertInstanceOf(Domains::class, $client->domains);
    }

    public function testRequiresAnApiKey(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('`apiKey` is required');

        new Pulsenote(apiKey: '   ');
    }

    public function testSendsTheApiKeyAndUserAgentOnEveryRequest(): void
    {
        $this->http->push(200, []);
        $this->client()->domains->list();

        $request = $this->http->lastRequest();
        self::assertSame('pk_test_abc', $request->getHeaderLine('X-API-Key'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertStringStartsWith('pulsenote-php/' . Pulsenote::VERSION, $request->getHeaderLine('User-Agent'));
    }

    public function testDefaultsToTheProductionBaseUrl(): void
    {
        $this->http->push(200, []);
        $this->client()->domains->list();

        self::assertSame(
            Pulsenote::DEFAULT_BASE_URL . '/api/v1/domains',
            (string) $this->http->lastRequest()->getUri(),
        );
    }

    public function testHonoursACustomBaseUrlAndTrimsTheTrailingSlash(): void
    {
        $this->http->push(200, []);
        $this->client(baseUrl: 'https://staging.example.test/')->domains->list();

        self::assertSame(
            'https://staging.example.test/api/v1/domains',
            (string) $this->http->lastRequest()->getUri(),
        );
    }

    public function testRejectsANonAbsoluteBaseUrl(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('absolute http(s) URL');

        $this->client(baseUrl: '/api');
    }

    public function testMergesExtraHeaders(): void
    {
        $this->http->push(200, []);
        $this->client(headers: ['X-Trace-Id' => 'abc-123'])->domains->list();

        self::assertSame('abc-123', $this->http->lastRequest()->getHeaderLine('X-Trace-Id'));
    }

    public function testFromEnvironmentRejectsAMissingKey(): void
    {
        putenv('PULSENOTE_API_KEY');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('PULSENOTE_API_KEY');

        Pulsenote::fromEnvironment();
    }

    public function testFromEnvironmentReadsTheKeyAndBaseUrl(): void
    {
        putenv('PULSENOTE_API_KEY=pk_test_env');
        putenv('PULSENOTE_BASE_URL=https://env.example.test');

        try {
            // Constructing is the assertion: it validates both values and would throw.
            $client = Pulsenote::fromEnvironment();
            self::assertInstanceOf(Pulsenote::class, $client);
        } finally {
            putenv('PULSENOTE_API_KEY');
            putenv('PULSENOTE_BASE_URL');
        }
    }
}
