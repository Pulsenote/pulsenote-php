<?php

declare(strict_types=1);

namespace Pulsenote\Mailer;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Pulsenote\Model\Attachment;
use Pulsenote\Model\BatchMessage;
use Pulsenote\Pulsenote;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Symfony Mailer transport for Pulsenote.
 *
 * Works anywhere Symfony Mailer does — standalone, in Symfony via
 * {@see PulsenoteTransportFactory} and a `pulsenote+api://` DSN, or in Laravel via
 * `MAIL_MAILER=pulsenote`. This is the difference between "rewrite your sending
 * code" and "change one setting": existing mail, password resets and verification
 * emails route through Pulsenote untouched.
 *
 * ## Multiple recipients
 *
 * Pulsenote models one recipient per message, so an email addressed to several
 * people is fanned out through {@see \Pulsenote\Resource\Notifications::sendBatch()}
 * — one message each. Recipients therefore do NOT see one another in the `To`
 * header. For transactional mail that is usually preferable; if you were relying on
 * a shared `To`, this is a behaviour change worth knowing about.
 *
 * That fan-out shapes how copies are handled: `cc` and `bcc` ride along with the
 * FIRST message only. Repeating them on every message would deliver one copy per
 * `To` recipient, so a two-recipient mail would hit each cc'd address twice.
 * Attachments and `replyTo`, by contrast, belong on every message — each recipient
 * should receive the invoice, and each should be able to reply.
 */
class PulsenoteTransport extends AbstractTransport
{
    /**
     * The dispatcher and logger are optional but worth passing in Symfony: without
     * them the mailer emits no SentMessage/FailedMessage events and logs nothing,
     * so anything built on those hooks silently stops working.
     */
    public function __construct(
        private readonly Pulsenote $pulsenote,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    public function __toString(): string
    {
        return 'pulsenote';
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        // Mailer hands us a Message in practice, but SentMessage is typed to the
        // wider RawMessage. Anything else cannot be inspected, so refuse rather
        // than guess.
        if (!$original instanceof Message) {
            throw new TransportException(
                'Pulsenote: the mail transport needs a structured message; got ' . $original::class . '.',
            );
        }

        $email = MessageConverter::toEmail($original);

        $recipients = array_values(array_map(
            static fn (Address $address): string => $address->getAddress(),
            $email->getTo(),
        ));

        if ($recipients === []) {
            throw new TransportException('Pulsenote: the message has no "To" recipient.');
        }

        $from = $email->getFrom()[0] ?? null;
        $subject = $email->getSubject();
        $html = $this->bodyAsString($email->getHtmlBody());
        $text = $this->bodyAsString($email->getTextBody());

        $cc = $this->addressList($email->getCc());
        $bcc = $this->addressList($email->getBcc());
        $replyTo = $this->addressList($email->getReplyTo());
        $attachments = $this->convertAttachments($email);

        if (count($recipients) === 1) {
            $this->pulsenote->notifications->send(
                to: $recipients[0],
                subject: $subject,
                from: $from?->toString(),
                html: $html,
                text: $text,
                cc: $cc,
                bcc: $bcc,
                replyTo: $replyTo,
                attachments: $attachments,
            );

            return;
        }

        $messages = [];

        foreach ($recipients as $index => $to) {
            // Copies go out once, with the first message — see the class docblock.
            $isFirst = $index === 0;

            $messages[] = new BatchMessage(
                to: $to,
                subject: $subject,
                from: $from?->toString(),
                html: $html,
                text: $text,
                cc: $isFirst ? $cc : null,
                bcc: $isFirst ? $bcc : null,
                replyTo: $replyTo,
                attachments: $attachments,
            );
        }

        $this->pulsenote->notifications->sendBatch($messages);
    }

    /**
     * @param array<Address> $addresses
     *
     * @return list<string>|null Null rather than an empty list, so the field is pruned from the request.
     */
    private function addressList(array $addresses): ?array
    {
        if ($addresses === []) {
            return null;
        }

        return array_values(array_map(
            static fn (Address $address): string => $address->getAddress(),
            $addresses,
        ));
    }

    /**
     * Convert Symfony's MIME parts into Pulsenote attachments.
     *
     * Whether a part is a download or an inline image is carried by its
     * disposition, not by a content id: {@see Email::embed()} marks the part inline
     * but assigns no cid, leaving Symfony to match `cid:<name>` in the HTML against
     * the part's file name at render time. Since we hand the API a body Symfony
     * will never render, that name has to become the content id here — otherwise
     * an embedded logo arrives as an attachment and the `<img>` renders broken.
     *
     * @return list<Attachment>|null
     */
    private function convertAttachments(Email $email): ?array
    {
        $parts = $email->getAttachments();

        if ($parts === []) {
            return null;
        }

        $attachments = [];

        foreach ($parts as $part) {
            $filename = $part->getFilename() ?? 'attachment';
            $isInline = $part->getDisposition() === 'inline';

            $contentId = null;
            if ($isInline) {
                $contentId = $part->hasContentId() ? $part->getContentId() : $filename;
            }

            $attachments[] = Attachment::fromContents(
                filename: $filename,
                contents: $part->getBody(),
                contentType: $part->getMediaType() . '/' . $part->getMediaSubtype(),
                contentId: $contentId,
            );
        }

        return $attachments;
    }

    /**
     * Symfony bodies are a string, a stream resource, or absent — a resource
     * whenever the body was built from a file or a large template render.
     *
     * @param resource|string|null $body
     */
    private function bodyAsString(mixed $body): ?string
    {
        if (is_resource($body)) {
            $contents = stream_get_contents($body);
            $body = $contents === false ? null : $contents;
        }

        return is_string($body) && $body !== '' ? $body : null;
    }
}
