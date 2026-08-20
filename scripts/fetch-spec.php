<?php

declare(strict_types=1);

/*
 * Refresh openapi/pulsenote-api.json from the live API spec.
 *
 *   php scripts/fetch-spec.php                       # default: https://pulsenote.eu/openapi.json
 *   SPEC_URL=http://localhost:3000/api-json php scripts/fetch-spec.php
 *
 * Filters the full spec down to the data plane — operations authenticated with
 * X-API-Key — which is the surface this SDK covers. Account management (JWT auth) is
 * deliberately excluded; `SpecCoverageTest` asserts that.
 *
 * This does NOT generate code. Run the tests afterwards: a red SpecCoverageTest is the
 * to-do list of endpoints to hand-write.
 */

// The public spec is the released contract; the internal sysgp.eu host is not always up.
$specUrl = getenv('SPEC_URL') ?: 'https://pulsenote.eu/openapi.json';
$target = __DIR__ . '/../openapi/pulsenote-api.json';

fwrite(STDERR, "Fetching {$specUrl}\n");
$raw = @file_get_contents($specUrl);
if ($raw === false) {
    fwrite(STDERR, "error: could not fetch {$specUrl}\n");
    exit(1);
}

/** @var array<string,mixed> $spec */
$spec = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

$dataPlane = ['Notifications', 'Templates', 'Domains'];

$out = [
    'openapi' => $spec['openapi'] ?? '3.0.0',
    'info' => [
        'title' => 'Pulsenote API',
        'description' => $spec['info']['description'] ?? null,
        'version' => $spec['info']['version'] ?? null,
        'contact' => new stdClass(),
    ],
    'servers' => $spec['servers'] ?? [],
    'tags' => array_values(array_filter(
        $spec['tags'] ?? [],
        static fn (array $tag): bool => in_array($tag['name'] ?? '', $dataPlane, true),
    )),
    'paths' => [],
    'components' => [
        'securitySchemes' => ['api-key' => $spec['components']['securitySchemes']['api-key']],
        'schemas' => [],
    ],
];

foreach ($spec['paths'] as $route => $methods) {
    foreach ($methods as $verb => $operation) {
        if (str_contains(json_encode($operation['security'] ?? '', JSON_THROW_ON_ERROR), 'api-key')) {
            $out['paths'][$route][$verb] = $operation;
        }
    }
}

// Pull across only the schemas the kept operations actually reference, transitively.
$needed = [];
$walk = static function (mixed $node) use (&$walk, &$needed, $spec): void {
    if (!is_array($node)) {
        return;
    }
    foreach ($node as $key => $value) {
        if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/components/schemas/')) {
            $name = substr($value, strlen('#/components/schemas/'));
            if (!isset($needed[$name])) {
                $needed[$name] = true;
                $walk($spec['components']['schemas'][$name] ?? null);
            }
        } else {
            $walk($value);
        }
    }
};
$walk($out['paths']);

ksort($needed);
foreach (array_keys($needed) as $name) {
    $out['components']['schemas'][$name] = $spec['components']['schemas'][$name];
}

file_put_contents($target, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

printf(
    "Wrote %s — %d paths, %d schemas\n",
    realpath($target) ?: $target,
    count($out['paths']),
    count($out['components']['schemas']),
);
