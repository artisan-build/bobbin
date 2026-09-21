# Bobbin

Bobbin gives integration teams named endpoints that durably catch third-party webhooks, preserve
what arrived, and replay requests to one destination. It is a single-tenant Built for Cloud product
deployed into the customer's Laravel Cloud account.

MVP non-goals are fan-out to multiple destinations, payload transformations, vendor-specific
signature verification, cross-endpoint aggregation, and a body-blind MCP surface.

## Workflow

See `.solo/workflow.md` for this app's repository, merge policy, hard gate, CI, and coordination
details.

Hard gate before any PR: `composer ready` (ide-helper + rector + pint + phpstan + pest + audit).

## No Frontend Build Step

Non-negotiable: **no Node, no npm, no Vite, no frontend build step.**
Do not introduce one.

Tailwind CSS is served from the committed bundle at `public/build/assets/app.css`. A class absent
from that bundle silently does nothing. To use new classes, delete the existing bundle, run
`php artisan tailwind:optimize`, verify the classes landed, and commit the regenerated file. The
optimizer uses a standalone Tailwind binary and does not introduce Node tooling.

## Built For Cloud

`artisan-build/built-for-cloud` provides Bobbin's authentication, ownership, and credential
plumbing. The package-owned three-key manifest overlay is maintained in
`config/built-for-cloud.php`; configure it only through the repository's frozen configure command.

Laravel Cloud provisions resource configuration. Do not commit secrets, app IDs, personal config,
machine-specific paths, or hand-written values that shadow Cloud-managed resource variables.

## IDE Helper Files

`_ide_helper.php` and `_ide_helper_models.php` are committed on purpose because PHPStan scans the
model helper. `.phpstorm.meta.php` is gitignored because it embeds absolute local paths. Do not make
these files consistent in either direction.

## Static Analysis

The PHPStan baseline lives in `phpstan-baseline.neon`. Keep it shrinking; never grow it silently.
