<?php

declare(strict_types=1);

namespace Pulsenote\Model;

use Pulsenote\Enum\DnsRecordType;
use Pulsenote\Internal\Payload;

/**
 * One DNS record to publish at your registrar so Pulsenote can send as your domain.
 */
final class DnsRecord
{
    public function __construct(
        public readonly DnsRecordType $type,
        /** Record name/host. */
        public readonly string $name,
        /** Record value. */
        public readonly string $value,
        /** What this record is for. */
        public readonly string $purpose,
        /** Priority (MX records only). */
        public readonly ?int $priority = null,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var DnsRecordType $type */
        $type = Payload::enum($data, 'type', DnsRecordType::class);

        return new self(
            type: $type,
            name: Payload::string($data, 'name'),
            value: Payload::string($data, 'value'),
            purpose: Payload::string($data, 'purpose'),
            priority: isset($data['priority']) && is_numeric($data['priority']) ? (int) $data['priority'] : null,
        );
    }
}
