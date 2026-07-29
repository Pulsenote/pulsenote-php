<?php

declare(strict_types=1);

namespace Pulsenote\Exception;

/**
 * The request never produced an HTTP response — DNS failure, connection reset,
 * TLS error, timeout — or the response body was not valid JSON.
 */
final class TransportException extends PulsenoteException
{
}
