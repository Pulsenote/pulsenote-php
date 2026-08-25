<?php

declare(strict_types=1);

namespace Pulsenote\Mailer;

use Pulsenote\Pulsenote;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Turns a `pulsenote+api://` DSN into a {@see PulsenoteTransport}.
 *
 * ```env
 * MAILER_DSN=pulsenote+api://YOUR_API_KEY@default
 * ```
 *
 * Register it so Symfony Mailer can find it:
 *
 * ```yaml
 * # config/services.yaml
 * services:
 *     Pulsenote\Mailer\PulsenoteTransportFactory:
 *         tags: ['mailer.transport_factory']
 * ```
 *
 * The API key is the DSN *user*, not the password: Symfony redacts the password in
 * `debug:config` output but not the host, and putting a credential where it will be
 * echoed back in logs is how keys leak. `@default` is a placeholder host — pass a
 * real one only to point at a non-production API.
 */
final class PulsenoteTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) {
            throw new UnsupportedSchemeException($dsn, 'pulsenote', $this->getSupportedSchemes());
        }

        $apiKey = $this->getUser($dsn);

        $host = $dsn->getHost();
        $baseUrl = ($host === '' || $host === 'default')
            ? null
            : sprintf('%s://%s%s', $dsn->getOption('scheme', 'https'), $host, $dsn->getPort() !== null ? ':' . $dsn->getPort() : '');

        return new PulsenoteTransport(
            new Pulsenote(apiKey: $apiKey, baseUrl: $baseUrl),
            $this->dispatcher,
            $this->logger,
        );
    }

    /**
     * @return list<string>
     */
    protected function getSupportedSchemes(): array
    {
        return ['pulsenote', 'pulsenote+api'];
    }
}
