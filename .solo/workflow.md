# Workflow - Bobbin

Project profile for the `multi-agent-build` skill and every agent working in this Built for Cloud
app. The coordinator reads this first. Keep it truthful as the app evolves.

This repository is an app scaffolded from `artisan-build/built-for-cloud-starter`; it is not the
starter kit. Never open app pull requests against the starter kit or its nodeless upstream.

## What This Is

Bobbin gives you named endpoints that durably catch third-party webhooks, let you inspect exactly what arrived, and replay them onward with retries and visible delivery status. It replaces Hookdeck and the RequestBin/ngrok dance.

Non-goals, explicitly: no fan-out to multiple destinations at MVP; no payload transformations; no vendor-specific signature verification (Stripe, GitHub and friends are each their own small post-launch surface).

The decision-complete scope lives in `~/Herd/brain/ideas/2026-09-21-bobbin-prd.md`. Read it before decomposing work; it
records which decisions are Ed's and which are brain's, so neither gets silently reopened.

## Phase And Mode

- phase: greenfield
- default mode: A-autonomous
- merge policy: merge each PR when the full gate and CI are green; no human review required per PR.
  Ed is explicitly not reviewing each PR for this build.
- merge method: `gh pr merge --squash`, then delete the branch. **Do NOT use `--auto`** unless you
  have confirmed a ruleset with required checks is active on this repo — with no required checks,
  `--auto` merges immediately instead of waiting for CI.

## Hard Gate

- command: `composer ready` (ide-helper regen + rector + pint + phpstan + pest + composer audit)
- extra suites: none
- monorepo: no. The client package splits out later; do not pre-build a package layout.

The coordinator verifies the hard gate on the committed SHA with a clean tree.

## CI

- status: verified
- required jobs: `ci (8.5)` (Rector check, PHPStan, Pest, composer audit) and `quality` (Pint).
  Both testing and static analysis are present, which is what permits CI-gated autonomous merging.
- workflows: `.github/workflows/tests.yml` and `.github/workflows/lint.yml`

CI installs free `livewire/flux` and needs no Flux Pro credentials. Do not add a step that does.

## Agent Roles

Fleet bindings come from `~/Herd/brain/agents.json`, resolved at spawn time via
`~/Herd/brain/playbooks/resolve-agent-role.md`.

- implementer / quality reviewer / acceptance judge: resolve the roles, do not hardcode.
- **Deliberate deviation from the starter template**, which asks for runtimes and Solo agent_tool_ids
  here: Solo ids are machine-local and drift on re-add, and a copied harness map goes stale silently.
  Resolve live and confirm the model from the agent's own banner, never from the command string.
- constraints: none specific to this app.

## Ship Details

- branch naming: `feat/<slug>`, `fix/<slug>`
- PR target repo: artisan-build/bobbin
- release or split steps: none yet. The `bobbin-client` package splits out when there is a real
  consumer, not before.

## Plan And Coordination

- plan location: `~/Herd/brain/ideas/2026-09-21-bobbin-prd.md`
- Solo project: bobbin (58)
- run-log: a Solo scratchpad in this project, appended at every transition.
- decision trail: `~/Herd/brain/projects/bobbin/decisions-<task-slug>.tsv` via
  `~/Herd/brain/scripts/log-decision.sh`. Never write a decisions file into this repo.
- mutation debt: `~/Herd/brain/projects/bobbin/mutation-debt.tsv`.

## Built For Cloud

- app role: A Built for Cloud catalog product. Sink is the precedent to learn from for durable ingest, retention and inspection - but Sink must NOT become a shared codebase, and Bobbin must not read as a Sink sibling: Sink captures mail in staging, Bobbin catches production webhooks and forwards them.
- manifest: `config/built-for-cloud.php`, package-owned three-key shape
  (`manifest`, `credentials`, `ui`). Configure it through
  `php artisan bfc:starter:configure --spec=<abs-json> --no-interaction` — the frozen two-command
  contract. Do not hand-edit the shape or flip a contract default to make something pass.
  Verify with `php .claude/skills/bfc-app-manifest/scripts/manifest.php --check --json`.
- deployment: Laravel Cloud, single-tenant, deployed into the customer's own account. Cloud injects
  ALL resource configuration — never set env for a Cloud-provisioned resource. No app ids or secrets
  in this file.

## Stack Notes

- Laravel 13 on `laravel-nodeless`: no Node, no npm, no Vite, no frontend build step. Do not add one.
- `artisan-build/built-for-cloud` is pinned at `^0.17`; the package moves fast, and raising the floor
  is preferred over shimming around it.
- Two traps this fleet has already paid for: test the SEAM (capture tested plus replay tested does not prove a captured request replays correctly - one end-to-end round trip is mandatory), and treat retention/redaction as security-material (a redaction test that still passes with the redaction deleted is the assurance-outruns-enforcement pattern, so include a positive control).
