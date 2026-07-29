<?php

declare(strict_types=1);

namespace Pulsenote\Exception;

/**
 * The SDK was constructed with unusable options (missing API key, malformed base URL).
 */
final class ConfigurationException extends PulsenoteException
{
}
