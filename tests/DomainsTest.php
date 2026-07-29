<?php

declare(strict_types=1);

namespace Pulsenote\Tests;

use Pulsenote\Enum\DnsRecordType;
use Pulsenote\Enum\DomainStatus;
use Pulsenote\Tests\Support\TestCase;

final class DomainsTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private static function domainPayload(string $status = 'VERIFIED', bool $withRecords = false): array
    {
        $payload = [
            'id' => 'd-1',
            'domain' => 'mail.example.com',
            'status' => $status,
            'spfVerified' => true,
            'dkimVerified' => true,
            'dmarcVerified' => false,
            'fromEmail' => 'noreply@mail.example.com',
            'fromName' => 'Example',
            'isDefault' => true,
            'verifiedAt' => '2026-07-05T12:00:00.000Z',
            'createdAt' => '2026-07-01T12:00:00.000Z',
            'updatedAt' => '2026-07-05T12:00:00.000Z',
        ];

        if ($withRecords) {
            $payload['dnsRecords'] = [
                ['type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 include:amazonses.com ~all', 'purpose' => 'SPF'],
                ['type' => 'MX', 'name' => 'bounce', 'value' => 'feedback-smtp.eu-west-1.amazonses.com', 'purpose' => 'MAIL FROM', 'priority' => 10],
            ];
        }

        return $payload;
    }

    public function testListReturnsTypedDomains(): void
    {
        $this->http->push(200, [self::domainPayload()]);

        $domains = $this->client()->domains->list();

        self::assertSame('/api/v1/domains', $this->http->lastRequest()->getUri()->getPath());
        self::assertCount(1, $domains);
        self::assertSame(DomainStatus::Verified, $domains[0]->status);
        self::assertTrue($domains[0]->isSendable());
        self::assertSame('2026-07-05T12:00:00+00:00', $domains[0]->verifiedAt?->format(\DateTimeInterface::ATOM));
    }

    public function testAddReturnsTheDnsRecordsToPublish(): void
    {
        $this->http->push(201, self::domainPayload(status: 'PENDING', withRecords: true));

        $domain = $this->client()->domains->add('mail.example.com', fromName: 'Example');

        self::assertSame(
            ['domain' => 'mail.example.com', 'fromName' => 'Example'],
            $this->http->lastBody(),
        );

        self::assertSame(DomainStatus::Pending, $domain->status);
        self::assertFalse($domain->isSendable());
        self::assertCount(2, $domain->dnsRecords);
        self::assertSame(DnsRecordType::Txt, $domain->dnsRecords[0]->type);
        self::assertNull($domain->dnsRecords[0]->priority);
        self::assertSame(DnsRecordType::Mx, $domain->dnsRecords[1]->type);
        self::assertSame(10, $domain->dnsRecords[1]->priority);
    }

    public function testDnsRecords(): void
    {
        $this->http->push(200, [
            'domain' => 'mail.example.com',
            'status' => 'VERIFYING',
            'records' => [['type' => 'CNAME', 'name' => 'abc._domainkey', 'value' => 'abc.dkim.amazonses.com', 'purpose' => 'DKIM']],
            'spfVerified' => true,
            'dkimVerified' => false,
            'dmarcVerified' => false,
        ]);

        $records = $this->client()->domains->dnsRecords('d-1');

        self::assertSame('/api/v1/domains/d-1/dns-records', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame(DomainStatus::Verifying, $records->status);
        self::assertCount(1, $records->records);
        self::assertSame(DnsRecordType::Cname, $records->records[0]->type);
        self::assertFalse($records->dkimVerified);
    }

    public function testZoneFileReturnsRawText(): void
    {
        $zone = "@ IN TXT \"v=spf1 include:amazonses.com ~all\"\n";
        $this->http->push(200, $zone, ['Content-Type' => 'text/plain']);

        $result = $this->client()->domains->zoneFile('d-1');

        self::assertSame('/api/v1/domains/d-1/zone-file', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame($zone, $result);
    }

    public function testVerifyPostsAndReturnsTheRefreshedDomain(): void
    {
        $this->http->push(201, self::domainPayload(status: 'VERIFYING'));

        $domain = $this->client()->domains->verify('d-1');

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v1/domains/d-1/verify', $request->getUri()->getPath());
        self::assertSame('', (string) $request->getBody());
        self::assertSame(DomainStatus::Verifying, $domain->status);
    }

    public function testDelete(): void
    {
        $this->http->push(200, null);

        $this->client()->domains->delete('d-1');

        $request = $this->http->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/v1/domains/d-1', $request->getUri()->getPath());
    }
}
