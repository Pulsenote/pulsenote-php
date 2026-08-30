<?php

declare(strict_types=1);

namespace Pulsenote\Tests;

use PHPUnit\Framework\TestCase;
use Pulsenote\Internal\Operation;
use Pulsenote\Resource\Resource;

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
    /**
     * Every concrete resource class, discovered from the filesystem.
     *
     * This used to be a hand-maintained list of three class names, which meant a
     * new resource was silently absent from the guard until somebody remembered
     * to add it — the same shape of bug the guard exists to catch. Discovery
     * cannot forget.
     *
     * @return list<class-string>
     */
    private static function resources(): array
    {
        $classes = [];

        foreach (glob(__DIR__ . '/../src/Resource/*.php') ?: [] as $file) {
            $class = 'Pulsenote\\Resource\\' . basename($file, '.php');

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            // The abstract base carries no operations of its own.
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(Resource::class)) {
                continue;
            }

            $classes[] = $class;
        }

        self::assertNotEmpty($classes, 'No resource classes found — has src/Resource moved?');

        return $classes;
    }

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
     * The committed spec, decoded.
     *
     * @return array<string,mixed>
     */
    private static function spec(): array
    {
        $path = __DIR__ . '/../openapi/pulsenote-api.json';
        self::assertFileExists($path, 'The committed OpenAPI spec is missing.');

        /** @var array<string,mixed> $spec */
        $spec = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        return $spec;
    }

    /**
     * Every request-body field the spec defines, per operation.
     *
     * @return array<string, list<string>> operationId => property names
     */
    private static function specRequestFields(): array
    {
        $spec = self::spec();
        $fields = [];

        foreach ($spec['paths'] as $methods) {
            foreach ($methods as $operation) {
                if (!is_array($operation) || !isset($operation['operationId'])) {
                    continue;
                }

                $schema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
                // A batch endpoint wraps its items; follow the array to the item schema.
                $ref = $schema['$ref'] ?? $schema['items']['$ref'] ?? null;
                if (!is_string($ref)) {
                    continue;
                }

                $name = substr($ref, (int) strrpos($ref, '/') + 1);
                $properties = $spec['components']['schemas'][$name]['properties'] ?? [];
                // array_keys() is typed as list<int|string>; JSON object keys are
                // always strings, and phpstan cannot know that from the shape alone.
                $fields[$operation['operationId']] = array_map(
                    static fn (int|string $key): string => (string) $key,
                    array_keys($properties),
                );
            }
        }

        return $fields;
    }

    /**
     * Parameter names of the SDK method implementing each operation.
     *
     * @return array<string, list<string>>
     */
    private static function sdkParameters(): array
    {
        $params = [];

        foreach (self::resources() as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Operation::class) as $attribute) {
                    $params[$attribute->newInstance()->id] = array_map(
                        static fn (\ReflectionParameter $p): string => $p->getName(),
                        $method->getParameters(),
                    );
                }
            }
        }

        return $params;
    }

    /**
     * Operations whose body this SDK deliberately does not spell out field by field.
     *
     * @var array<string,string>
     */
    private const BODY_TAKEN_WHOLE = [
        // Takes a list of Message objects; the fields live on the model, not the signature.
        'sendNotificationBatch' => 'accepts a list of message models rather than scalars',
    ];

    /**
     * The operation-level guard above catches an endpoint the SDK cannot reach at
     * all. It says nothing about an endpoint that exists but has grown a field the
     * caller cannot set — which is the drift that actually happened: `region` was
     * missing from addDomain here while every other check stayed green, and
     * `stream` went the same way in the Node SDK. See GP-54.
     */
    public function testEveryRequestFieldIsReachableFromTheSdk(): void
    {
        $params = self::sdkParameters();
        $missing = [];

        foreach (self::specRequestFields() as $operationId => $fields) {
            if (isset(self::BODY_TAKEN_WHOLE[$operationId]) || !isset($params[$operationId])) {
                continue;
            }

            $absent = array_values(array_diff($fields, $params[$operationId]));
            if ($absent !== []) {
                $missing[$operationId] = $absent;
            }
        }

        self::assertSame(
            [],
            $missing,
            "Request fields in the spec with no matching SDK parameter:\n  "
            . json_encode($missing, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array<string,string> operationId => "METHOD /path", as declared by the SDK
     */
    private static function sdkOperations(): array
    {
        $operations = [];

        foreach (self::resources() as $class) {
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
