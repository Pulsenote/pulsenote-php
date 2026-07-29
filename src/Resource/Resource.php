<?php

declare(strict_types=1);

namespace Pulsenote\Resource;

use Pulsenote\Exception\ConfigurationException;
use Pulsenote\Http\Transport;

/**
 * Shared plumbing for the three data-plane resource groups.
 *
 * @internal
 */
abstract class Resource
{
    public function __construct(protected readonly Transport $transport)
    {
    }

    /**
     * Interpolate path parameters, URL-encoded.
     *
     * @param array<string,string> $params
     */
    protected function path(string $template, array $params = []): string
    {
        foreach ($params as $name => $value) {
            if (trim($value) === '') {
                throw new ConfigurationException(sprintf('Pulsenote: `%s` must not be empty.', $name));
            }
            $template = str_replace('{' . $name . '}', rawurlencode($value), $template);
        }

        return $template;
    }
}
