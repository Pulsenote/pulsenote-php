# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Sandbox results on `send()` / `sendBatch()`. With no verified sending domain the API
  renders the message without delivering it and returns `status: SANDBOX` with
  `sandbox: true` and an explanatory `message`, instead of raising. `SendEmailResponse`
  gains `$sandbox` and `$message`, and `NotificationStatus` gains `Sandbox` (terminal —
  nothing will move it).

  **This was a hard failure before.** `Payload::enum()` rejects unknown values, so a
  `SANDBOX` status made `send()` throw `TransportException` — on a new user's very first
  send, which is exactly the case sandbox exists to serve.
- `Notifications::sendBatch()` — `POST /api/v1/notifications/batch`, up to 500 messages
  per call. New `BatchMessage` input object (same arguments as `send()`) and
  `BatchSendResult` / `BatchMessageResult` / `BatchMessageStatus` for the per-message
  outcome. The batch is partial-success: it returns `202` with rejections rather than
  throwing, so check `rejected` / `isCompletelySuccessful()`.
- `Notifications::list()` and `all()` accept `search` — matches recipient or subject,
  case-insensitive.
- `Notification` now exposes `templateName` and `fromAddress`, which the API returns but
  the model silently dropped.

### Changed

- `scripts/fetch-spec.php` defaults to the public spec at `https://pulsenote.eu/openapi.json`;
  the internal `pulsenote-api.sysgp.eu` host it used before is not reliably reachable.
- `Notification`'s constructor gained two parameters after `$templateId`. Positional
  construction of the model shifts accordingly; `fromArray()` and named arguments are
  unaffected.

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
