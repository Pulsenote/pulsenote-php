<?php

declare(strict_types=1);

namespace Pulsenote\Exception;

/**
 * 409 Conflict — the resource already exists (e.g. the sender domain is already registered).
 */
final class ConflictException extends ApiException
{
}
