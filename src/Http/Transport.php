<?php

declare(strict_types=1);

namespace Pulsenote\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Pulsenote\Exception\ApiException;
use Pulsenote\Exception\TransportException;

/**
 * Signs, sends, and decodes every request the SDK makes.
 *
 * Resource classes talk to this; nothing else in the SDK touches PSR-18 directly.
 *
 * @internal
 */
final class Transport
{
    /**
     * @param array<string,string> $headers Extra headers sent on every request.
     */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly array $headers = [],
    ) {
    }

    /**
     * Send a request and decode a JSON object response.
     *
     * @param array<string,mixed> $query Query parameters; nulls are dropped.
     * @param array<string,mixed> $body  JSON body; nulls are dropped.
     *
     * @return array<string,mixed>
     */
    public function requestObject(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $decoded = $this->decode($this->send($method, $path, $query, $body), $method, $path);

        if (!is_array($decoded)) {
            throw new TransportException(sprintf(
                'Pulsenote: expected a JSON object from %s %s, got %s.',
                $method,
                $path,
                get_debug_type($decoded),
            ));
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /**
     * Send a request and decode a JSON array-of-objects response.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     *
     * @return list<array<string,mixed>>
     */
    public function requestList(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $decoded = $this->decode($this->send($method, $path, $query, $body), $method, $path);

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new TransportException(sprintf(
                'Pulsenote: expected a JSON array from %s %s, got %s.',
                $method,
                $path,
                get_debug_type($decoded),
            ));
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                throw new TransportException(sprintf(
                    'Pulsenote: expected objects in the array from %s %s, got %s.',
                    $method,
                    $path,
                    get_debug_type($item),
                ));
            }
            /** @var array<string,mixed> $item */
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Send a request and return the raw response body (for non-JSON endpoints).
     *
     * @param array<string,mixed> $query
     */
    public function requestRaw(string $method, string $path, array $query = []): string
    {
        return (string) $this->send($method, $path, $query, null)->getBody();
    }

    /**
     * Send a request and discard the body (204/200-with-no-payload endpoints).
     *
     * @param array<string,mixed> $query
     */
    public function requestVoid(string $method, string $path, array $query = []): void
    {
        $this->send($method, $path, $query, null);
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     */
    private function send(
        string $method,
        string $path,
        array $query,
        ?array $body,
    ): \Psr\Http\Message\ResponseInterface {
        $url = $this->buildUrl($path, $query);

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('X-API-Key', $this->apiKey)
            ->withHeader('Accept', 'application/json')
            ->withHeader('User-Agent', UserAgent::value());

        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $encoded = json_encode(self::withoutNulls($body), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($encoded));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(
                sprintf('Pulsenote: %s %s failed before a response was received: %s', $method, $url, $e->getMessage()),
                0,
                $e,
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw ApiException::fromResponse($response, $method, $url);
        }

        return $response;
    }

    private function decode(\Psr\Http\Message\ResponseInterface $response, string $method, string $path): mixed
    {
        $raw = (string) $response->getBody();
        if (trim($raw) === '') {
            return [];
        }

        try {
            return json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransportException(
                sprintf('Pulsenote: %s %s returned a body that is not valid JSON: %s', $method, $path, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * @param array<string,mixed> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $url = $this->baseUrl . $path;

        $query = self::withoutNulls($query);
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * Drop nulls so optional arguments the caller left unset are simply absent —
     * an explicit `null` would otherwise overwrite a server-side default.
     *
     * @param array<string,mixed> $values
     *
     * @return array<string,mixed>
     */
    private static function withoutNulls(array $values): array
    {
        return array_filter($values, static fn (mixed $v): bool => $v !== null);
    }
}
