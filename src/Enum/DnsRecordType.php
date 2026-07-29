<?php

declare(strict_types=1);

namespace Pulsenote\Enum;

/**
 * DNS record types Pulsenote asks you to publish for a sender domain.
 */
enum DnsRecordType: string
{
    case Cname = 'CNAME';
    case Mx = 'MX';
    case Txt = 'TXT';
}
