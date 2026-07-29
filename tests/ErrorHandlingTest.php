<?php

declare(strict_types=1);

namespace Pulsenote\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Pulsenote\Exception\ApiException;
use Pulsenote\Exception\AuthenticationException;
use Pulsenote\Exception\ConflictException;
use Pulsenote\Exception\NotFoundException;
use Pulsenote\Exception\PulsenoteException;
use Pulsenote\Exception\RateLimitException;
use Pulsenote\Exception\ServerException;
use Pulsenote\Exception\TransportException;
use Pulsenote\Exception\ValidationException;
use Pulsenote\Tests\Support\TestCase;

final class ErrorHandlingTest extends TestCase
{
    public function testUnauthorized(): void
    {
        $this->http->push(401, ['statusCode' => 401, 'message' => 'Invalid API key', 'error' => 'Unauthorized']);

        try {
            $this->client()->notifications->stats();
            self::fail('Expected AuthenticationException.');
        } catch (AuthenticationException $e) {
            self::assertSame(401, $e->status);
            self::assertSame('Invalid API key', $e->apiMessage());
            self::assertStringContainsString('GET https://', $e->getMessage());
        }
    }

    public function testRateLimitExposesRetryAfter(): void
    {
        $this->http->push(429, ['message' => 'Rate limit exceeded'], ['Retry-After' => '30']);

        try {
            $this->client()->notifications->send(to: 'greg@example.com', html: '<b>Hi</b>');
            self::fail('Expected RateLimitException.');
        } catch (RateLimitException $e) {
            self::assertSame(429, $e->status);
            self::assertSame(30, $e->retryAfter);
        }
    }

    public function testRateLimitWithoutARetryAfterHeader(): void
    {
        $this->http->push(429, ['message' => 'Rate limit exceeded']);

        $this->expectException(RateLimitException::class);

        try {
            $this->client()->notifications->stats();
        } catch (RateLimitException $e) {
            self::assertNull($e->retryAfter);

            throw $e;
        }
    }

    public function testValidationErrorJoinsNestFieldMessages(): void
    {
        $this->http->push(400, [
            'statusCode' => 400,
            'message' => ['to must be an email', 'html should not be empty'],
            'error' => 'Bad Request',
        ]);

        try {
            $this->client()->notifications->send(to: 'nope', html: '');
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('to must be an email; html should not be empty', $e->apiMessage());
        }
    }

    public function testNotFound(): void
    {
        $this->http->push(404, ['message' => 'Notification not found']);

        $this->expectException(NotFoundException::class);

        $this->client()->notifications->get('missing');
    }

    public function testConflict(): void
    {
        $this->http->push(409, ['message' => 'Domain already registered']);

        $this->expectException(ConflictException::class);

        $this->client()->domains->add('mail.example.com');
    }

    public function testServerError(): void
    {
        $this->http->push(503, 'upstream unavailable');

        try {
            $this->client()->domains->list();
            self::fail('Expected ServerException.');
        } catch (ServerException $e) {
            self::assertSame(503, $e->status);
            self::assertNull($e->body);
            self::assertSame('upstream unavailable', $e->rawBody);
        }
    }

    public function testCapturesTheRequestIdHeader(): void
    {
        $this->http->push(500, ['message' => 'boom'], ['x-request-id' => 'req-42']);

        try {
            $this->client()->domains->list();
            self::fail('Expected ApiException.');
        } catch (ApiException $e) {
            self::assertSame('req-42', $e->requestId);
        }
    }

    public function testUnmappedStatusFallsBackToApiException(): void
    {
        $this->http->push(418, ['message' => "I'm a teapot"]);

        try {
            $this->client()->domains->list();
            self::fail('Expected ApiException.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame(418, $e->status);
        }
    }

    public function testConnectionFailureBecomesATransportException(): void
    {
        $this->http->pushFailure(new ConnectException('cURL error 6: could not resolve host', new Request('GET', '/')));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('failed before a response was received');

        $this->client()->domains->list();
    }

    public function testMalformedJsonBecomesATransportException(): void
    {
        $this->http->push(200, '{not json');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('not valid JSON');

        $this->client()->notifications->stats();
    }

    public function testAMissingRequiredFieldIsSurfacedNotSilentlyNulled(): void
    {
        $this->http->push(200, ['id' => 'abc', 'status' => 'QUEUED']); // `from` missing

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('malformed API response');

        $this->client()->notifications->send(to: 'greg@example.com', html: '<b>Hi</b>');
    }

    public function testAnUnknownEnumValuePointsAtAnOutdatedSdk(): void
    {
        $this->http->push(200, ['id' => 'abc', 'status' => 'TELEPORTED', 'from' => 'noreply@sysgp.eu']);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('upgrade pulsenote/pulsenote-php');

        $this->client()->notifications->send(to: 'greg@example.com', html: '<b>Hi</b>');
    }

    public function testEveryFailureSharesOneCatchableBaseType(): void
    {
        $this->http->push(500, ['message' => 'boom']);

        $this->expectException(PulsenoteException::class);

        $this->client()->domains->list();
    }
}
