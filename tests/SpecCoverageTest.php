<?php

declare(strict_types=1);

namespace Pulsenote\Tests;

use PHPUnit\Framework\TestCase;
use Pulsenote\Internal\Operation;
use Pulsenote\Resource\Domains;
use Pulsenote\Resource\Notifications;
use Pulsenote\Resource\Templates;

/**
 * The drift guard.
 *
 * This client is hand-written rather than generated, so nothing automatically notices
 * when the API grows an endpoint or moves one. These tests diff the `#[Operation]`
 * attributes on the resource classes against `openapi/pulsenote-api.json` and fail CI
 * when the two disagree.
 *
 * Refresh the spec with `make spec` (or drop a new export into `openapi/`); a red test
 * here is a to-do list, not a bug.
 */
final class SpecCoverageTest extends TestCase
{
    private const RESOURCES = [Notifications::class, Templates::class, Domains::class];

    /**
     * Operations the SDK deliberately does not expose. Keep this empty unless there is
     * a documented reason — every entry is API surface a PHP user cannot reach.
     *
     * @var list<string>
     */
    private const INTENTIONALLY_UNIMPLEMENTED = [];

    /**
     * @return array<string,string> operationId => "METHOD /path"
     */
    private static function specOperations(): array
    {
        $path = __DIR__ . '/../openapi/pulsenote-api.json';
        self::assertFileExists($path, 'The committed OpenAPI spec is missing.');

        /** @var array{paths: array<string, array<string, array{operationId?: string}>>} $spec */
        $spec = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        $operations = [];
        foreach ($spec['paths'] as $route => $methods) {
            foreach ($methods as $verb => $operation) {
                $id = $operation['operationId'] ?? null;
                self::assertIsString($id, sprintf('Spec operation %s %s has no operationId.', $verb, $route));
                $operations[$id] = strtoupper($verb) . ' ' . $route;
            }
        }

        return $operations;
    }

    /**
     * @return array<string,string> operationId => "METHOD /path", as declared by the SDK
     */
    private static function sdkOperations(): array
    {
        $operations = [];

        foreach (self::RESOURCES as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Operation::class) as $attribute) {
                    $operation = $attribute->newInstance();

                    self::assertArrayNotHasKey(
                        $operation->id,
                        $operations,
                        sprintf('Operation "%s" is claimed by more than one SDK method.', $operation->id),
                    );

                    $operations[$operation->id] = $operation->method . ' ' . $operation->path;
                }
            }
        }

        return $operations;
    }

    public function testEverySpecOperationIsImplemented(): void
    {
        $missing = array_diff_key(
            self::specOperations(),
            self::sdkOperations(),
            array_flip(self::INTENTIONALLY_UNIMPLEMENTED),
        );

        self::assertSame([], $missing, sprintf(
            "The API exposes %d data-plane operation(s) this SDK does not implement:\n  %s",
            count($missing),
            implode("\n  ", array_map(
                static fn (string $id, string $route): string => "$id — $route",
                array_keys($missing),
                $missing,
            )),
        ));
    }

    public function testEverySdkOperationExistsInTheSpec(): void
    {
        $unknown = array_diff_key(self::sdkOperations(), self::specOperations());

        self::assertSame([], $unknown, sprintf(
            "This SDK claims operation(s) the spec does not define — renamed or removed upstream?\n  %s",
            implode("\n  ", array_keys($unknown)),
        ));
    }

    public function testEverySdkOperationUsesTheSpecVerbAndPath(): void
    {
        $spec = self::specOperations();

        foreach (self::sdkOperations() as $id => $route) {
            self::assertSame(
                $spec[$id] ?? null,
                $route,
                sprintf('Operation "%s" points at the wrong route.', $id),
            );
        }
    }

    public function testTheSpecIsTheDataPlaneSurfaceOnly(): void
    {
        /** @var array{components: array{securitySchemes: array<string,mixed>}} $spec */
        $spec = json_decode(
            (string) file_get_contents(__DIR__ . '/../openapi/pulsenote-api.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        // JWT-authenticated account management is out of scope for the SDK; if a
        // Bearer scheme shows up here, the spec was refreshed without filtering.
        self::assertSame(['api-key'], array_keys($spec['components']['securitySchemes']));
    }
}
