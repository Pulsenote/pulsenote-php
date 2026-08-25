<?php

declare(strict_types=1);

namespace Pulsenote\Laravel;

use Pulsenote\Mailer\PulsenoteTransport as MailerTransport;

/**
 * @deprecated since 1.1.0, use {@see \Pulsenote\Mailer\PulsenoteTransport}.
 *
 * The transport was never Laravel-specific — it is a Symfony Mailer transport, and
 * Laravel simply runs on Symfony Mailer. It moved so a Symfony application can use
 * it without pulling in a Laravel namespace.
 *
 * Kept as a subclass because the old name shipped in 1.0.0; removing it would break
 * anyone who referenced it directly. Behaviour is identical.
 */
final class PulsenoteTransport extends MailerTransport
{
}
