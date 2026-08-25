<?php

declare(strict_types=1);

namespace Pulsenote\Laravel;

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
 * Laravel mail transport — `MAIL_MAILER=pulsenote`.
 *
 * This is the difference between "rewrite your sending code" and "change one
 * environment variable": every existing Mailable, password reset and verification
 * email routes through Pulsenote untouched.
 *
 * ## What it deliberately refuses
 *
 * The API accepts `to`, `from`, `subject`, `html`, `text` and template fields —
 * there is no `cc`, `bcc`, `replyTo` or attachment support. Rather than drop those
 * silently, this transport throws. A vanished invoice PDF is a far worse failure
 * than an exception at send time, and silent divergence between what a Mailable
 * declares and what the recipient receives is the kind of bug nobody finds until a
 * customer complains.
 *
 * ## Multiple recipients
 *
 * Pulsenote models one recipient per message, so an email addressed to several
 * people is fanned out through {@see \Pulsenote\Resource\Notifications::sendBatch()}
 * — one message each. Recipients therefore do NOT see one another in the `To`
 * header. For transactional mail that is usually preferable; if you were relying on
 * a shared `To`, this is a behaviour change worth knowing about.
 */
final class PulsenoteTransport extends AbstractTransport
{
    public function __construct(private readonly Pulsenote $pulsenote)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'pulsenote';
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        // Laravel always hands us a Message, but SentMessage is typed to the wider
        // RawMessage. Anything else cannot be inspected, so refuse rather than guess.
        if (!$original instanceof Message) {
            throw new TransportException(
                'Pulsenote: the mail transport needs a structured message; got ' . $original::class . '.',
            );
        }

        $email = MessageConverter::toEmail($original);

        $this->rejectUnsupported($email);

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

        if (count($recipients) === 1) {
            $this->pulsenote->notifications->send(
                to: $recipients[0],
                subject: $subject,
                from: $from?->toString(),
                html: $html,
                text: $text,
            );

            return;
        }

        $this->pulsenote->notifications->sendBatch(array_values(array_map(
            static fn (string $to): BatchMessage => new BatchMessage(
                to: $to,
                subject: $subject,
                from: $from?->toString(),
                html: $html,
                text: $text,
            ),
            $recipients,
        )));
    }

    /**
     * Fail loudly on anything the API cannot represent.
     */
    private function rejectUnsupported(Email $email): void
    {
        $unsupported = [];

        if ($email->getCc() !== []) {
            $unsupported[] = 'cc';
        }
        if ($email->getBcc() !== []) {
            $unsupported[] = 'bcc';
        }
        if ($email->getReplyTo() !== []) {
            $unsupported[] = 'replyTo';
        }
        if ($email->getAttachments() !== []) {
            $unsupported[] = 'attachments';
        }

        if ($unsupported === []) {
            return;
        }

        throw new TransportException(sprintf(
            'Pulsenote: the mail transport cannot send %s — the API has no field for %s. '
            . 'Nothing was sent, deliberately: dropping them silently would deliver a message '
            . 'that differs from the one your Mailable declares. Remove them from the Mailable, '
            . 'or use a different mailer for this message.',
            implode(', ', $unsupported),
            count($unsupported) === 1 ? 'it' : 'them',
        ));
    }

    /**
     * Symfony bodies are a string, a stream resource, or absent — Laravel produces
     * a resource whenever the body was built from a file or a large view render.
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
