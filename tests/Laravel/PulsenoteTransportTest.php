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

    public function testForwardsCopiesAndReplyTo(): void
    {
        $this->http->push(202, ['id' => 'n1', 'status' => 'QUEUED', 'from' => 'app@example.com']);

        $this->transport()->send(
            $this->email()
                ->cc('cc@example.com')
                ->bcc('bcc@example.com')
                ->replyTo('reply@example.com'),
        );

        $body = $this->http->lastBody();
        self::assertSame(['cc@example.com'], $body['cc']);
        self::assertSame(['bcc@example.com'], $body['bcc']);
        self::assertSame(['reply@example.com'], $body['replyTo']);
    }

    /**
     * The case that made this transport unusable for real applications: a Mailable
     * with an invoice attached. It must arrive, base64-encoded, not be refused.
     */
    public function testForwardsAnAttachment(): void
    {
        $this->http->push(202, ['id' => 'n1', 'status' => 'QUEUED', 'from' => 'app@example.com']);

        $this->transport()->send(
            $this->email()->attach('invoice bytes', 'invoice.pdf', 'application/pdf'),
        );

        $body = $this->http->lastBody();
        self::assertCount(1, $body['attachments']);
        self::assertSame('invoice.pdf', $body['attachments'][0]['filename']);
        self::assertSame('application/pdf', $body['attachments'][0]['contentType']);
        self::assertSame('invoice bytes', base64_decode($body['attachments'][0]['content'], true));
        // Not embedded, so no content id — it is a download, not an inline image.
        self::assertArrayNotHasKey('contentId', $body['attachments'][0]);
    }

    public function testMarksAnEmbeddedFileWithItsContentId(): void
    {
        $this->http->push(202, ['id' => 'n1', 'status' => 'QUEUED', 'from' => 'app@example.com']);

        $this->transport()->send(
            $this->email()->embed('png bytes', 'logo', 'image/png'),
        );

        $body = $this->http->lastBody();
        self::assertSame('logo', $body['attachments'][0]['filename']);
        self::assertNotEmpty($body['attachments'][0]['contentId'] ?? null);
    }

    public function testOmitsCopyFieldsWhenThereAreNone(): void
    {
        $this->http->push(202, ['id' => 'n1', 'status' => 'QUEUED', 'from' => 'app@example.com']);

        $this->transport()->send($this->email());

        $body = $this->http->lastBody();
        self::assertArrayNotHasKey('cc', $body);
        self::assertArrayNotHasKey('bcc', $body);
        self::assertArrayNotHasKey('replyTo', $body);
        self::assertArrayNotHasKey('attachments', $body);
    }

    /**
     * Several `To` recipients fan out to one message each, so copies must not ride
     * along on every one — a cc'd address would otherwise receive N copies of the
     * same mail. Attachments and replyTo do belong on all of them.
     */
    public function testSendsCopiesOnceWhenFanningOutToSeveralRecipients(): void
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

        $this->transport()->send(
            $this->email()
                ->to('a@example.com', 'b@example.com')
                ->cc('cc@example.com')
                ->bcc('bcc@example.com')
                ->replyTo('reply@example.com')
                ->attach('invoice bytes', 'invoice.pdf', 'application/pdf'),
        );

        $messages = $this->http->lastBody()['messages'];

        self::assertSame(['cc@example.com'], $messages[0]['cc']);
        self::assertSame(['bcc@example.com'], $messages[0]['bcc']);
        self::assertArrayNotHasKey('cc', $messages[1]);
        self::assertArrayNotHasKey('bcc', $messages[1]);

        foreach ($messages as $message) {
            self::assertSame(['reply@example.com'], $message['replyTo']);
            self::assertSame('invoice.pdf', $message['attachments'][0]['filename']);
        }
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
