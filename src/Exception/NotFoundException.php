<?php

declare(strict_types=1);

namespace Pulsenote\Exception;

/**
 * 404 Not Found — the notification, template, or domain does not exist for this tenant.
 */
final class NotFoundException extends ApiException
{
}
