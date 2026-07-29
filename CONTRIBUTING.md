# Contributing

Unlike [`pulsenote-node`](https://github.com/Pulsenote/pulsenote-node), this client is
**hand-written** — there is no generator, and every file here is safe to edit. What keeps
it honest is `tests/SpecCoverageTest.php`, which diffs the SDK against the committed
OpenAPI spec.

## Layout

| Path | What it is |
|------|------------|
| `src/Pulsenote.php` | The facade users construct. |
| `src/Resource/` | One class per API group; every networked method carries `#[Operation]`. |
| `src/Model/` | Typed response objects, each with a `fromArray()`. |
| `src/Enum/` | Backed enums for the spec's string enums. |
| `src/Exception/` | `PulsenoteException` and its status-specific subclasses. |
| `src/Http/` | PSR-18 transport. Nothing else in `src/` touches PSR-18. |
| `src/Laravel/` | Optional integration; only loaded inside a Laravel app. |
| `openapi/pulsenote-api.json` | Committed data-plane spec — the drift baseline. |

## Adding an endpoint

The source of truth is the `api-gateway` service. Once its OpenAPI annotations change:

```bash
make spec     # refresh openapi/pulsenote-api.json and run the drift test
```

`SpecCoverageTest` will name the operations the SDK is missing. For each one:

1. Add a method to the right `src/Resource/*.php` class, using named arguments for the
   request fields and returning a typed model.
2. Tag it with `#[Operation('operationId', 'VERB', '/api/v1/…')]` — this is what the
   drift test matches on.
3. Add the response model under `src/Model/` if it's new, with a `fromArray()` that uses
   `Internal\Payload` accessors (required fields throw rather than silently nulling).
4. Cover it in `tests/` — assert the verb, path, and serialised body, not just the return
   value.

Only exclude an operation via `SpecCoverageTest::INTENTIONALLY_UNIMPLEMENTED`, and only
with a comment saying why — every entry is API surface a PHP user cannot reach.

## Before opening a PR

```bash
make check    # phpstan level 8 + phpunit
```

Both run in CI on PHP 8.2–8.4, plus a `--prefer-lowest` job. No baselines, no `@phpstan-ignore`.

## Releasing

Bump `Pulsenote::VERSION` and the `CHANGELOG.md` entry in the same PR, merge, then cut a
GitHub release tagged `vX.Y.Z`. Packagist picks it up from the release webhook.
