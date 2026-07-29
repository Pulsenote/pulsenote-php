<?php

declare(strict_types=1);

namespace Pulsenote\Exception;

/**
 * Base type for every exception thrown by the SDK.
 *
 * Catch this to handle any Pulsenote failure — transport or API — in one place.
 */
class PulsenoteException extends \RuntimeException
{
}
