<?php

declare(strict_types=1);

namespace Pulsenote\Tests\Mailer;

use Pulsenote\Mailer\PulsenoteTransport;
use Pulsenote\Mailer\PulsenoteTransportFactory;
use Pulsenote\Tests\Support\TestCase;
use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;

/**
 * The factory is what makes `MAILER_DSN=pulsenote+api://…` work at all, so these
 * pin the DSN contract rather than the sending behaviour, which is covered by
 * PulsenoteTransportTest.
 */
final class PulsenoteTransportFactoryTest extends TestCase
{
    private function factory(): PulsenoteTransportFactory
    {
        return new PulsenoteTransportFactory();
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function supportedSchemes(): iterable
    {
        yield 'pulsenote' => ['pulsenote'];
        yield 'pulsenote+api' => ['pulsenote+api'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('supportedSchemes')]
    public function testCreatesATransportForItsSchemes(string $scheme): void
    {
        $transport = $this->factory()->create(new Dsn($scheme, 'default', 'pk_live_key'));

        self::assertInstanceOf(PulsenoteTransport::class, $transport);
        self::assertSame('pulsenote', (string) $transport);
    }

    public function testRejectsAnotherProvidersScheme(): void
    {
        $this->expectException(UnsupportedSchemeException::class);
        $this->factory()->create(new Dsn('ses+api', 'default', 'key'));
    }

    /**
     * The API key belongs in the DSN *user*: Symfony redacts the password in
     * `debug:config`, so a key placed there would be echoed back in logs.
     */
    public function testRequiresAnApiKey(): void
    {
        $this->expectException(IncompleteDsnException::class);
        $this->factory()->create(new Dsn('pulsenote+api', 'default'));
    }

    /**
     * The old name shipped in 1.0.0, so it has to keep working. Asserting the class
     * relationship would be a tautology PHPStan proves on its own — this exercises the
     * behaviour instead, and stops compiling at all if the class is removed.
     */
    public function testTheDeprecatedLaravelClassStillBehavesAsATransport(): void
    {
        $transport = new \Pulsenote\Laravel\PulsenoteTransport($this->client());

        self::assertSame('pulsenote', (string) $transport);
    }
}
