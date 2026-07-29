<?php

declare(strict_types=1);

namespace Pulsenote\Exception;

/**
 * A 5xx response. The request may be safe to retry after a short back-off.
 */
final class ServerException extends ApiException
{
}
