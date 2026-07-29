<?php

declare(strict_types=1);

namespace Pulsenote\Exception;

/**
 * 401 Unauthorized / 403 Forbidden — the API key is missing, malformed, revoked, or not allowed to touch this resource.
 */
final class AuthenticationException extends ApiException
{
}
