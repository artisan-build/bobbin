# Bobbin

Bobbin gives integration teams named endpoints for receiving, inspecting, and eventually replaying
third-party webhooks without the RequestBin and tunnel debugging loop.

## Who It Is For

Bobbin is for developers and operators who need a durable address for an integration's webhook
traffic and a clear place to inspect what arrived.

## What It Does

- Create named webhook endpoints within the signed-in operator's team.
- List and inspect that team's endpoints.
- Reserve a stable, cryptographically random capture URL for each endpoint.

Public request capture, retention and redaction controls, and replay delivery are not implemented in
this product slice. MVP explicitly excludes fan-out, payload transformations, vendor-specific
signature verification, cross-endpoint aggregation, and a body-blind MCP surface.

## Built For Cloud

Bobbin is a single-tenant Built for Cloud app intended to be deployed into each customer's own
Laravel Cloud account. `artisan-build/built-for-cloud` supplies authentication and ownership
plumbing. Catalog metadata remains unconfigured while its manifest checker contract is reconciled.

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
