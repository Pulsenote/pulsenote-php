<?php

declare(strict_types=1);

namespace Pulsenote\Enum;

/**
 * Verification state of a sender domain.
 */
enum DomainStatus: string
{
    case Pending = 'PENDING';
    case Verifying = 'VERIFYING';
    case Verified = 'VERIFIED';
    case Failed = 'FAILED';
}
