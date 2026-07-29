<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Enum\DomainStatus;
use Pulsenote\Internal\Payload;

/**
 * A sender domain registered with the tenant.
 */
final class Domain
{
    /**
     * @param list<DnsRecord> $dnsRecords DNS records to publish (returned on add/verify).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $domain,
        /** Verification status. */
        public readonly DomainStatus $status,
        /** Whether the SPF (MAIL FROM) record is verified. */
        public readonly bool $spfVerified,
        /** Whether DKIM signing is verified. */
        public readonly bool $dkimVerified,
        /** Whether a DMARC policy is present. */
        public readonly bool $dmarcVerified,
        /** Whether this is the tenant default sender domain. */
        public readonly bool $isDefault,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        /** Default from address for this domain. */
        public readonly ?string $fromEmail = null,
        /** Default display name. */
        public readonly ?string $fromName = null,
        public readonly ?\DateTimeImmutable $verifiedAt = null,
        public readonly array $dnsRecords = [],
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
            id: Payload::string($data, 'id'),
            domain: Payload::string($data, 'domain'),
            status: $status,
            spfVerified: Payload::bool($data, 'spfVerified'),
            dkimVerified: Payload::bool($data, 'dkimVerified'),
            dmarcVerified: Payload::bool($data, 'dmarcVerified'),
            isDefault: Payload::bool($data, 'isDefault'),
            createdAt: Payload::dateTime($data, 'createdAt'),
            updatedAt: Payload::dateTime($data, 'updatedAt'),
            fromEmail: Payload::stringOrNull($data, 'fromEmail'),
            fromName: Payload::stringOrNull($data, 'fromName'),
            verifiedAt: Payload::dateTimeOrNull($data, 'verifiedAt'),
            dnsRecords: array_map(
                static fn (array $row): DnsRecord => DnsRecord::fromArray($row),
                Payload::objectList($data, 'dnsRecords'),
            ),
        );
    }

    /**
     * Whether the domain is cleared to send — status `VERIFIED` with SPF and DKIM in place.
     */
    public function isSendable(): bool
    {
        return $this->status === DomainStatus::Verified && $this->spfVerified && $this->dkimVerified;
    }
}
