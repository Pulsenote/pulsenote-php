# pulsenote-php

Official PHP SDK for the [Pulsenote](https://pulsenote.eu) email API.
Published to Packagist as [`pulsenote/pulsenote-php`](https://packagist.org/packages/pulsenote/pulsenote-php).

Framework-agnostic (PSR-18), with first-class Laravel support.

## Install

```bash
composer require pulsenote/pulsenote-php
```

The SDK talks PSR-18, so it uses whatever HTTP client you already have (Guzzle, Symfony
HttpClient, …) via [`php-http/discovery`](https://docs.php-http.org/en/latest/discovery.html).
If you have none:

```bash
composer require guzzlehttp/guzzle
```

Requires PHP 8.2+.

## Usage

```php
use Pulsenote\Pulsenote;

$pulsenote = new Pulsenote(getenv('PULSENOTE_API_KEY'));

$res = $pulsenote->notifications->send(
    to: 'greg@example.com',
    subject: 'Welcome',
    html: '<b>Hello from Pulsenote</b>',
);

echo $res->id, ' ', $res->status->value; // "<uuid> QUEUED"  (or SANDBOX — see below)
```

`Pulsenote::fromEnvironment()` does the same from `PULSENOTE_API_KEY` (and optional
`PULSENOTE_BASE_URL`).

### Sandbox — your first send probably won't be delivered

Pulsenote sends only from **your own** verified domain; there is no shared sending
address. Until you have verified one, sends are accepted and fully rendered but
**never delivered**, and come back as sandbox rather than failing:

```php
$res = $pulsenote->notifications->send(/* … */);

if ($res->sandbox) {
    // $res->status === NotificationStatus::Sandbox
    // Rendered and stored for preview — nothing was delivered.
    error_log($res->message);
}
```

This exists so you can wire up the integration *before* pointing production DNS at
an email vendor. The `from` you pass is echoed back untouched, so **going live is
just verifying a domain — no code changes**. Sandbox is capped at 50 messages/month
and does not consume your plan allowance.

Verify a domain with `$pulsenote->domains`, or in Settings → Domains. A subdomain
such as `notify.yourcompany.com` is recommended: its DNS records are separate from
your main domain, so publishing them cannot affect the deliverability of your
existing company email.

> Guard against shipping in sandbox by asserting on it:
> `self::assertFalse($res->sandbox);`

The client exposes three groups matching the API's data plane:

| Group | Methods |
|-------|---------|
| `$pulsenote->notifications` | `send`, `sendBatch`, `list`, `all`, `get`, `stats` |
| `$pulsenote->templates` | `list`, `get`, `create`, `update`, `delete`, `render`, `listLocales` |
| `$pulsenote->domains` | `list`, `add`, `verify`, `dnsRecords`, `zoneFile`, `delete` |

Everything is named arguments in, typed objects out — statuses are enums
(`NotificationStatus`, `DomainStatus`, `DnsRecordType`), timestamps are
`DateTimeImmutable`, and unset optional arguments are omitted from the request rather
than sent as `null`.

### Batch sending

`sendBatch()` queues up to 500 emails in one request. Each message is validated
independently, so the batch is **partial-success**: one bad recipient rejects that
message and the rest still go out.

```php
use Pulsenote\Model\BatchMessage;

$batch = $pulsenote->notifications->sendBatch([
    new BatchMessage(to: 'a@example.com', subject: 'Welcome', html: '<b>Hi</b>'),
    new BatchMessage(to: 'b@example.com', templateSlug: 'welcome', locale: 'pl',
                     templateData: ['name' => 'Greg']),
]);

echo $batch->queued, '/', $batch->total, ' queued', PHP_EOL;

foreach ($batch->rejections() as $failed) {
    echo 'message ', $failed->index, ' rejected: ', $failed->error, PHP_EOL;
}
```

`BatchMessage` takes the same arguments as `send()`. The result is iterable over the
per-message outcomes and also gives you `queuedIds()`, `rejections()`, and
`isCompletelySuccessful()`.

> A batch that partly failed still returns `202` and **does not throw** — check
> `$batch->rejected` (or `isCompletelySuccessful()`) rather than assuming success.
> Exceptions are reserved for whole-request failures: bad key, quota exhausted, or a
> batch that is empty or over `Notifications::MAX_BATCH`.

### Paginating

`list()` returns one page and is directly iterable; `all()` walks every page lazily.

```php
$page = $pulsenote->notifications->list(page: 1, limit: 50);
echo $page->meta->total, ' total, ', count($page), ' on this page';

foreach ($pulsenote->notifications->all(status: NotificationStatus::Bounced) as $n) {
    echo $n->recipient, ' — ', $n->failureReason, PHP_EOL;
}
```

Both accept `search` to filter by recipient or subject, case-insensitive:

```php
$pulsenote->notifications->list(search: 'greg@example.com');
```

### Errors

Non-2xx responses throw a typed exception. All of them extend `PulsenoteException`, so
one `catch` covers everything, including transport failures.

```php
use Pulsenote\Exception\RateLimitException;
use Pulsenote\Exception\ValidationException;

try {
    $pulsenote->notifications->send(to: $to, subject: $subject, html: $html);
} catch (RateLimitException $e) {
    sleep($e->retryAfter ?? 60);   // from the Retry-After header
} catch (ValidationException $e) {
    logger()->warning($e->apiMessage());  // field errors, joined
}
```

| Status | Exception |
|--------|-----------|
| 400 / 422 | `ValidationException` |
| 401 / 403 | `AuthenticationException` |
| 404 | `NotFoundException` |
| 409 | `ConflictException` |
| 429 | `RateLimitException` (`->retryAfter`) |
| 5xx | `ServerException` |
| other non-2xx | `ApiException` |
| no response / bad JSON | `TransportException` |

The SDK does **not** retry for you — back-off policy belongs to your queue or job
runner, not to a request helper.

## Laravel

The service provider is auto-discovered. Set the key and go:

```env
PULSENOTE_API_KEY=pk_live_...
```

```bash
php artisan vendor:publish --tag=pulsenote-config   # optional
```

**Inject the client** (bound as a singleton):

```php
public function __construct(private readonly Pulsenote $pulsenote) {}
```

**Or use the facade:**

```php
use Pulsenote\Laravel\Facades\Pulsenote;

Pulsenote::notifications()->send(to: 'greg@example.com', subject: 'Hi', html: '<b>Hi</b>');
```

**Or the notification channel:**

```php
public function via(object $notifiable): array
{
    return ['pulsenote'];
}

public function toPulsenote(object $notifiable): PulsenoteMessage
{
    return PulsenoteMessage::make()
        ->subject('Your order is on its way')
        ->template('order-shipped', locale: 'pl')
        ->data(['tracking' => $this->code]);
}
```

The recipient resolves from the notifiable's `pulsenote` route, then its `mail` route,
then an `email` attribute — so existing `Notifiable` models work unchanged. Add
`routeNotificationForPulsenote()` to override. API failures propagate, so a queued
notification retries on Laravel's normal path.

See [`examples/laravel-notification.php`](examples/laravel-notification.php).

### Mail transport — `MAIL_MAILER=pulsenote`

The channel above needs a `toPulsenote()` method on every notification. The **mail
transport** needs none: point Laravel's mailer at Pulsenote and every existing
Mailable, password reset and verification email routes through it unchanged.

Add the mailer to `config/mail.php`:

```php
'mailers' => [
    'pulsenote' => ['transport' => 'pulsenote'],
],
```

then switch to it:

```env
MAIL_MAILER=pulsenote
PULSENOTE_API_KEY=pk_live_...
```

That is the whole change. `Mail::to($user)->send(new OrderShipped($order))` now goes
through Pulsenote.

#### What it will not send

The API carries `to`, `from`, `subject`, `html` and `text`. It has **no `cc`, `bcc`,
`replyTo` or attachments**, and the transport **throws** rather than dropping them:

```
Pulsenote: the mail transport cannot send cc, attachments — the API has no field
for them. Nothing was sent, deliberately: dropping them silently would deliver a
message that differs from the one your Mailable declares.
```

A vanished invoice PDF is a worse failure than an exception at send time, and one
you would not discover until a customer complained. If a particular Mailable needs
those, route it through a different mailer:

```php
Mail::mailer('ses')->to($user)->send(new InvoiceIssued($invoice));
```

#### Several recipients

Pulsenote models one recipient per message, so `Mail::to(['a@x.com', 'b@x.com'])` is
fanned out through the batch endpoint — one message each. **Recipients therefore do
not see one another in the `To` header.** For transactional mail that is usually what
you want; it is a behaviour change if you were relying on a shared `To`.

## Custom HTTP client

Pass any PSR-18 client — useful for middleware, custom timeouts, or tests:

```php
$pulsenote = new Pulsenote(
    apiKey: $key,
    baseUrl: 'https://staging.example.test',
    headers: ['X-Trace-Id' => $traceId],
    httpClient: $myPsr18Client,
    requestFactory: $myPsr17Factory,
    streamFactory: $myPsr17Factory,
);
```

## Scope

v1 covers the **data plane** — the endpoints authenticated with your `X-API-Key`
(notifications, templates, domains). Account-management endpoints (team, billing,
auth), which use JWT auth, are intentionally out of scope, matching
[`pulsenote-node`](https://github.com/Pulsenote/pulsenote-node).

## Staying in sync with the API

Unlike the Node SDK this client is **hand-written**, so nothing regenerates when the API
changes. Instead, `openapi/pulsenote-api.json` is committed and `tests/SpecCoverageTest.php`
diffs it against the `#[Operation]` attributes on the resource classes. If the API grows,
moves, or renames an endpoint, CI goes red with the exact list.

```bash
make spec    # refresh the spec from the live API, then show the drift
```

A red `SpecCoverageTest` is a to-do list, not a bug: add the method, tag it with
`#[Operation(...)]`, and it goes green.

## Development

```bash
composer install
make check    # phpstan (level 8) + phpunit
```

See [CONTRIBUTING.md](CONTRIBUTING.md) and [CHANGELOG.md](CHANGELOG.md).

## License

[MIT](LICENSE) © GP IT-Tech
