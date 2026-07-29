<?php

declare(strict_types=1);

namespace Pulsenote\Internal;

/**
 * Binds an SDK method to the OpenAPI operation it implements.
 *
 * This is what lets `SpecCoverageTest` diff the hand-written client against
 * `openapi/pulsenote-api.json` and fail CI when the API grows an endpoint the SDK
 * does not cover (or when a path/verb drifts). Every public method on a resource
 * class that hits the network must carry one.
 *
 * @internal
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Operation
{
    /**
     * @param string $id     `operationId` from the spec.
     * @param string $method HTTP verb, uppercase.
     * @param string $path   Templated path, e.g. `/api/v1/templates/{id}`.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $method,
        public readonly string $path,
    ) {
    }
}
