<?php

declare(strict_types=1);

namespace Pulsenote\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that replays queued responses and records what it was asked to send.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param array<string,mixed>|list<mixed>|null $json
     * @param array<string,string>                 $headers
     */
    public function push(int $status = 200, array|string|null $json = null, array $headers = []): self
    {
        $body = is_string($json)
            ? $json
            : ($json === null ? '' : json_encode($json, \JSON_THROW_ON_ERROR));

        if (is_array($json)) {
            $headers += ['Content-Type' => 'application/json'];
        }

        $this->queue[] = new Response($status, $headers, $body);

        return $this;
    }

    public function pushFailure(ClientExceptionInterface $e): self
    {
        $this->queue[] = $e;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException(sprintf(
                'MockHttpClient: no queued response for %s %s',
                $request->getMethod(),
                (string) $request->getUri(),
            ));
        }

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    /**
     * How many requests were actually attempted. Lets a test assert that nothing
     * went out — `lastRequest()` throws in that case, which is awkward to assert on.
     */
    public function requestCount(): int
    {
        return count($this->requests);
    }

    public function lastRequest(): RequestInterface
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('MockHttpClient: no request was made.');
        }

        return $last;
    }

    /**
     * @return array<string,mixed>
     */
    public function lastBody(): array
    {
        $raw = (string) $this->lastRequest()->getBody();
        if ($raw === '') {
            return [];
        }

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
