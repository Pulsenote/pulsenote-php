<?php

declare(strict_types=1);

namespace Pulsenote\Enum;

/**
 * What to do with a template that is already there (same slug and locale).
 *
 * The default is {@see self::Skip} on purpose. Quietly replacing a live
 * template is how someone loses the body of a transactional email that goes
 * out every day, so overwriting has to be asked for in as many words.
 */
enum ImportConflictPolicy: string
{
    /** Leave the existing template alone and report it as skipped. */
    case Skip = 'skip';

    /** Replace the existing template with the one in the file. */
    case Overwrite = 'overwrite';
}
