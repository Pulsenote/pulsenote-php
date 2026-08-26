# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0](https://github.com/Pulsenote/pulsenote-php/compare/1.1.0...1.2.0) (2026-08-26)


### Features

* support cc, bcc, replyTo and attachments ([#8](https://github.com/Pulsenote/pulsenote-php/issues/8)) ([4f63566](https://github.com/Pulsenote/pulsenote-php/commit/4f63566977b1449a4ff0bfd83efbf6ef16971e48))

## [1.1.0] - 2026-08-25

### Added

- **Symfony Mailer bridge** — `PulsenoteTransportFactory` turns a
  `pulsenote+api://KEY@default` DSN into a transport, so a Symfony application routes
  every `MailerInterface::send()` through Pulsenote by registering one tagged service.
  The API key is read from the DSN *user*, not the password, because Symfony redacts
  passwords in `debug:config` but echoes hosts.
- `PulsenoteTransport` now accepts an event dispatcher and logger. Without them Symfony
  Mailer emits no `SentMessage` / `FailedMessage` events and logs nothing, so anything
  built on those hooks silently stops working.

### Changed

- The transport moved from `Pulsenote\Laravel\PulsenoteTransport` to
  `Pulsenote\Mailer\PulsenoteTransport`. It was never Laravel-specific — Laravel runs
  on Symfony Mailer — and a Symfony application should not have to reference a Laravel
  namespace.

### Deprecated

- `Pulsenote\Laravel\PulsenoteTransport`, kept as a subclass because it shipped in
  1.0.0. Behaviour is identical; use `Pulsenote\Mailer\PulsenoteTransport`.

## [1.0.0] - 2026-08-25

### Added

- **Laravel mail transport** — `MAIL_MAILER=pulsenote`. Registers a `pulsenote` mail
  driver so every existing Mailable, password reset and verification email routes
  through the API without touching application code; the notification channel, by
  contrast, requires a `toPulsenote()` method on each notification.

  The transport **refuses** `cc`, `bcc`, `replyTo` and attachments rather than dropping
  them, since the API has no field for any of them and a silently missing attachment is
  a worse failure than an exception. Several `To` recipients are fanned out through the
  batch endpoint, so they do not see one another in the header.
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

[Unreleased]: https://github.com/Pulsenote/pulsenote-php/compare/1.1.0...HEAD
[1.1.0]: https://github.com/Pulsenote/pulsenote-php/compare/1.0.0...1.1.0
[1.0.0]: https://github.com/Pulsenote/pulsenote-php/compare/0.1.0...1.0.0
[0.1.0]: https://github.com/Pulsenote/pulsenote-php/releases/tag/0.1.0
