<?php

declare(strict_types=1);

namespace Pulsenote;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Http\Transport;
use Pulsenote\Resource\Domains;
use Pulsenote\Resource\Notifications;
use Pulsenote\Resource\Templates;

/**
 * Client for the Pulsenote email API.
 *
 * ```php
 * $pulsenote = new Pulsenote(getenv('PULSENOTE_API_KEY'));
 *
 * $res = $pulsenote->notifications->send(
 *     to: 'greg@example.com',
 *     subject: 'Welcome',
 *     html: '<b>Hello from Pulsenote</b>',
 * );
 *
 * echo $res->id, ' ', $res->status->value; // "<uuid> QUEUED"
 * ```
 *
 * Covers the **data plane** — the endpoints authenticated with an `X-API-Key`
 * (notifications, templates, domains). Account management (team, billing, auth) uses
 * JWT auth and is deliberately out of scope, matching the Node SDK.
 *
 * The HTTP client is any PSR-18 implementation. Leave it unset and one is
 * auto-discovered from your installed packages (Guzzle, Symfony HttpClient, …).
 */
final class Pulsenote
{
    /** SDK version, sent in the `User-Agent`. Keep in step with CHANGELOG.md. */
    public const VERSION = '1.1.0'; // x-release-please-version

    /** Default API base URL. */
    public const DEFAULT_BASE_URL = 'https://pulsenote-api.sysgp.eu';

    /** Sending email and inspecting delivery. */
    public readonly Notifications $notifications;

    /** Stored email templates. */
    public readonly Templates $templates;

    /** Sender domains and their DNS verification. */
    public readonly Domains $domains;

    /**
     * @param string                        $apiKey         Tenant API key (`pk_live_…` / `pk_test_…`), sent as `X-API-Key`.
     * @param string|null                   $baseUrl        Override the API base URL. Defaults to {@see DEFAULT_BASE_URL}.
     * @param array<string,string>          $headers        Extra headers sent on every request.
     * @param ClientInterface|null          $httpClient     PSR-18 client. Auto-discovered when null.
     * @param RequestFactoryInterface|null  $requestFactory PSR-17 request factory. Auto-discovered when null.
     * @param StreamFactoryInterface|null   $streamFactory  PSR-17 stream factory. Auto-discovered when null.
     *
     * @throws ConfigurationException The API key is empty or the base URL is not a valid absolute URL.
     */
    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        array $headers = [],
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        if (trim($apiKey) === '') {
            throw new ConfigurationException('Pulsenote: `apiKey` is required.');
        }

        $transport = new Transport(
            httpClient: $httpClient ?? Psr18ClientDiscovery::find(),
            requestFactory: $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory(),
            streamFactory: $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory(),
            baseUrl: self::normalizeBaseUrl($baseUrl ?? self::DEFAULT_BASE_URL),
            apiKey: $apiKey,
            headers: $headers,
        );

        $this->notifications = new Notifications($transport);
        $this->templates = new Templates($transport);
        $this->domains = new Domains($transport);
    }

    /**
     * Build a client from the `PULSENOTE_API_KEY` (and optional `PULSENOTE_BASE_URL`)
     * environment variables — the usual wiring for a container or CI job.
     *
     * @throws ConfigurationException `PULSENOTE_API_KEY` is unset or empty.
     */
    public static function fromEnvironment(): self
    {
        $apiKey = getenv('PULSENOTE_API_KEY');
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new ConfigurationException('Pulsenote: environment variable PULSENOTE_API_KEY is not set.');
        }

        $baseUrl = getenv('PULSENOTE_BASE_URL');

        return new self(
            apiKey: $apiKey,
            baseUrl: is_string($baseUrl) && trim($baseUrl) !== '' ? $baseUrl : null,
        );
    }

    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');

        $scheme = parse_url($baseUrl, \PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true) || parse_url($baseUrl, \PHP_URL_HOST) === null) {
            throw new ConfigurationException(
                sprintf('Pulsenote: `baseUrl` must be an absolute http(s) URL, got "%s".', $baseUrl),
            );
        }

        return $baseUrl;
    }
}
