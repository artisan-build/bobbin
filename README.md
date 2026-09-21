# Bobbin

Bobbin gives integration teams named endpoints for receiving, inspecting, and replaying third-party
webhooks without the RequestBin and tunnel debugging loop.

## Who It Is For

Bobbin is for developers and operators who need a durable address for an integration's webhook
traffic and a clear place to inspect what arrived.

## What It Does

- Create named webhook endpoints within the signed-in operator's team.
- Capture raw request methods, query bytes, permitted multi-value headers, and bodies durably.
- Configure retention and redaction controls for future captures.
- Inspect stored captures and their redaction metadata.
- Replay a selected capture to one configured destination through the database queue.
- See queued, running, succeeded, and failed delivery history with attempts, response status, safe
  errors, and timestamps.

MVP explicitly excludes fan-out, payload transformations, vendor-specific signature verification,
cross-endpoint aggregation, destination deletion, and a body-blind MCP surface.

## Delivery Safety

Destination URLs must use HTTP or HTTPS and resolve entirely to globally routable addresses. Bobbin
rejects credentials, fragments, URL-template braces, local and non-public addresses, unresolved
hosts, and unsafe mixed DNS answers. The same policy runs immediately before every attempt, and
redirects are never followed; a 3xx response is terminal.

Delivery is at-least-once. Per-delivery overlap locks prevent concurrent workers from sending the
same delivery, but a receiver can act before a worker records success. DNS is validated at
configuration and immediately before sending, but the connection is not pinned to the validated
address, so a time-of-check/time-of-use DNS window remains.

## Built For Cloud

Bobbin is a single-tenant Built for Cloud app intended to be deployed into each customer's own
Laravel Cloud account. `artisan-build/built-for-cloud` supplies authentication and ownership
plumbing. Its Built for Cloud product page is <https://scalpels.app/products/bobbin>.

## Local Development

```bash
composer setup
composer dev
```

This app is nodeless by design. Do not add Node, npm, Vite, or a frontend build step. Static assets
are committed under `public/build`.

Run the focused endpoint-management tests with:

```bash
php artisan test tests/Feature/EndpointManagementTest.php
```

Run the real capture-to-replay verification harness with:

```bash
php tests/Live/run-replay-status.php
```

The harness creates throwaway credentials and storage, starts Bobbin, a local receiver, and a real
database queue worker, then drives the protected browser surfaces. It temporarily sets
`BOBBIN_ALLOW_PRIVATE_DESTINATIONS=true`. That escape hatch defaults to false and only works when
`APP_ENV=local`; it is for local verification only and must never be enabled in a deployed
environment.

## Quality Gate

```bash
composer ready
```

## Deployment

Artisan Build agents handling Laravel Cloud work must follow
`brain/skills/laravel-cloud-deploy/SKILL.md`. Start with read-only discovery. Credentials come from
the authenticated Laravel Cloud CLI; discover application and environment identifiers rather than
guessing or committing them. Keep `.cloud/config.json` uncommitted, and verify the application and
its attached resources after every deployment.

No app-specific Cloud environments or resource identifiers are committed. Discover the target at
deployment time and allow Laravel Cloud to inject configuration for provisioned resources.

## Repository

<https://github.com/artisan-build/bobbin>
