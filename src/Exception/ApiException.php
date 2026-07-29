<?php

declare(strict_types=1);

namespace Pulsenote\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * A non-2xx response from the Pulsenote API.
 *
 * Subclasses cover the statuses worth branching on ({@see AuthenticationException},
 * {@see NotFoundException}, {@see ConflictException}, {@see RateLimitException},
 * {@see ValidationException}, {@see ServerException}); anything else surfaces as this
 * base class.
 */
class ApiException extends PulsenoteException
{
    /**
     * @param int                     $status    HTTP status code.
     * @param array<string,mixed>|null $body     Decoded JSON error body, when the API sent one.
     * @param string|null             $rawBody   The response body verbatim.
     * @param string|null             $requestId Value of the `x-request-id` response header, if present.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?array $body = null,
        public readonly ?string $rawBody = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Build the most specific exception subclass for a response.
     */
    public static function fromResponse(ResponseInterface $response, string $method, string $url): self
    {
        $status = $response->getStatusCode();
        $rawBody = (string) $response->getBody();

        $body = null;
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                /** @var array<string,mixed> $decoded */
                $body = $decoded;
            }
        }

        $requestId = $response->getHeaderLine('x-request-id') ?: null;
        $message = sprintf(
            '%s %s failed with %d %s: %s',
            $method,
            $url,
            $status,
            $response->getReasonPhrase(),
            self::extractMessage($body) ?? ($rawBody === '' ? '(empty body)' : $rawBody),
        );

        return match (true) {
            $status === 401, $status === 403 => new AuthenticationException($message, $status, $body, $rawBody, $requestId),
            $status === 404 => new NotFoundException($message, $status, $body, $rawBody, $requestId),
            $status === 409 => new ConflictException($message, $status, $body, $rawBody, $requestId),
            $status === 422, $status === 400 => new ValidationException($message, $status, $body, $rawBody, $requestId),
            $status === 429 => new RateLimitException(
                $message,
                $status,
                $body,
                $rawBody,
                $requestId,
                self::parseRetryAfter($response->getHeaderLine('retry-after')),
            ),
            $status >= 500 => new ServerException($message, $status, $body, $rawBody, $requestId),
            default => new self($message, $status, $body, $rawBody, $requestId),
        };
    }

    /**
     * The API's own error text, without the HTTP framing this exception's message adds.
     *
     * NestJS sends `message` as either a string or an array of validation strings.
     */
    public function apiMessage(): ?string
    {
        return self::extractMessage($this->body);
    }

    /**
     * @param array<string,mixed>|null $body
     */
    private static function extractMessage(?array $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $message = $body['message'] ?? $body['error'] ?? null;

        if (is_string($message)) {
            return $message;
        }

        if (is_array($message)) {
            $parts = array_filter($message, 'is_string');

            return $parts === [] ? null : implode('; ', $parts);
        }

        return null;
    }

    private static function parseRetryAfter(string $header): ?int
    {
        if ($header === '') {
            return null;
        }

        // Either delta-seconds or an HTTP-date (RFC 9110 §10.2.3).
        if (ctype_digit($header)) {
            return (int) $header;
        }

        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }
}
