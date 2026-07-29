# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-07-28

### Added

- Initial release. Hand-written PHP client (8.2+) for the Pulsenote data-plane API
  (`X-API-Key` auth), covering all 17 operations across notifications, templates, and
  domains.
- `Pulsenote` client with `notifications`, `templates`, and `domains` groups; named
  arguments in, typed models out. `Pulsenote::fromEnvironment()` for env-driven wiring.
- PSR-18/PSR-17 transport with auto-discovery — bring your own HTTP client.
- Typed exception hierarchy under `PulsenoteException`: `ValidationException`,
  `AuthenticationException`, `NotFoundException`, `ConflictException`,
  `RateLimitException` (with `Retry-After`), `ServerException`, `TransportException`.
- Backed enums for `NotificationStatus`, `DomainStatus`, `DnsRecordType`.
- `Notifications::all()` — lazy generator across every page.
- Laravel integration (auto-discovered): service provider, publishable
  `config/pulsenote.php`, `Pulsenote` facade, and a `pulsenote` notification channel with
  `PulsenoteMessage`.
- `SpecCoverageTest` — diffs the SDK against the committed OpenAPI spec so API drift
  fails CI. `make spec` refreshes the spec from the live API.

[Unreleased]: https://github.com/Pulsenote/pulsenote-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Pulsenote/pulsenote-php/releases/tag/v0.1.0
