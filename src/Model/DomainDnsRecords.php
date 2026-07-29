<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Enum\DomainStatus;
use Pulsenote\Internal\Payload;

/**
 * The DNS records a domain needs, together with what Pulsenote can currently see.
 */
final class DomainDnsRecords
{
    /**
     * @param list<DnsRecord> $records
     */
    public function __construct(
        public readonly string $domain,
        public readonly DomainStatus $status,
        public readonly array $records,
        public readonly bool $spfVerified,
        public readonly bool $dkimVerified,
        public readonly bool $dmarcVerified,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var DomainStatus $status */
        $status = Payload::enum($data, 'status', DomainStatus::class);

        return new self(
            domain: Payload::string($data, 'domain'),
            status: $status,
            records: array_map(
                static fn (array $row): DnsRecord => DnsRecord::fromArray($row),
                Payload::objectList($data, 'records'),
            ),
            spfVerified: Payload::bool($data, 'spfVerified'),
            dkimVerified: Payload::bool($data, 'dkimVerified'),
            dmarcVerified: Payload::bool($data, 'dmarcVerified'),
        );
    }
}
