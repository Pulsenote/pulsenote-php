<?php

declare(strict_types=1);

namespace Pulsenote\Exception;

/**
 * 400 Bad Request / 422 Unprocessable Entity — the payload failed validation. See apiMessage() for the field errors.
 */
final class ValidationException extends ApiException
{
}
