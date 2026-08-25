<?php

declare(strict_types=1);

namespace Pulsenote\Tests\Laravel;

use Pulsenote\Laravel\PulsenoteTransport;
use Pulsenote\Tests\Support\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

/**
 * The transport is exercised directly rather than through a booted Laravel app —
 * Symfony's Mailer contracts stand alone, and `send()` is what Laravel calls.
 */
final class PulsenoteTransportTest extends TestCase
{
    private function transport(): PulsenoteTransport
    {
        return new PulsenoteTransport($this->client());
    }

    private function queueAccepted(): void
    {
        $this->http->push(202, ['id' => 'abc', 'status' => 'QUEUED', 'from' => 'noreply@acme.com']);
    }

    private function email(): Email
    {
        return (new Email())
            ->from('noreply@acme.com')
            ->to('greg@example.com')
            ->subject('Welcome')
            ->html('<b>Hi</b>')
            ->text('Hi');
    }

    public function testItIsRegisteredUnderThePulsenoteDsnName(): void
    {
        self::assertSame('pulsenote', (string) $this->transport());
    }

    public function testSendsASingleRecipientThroughTheSendEndpoint(): void
    {
        $this->queueAccepted();

        $this->transport()->send($this->email());

        $request = $this->http->lastRequest();
        self::assertSame('/api/v1/notifications/send', $request->getUri()->getPath());
        self::assertSame([
            'to' => 'greg@example.com',
            'subject' => 'Welcome',
            'from' => 'noreply@acme.com',
            'html' => '<b>Hi</b>',
            'text' => 'Hi',
        ], $this->http->lastBody());
    }

    public function testFansSeveralRecipientsOutThroughTheBatchEndpoint(): void
    {
        $this->http->push(202, [
            'total' => 2,
            'queued' => 2,
            'rejected' => 0,
            'results' => [
                ['index' => 0, 'status' => 'queued', 'id' => 'a'],
                ['index' => 1, 'status' => 'queued', 'id' => 'b'],
            ],
        ]);

        $this->transport()->send($this->email()->to('a@example.com', 'b@example.com'));

        $request = $this->http->lastRequest();
        self::assertSame('/api/v1/notifications/batch', $request->getUri()->getPath());

        $body = $this->http->lastBody();
        self::assertIsArray($body['messages'] ?? null);
        self::assertCount(2, $body['messages']);
        self::assertSame('a@example.com', $body['messages'][0]['to']);
        self::assertSame('b@example.com', $body['messages'][1]['to']);
    }

    /**
     * Silent divergence between what a Mailable declares and what the recipient gets
     * is the failure mode worth being loud about — a dropped invoice PDF surfaces as
     * a customer complaint weeks later, not as a stack trace.
     *
     * @return iterable<string,array{callable(Email):Email,string}>
     */
    public static function unsupportedFeatures(): iterable
    {
        yield 'cc' => [static fn (Email $e): Email => $e->cc('cc@example.com'), 'cc'];
        yield 'bcc' => [static fn (Email $e): Email => $e->bcc('bcc@example.com'), 'bcc'];
        yield 'replyTo' => [static fn (Email $e): Email => $e->replyTo('reply@example.com'), 'replyTo'];
        yield 'attachment' => [
            static fn (Email $e): Email => $e->attach('invoice bytes', 'invoice.pdf'),
            'attachments',
        ];
    }

    /**
     * @param callable(Email):Email $mutate
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedFeatures')]
    public function testRefusesWhatTheApiCannotRepresent(callable $mutate, string $expected): void
    {
        $email = $mutate($this->email());

        try {
            $this->transport()->send($email);
            self::fail('Expected the transport to refuse ' . $expected);
        } catch (TransportException $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }

        // Nothing may go out — a partial send would be worse than none.
        self::assertSame(0, $this->http->requestCount());
    }

    /**
     * Symfony validates the envelope before a transport ever sees it, so the
     * "no To" guard inside doSend() is unreachable through send(). Pinned here so
     * the guarantee is documented rather than assumed — and so this test starts
     * failing if Symfony ever relaxes it.
     */
    public function testSymfonyRejectsAMessageWithNoRecipientBeforeItReachesUs(): void
    {
        $email = (new Email())->from('noreply@acme.com')->subject('Welcome')->html('<b>Hi</b>');

        $this->expectException(\Symfony\Component\Mime\Exception\LogicException::class);
        $this->transport()->send($email);

        self::assertSame(0, $this->http->requestCount());
    }
}
