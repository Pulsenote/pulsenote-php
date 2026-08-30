<?php

declare(strict_types=1);

namespace Pulsenote\Enum;

/**
 * Why an address is suppressed.
 *
 * `Bounce` and `Complaint` are added automatically from provider feedback and
 * are the ones you should think twice about removing — the address either does
 * not exist or its owner marked you as spam. `Manual` is your own doing.
 */
enum SuppressionReason: string
{
    /** Hard bounce: the mailbox does not exist or rejected permanently. */
    case Bounce = 'bounce';

    /** The recipient marked a message as spam. */
    case Complaint = 'complaint';

    /** Added by hand through the API or dashboard. */
    case Manual = 'manual';
}
