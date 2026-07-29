# Security Policy

## Reporting a vulnerability

Please report security issues privately to **security@pulsenote.eu**. Do not open a
public GitHub issue for security reports. We aim to acknowledge reports within a few
business days.

## Handling API keys

This SDK authenticates with a tenant API key (`pk_live_…` / `pk_test_…`) sent as the
`X-API-Key` header. Keep keys server-side:

- Load the key from an environment variable or secret store — never hardcode it, and
  never commit it. In Laravel, keep it in `.env` and read it via
  `config('pulsenote.api_key')`, so `php artisan config:cache` doesn't bake it into a
  committed file.
- Do not expose a `pk_live_` key to a browser, a mobile app, or any client-side bundle.
- Rotate keys via the Pulsenote dashboard if one is exposed.

## Error output

`ApiException` messages include the request URL and the API's error body, and
`ApiException::$requestId` carries the `x-request-id` header — useful in support
tickets. They never contain your API key, but treat exception traces as you would any
other log data.
