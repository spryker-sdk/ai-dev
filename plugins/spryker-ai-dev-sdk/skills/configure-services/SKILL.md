---
name: configure-services
description: "Use when changing what infrastructure a Spryker project runs on — disable optional dev services (mail catcher, swagger, dashboard), or applications in a deploy file — or when building a new environment deploy file (deploy.<project>-<env>.yml). A pre-boot wizard step of project start, and the standalone deploy-file lifecycle afterwards."
---

# configure-services

Goal: the platform runs on the engines and apps the project chose. The clone ships **everything enabled**, so every edit here is a **deviation from the shipped `deploy.dev.yml`** — a disable (or a dev-service swap), never an "enable" and never an infra-engine swap. Read `.ai-dev/project-setup.md` → `services` (the block records only deviations; empty/absent = keep the demoshop default untouched).

## Edits (deploy.dev.yml only — multi-env deploy files are a later concern)

- **Infra engines.** There is no `engines` collection — under `services:` each infra service is its own key with its own `engine:` field: `database`, `broker`, `session`, `key_value_store`, `search` (read the real file; don't assume this list). **Engine swaps are not supported — never change an `engine:` value** (no `search: opensearch` → `elasticsearch`, no `session`/`key_value_store: valkey` → `redis`). The shipped set is the Cloud-recommended one and is fixed; the wizard does not ask about it and neither should you. **Read the values, don't infer them** — MariaDB ships as `database.engine: mysql` with `version: mariadb-<x.y>`, so the engine value and the product name differ. What you MAY change is `version:` on an already-shipped engine, when the project pins one. If a developer asks for a swap because their hosting mandates it, say it is unsupported and record it as a follow-up rather than editing the engine. Leave every unlisted service exactly as shipped.
- **Optional dev services** — for each in `services.dev_services_disabled`, remove/comment its whole block under `services:` (mail_catcher, swagger, dashboard, redis-gui, webdriver, scheduler); for each key in `services.dev_services_engine`, change only that service's `engine:` (e.g. mail_catcher `mailpit` → `mailhog`). Everything not named stays on.
- **Applications / APIs** — for each in `services.applications_disabled`, remove/comment its block under `groups.<region>.applications`, where **`<region>` is the region group actually present in the file right now — the shipped one (`EU` on this clone; a dormant `US` group exists only in the `deploy.dev.multi-region.yml` sample), NOT the project's target region token.** This step runs *before* `define-stores` sets the project region, so `groups.NA` (or whatever the project picked) does not exist yet; read the group key from the file, don't assume the state-file `region:` value. Any app may be disabled **except the two fixed apps, `backend-gateway` and `backoffice`** — `backend-gateway` is the primal `HOST_ZED_API` Zed RPC gateway every Yves/Glue business call routes through; `backoffice` is kept mandatory (the E2E baseline exercises admin flows and a project always wants the admin UI). If either appears in `services.applications_disabled`, refuse and flag it. The rest are project choices (`yves` = headless when dropped; `merchant-portal`, `glue`, `glue-backend`, `static`). Everything not listed stays on. Disabling an app removes only its **deploy endpoint** — `backoffice`/`backend-gateway`/`glue-backend` share the one backend (Zed) codebase that `backend-gateway` keeps running, so console, data import and OMS are unaffected (dropping `backoffice` loses the admin UI, not the ability to manage via import/console/Backend API). Spryker ships a `docker.ci.acceptance-no-mp.yml` install recipe, confirming merchant-portal is officially optional.
- **Disabling an app also means pruning its build/asset/cache steps from the install recipes — not just its deploy endpoint.** Removing an application block from `deploy.dev.yml` stops it being *served*, but the install recipes (`config/install/*.yml` — `docker.yml` and any active variant) run that app's frontend/asset/cache commands **unconditionally**, so a disabled app still burns build time on assets nobody serves. For example, disabling `static` (Storybook) without pruning its recipe step still runs `frontend:storybook:build` at boot — the app is unserved but its build time is still spent. For each app in `services.applications_disabled`, find and remove its own build/asset/cache steps from the active recipe(s). Map (verify against the real recipe — names evolve; grep, don't trust this list blindly):
  - `static` → `frontend:storybook:build`. **Grep for the COMMAND, not a section name** — the recipe's section names drift (real recipe had `build-static`, `build-static-production` (`excluded: true`), and `build-static-development`, with storybook in *production* + *development*, not `build-static`). Find every step running the command and prune the ones in active (non-`excluded`) sections.
  - `yves` → `frontend:yves:build`, Yves `assets:install`, `yves router:cache:warm-up`, `yves twig:template:warmer`.
  - `merchant-portal` → `frontend:mp:build`, `router:cache:warm-up:merchant-portal`.
  - `backoffice` → `router:cache:warm-up:backoffice` and BO-only asset/cache steps.
  - `glue` / `glue-backend` → that entrypoint's `GLUE_APPLICATION=… glue assets:install` / `api:generate` / `cache:*` steps.
  **Prune ONLY the disabled app's own UI/asset/cache/build steps — never a step that feeds the shared backend (Zed), console, data import, migrations, or publish** (`backoffice`/`backend-gateway`/`glue-backend` share the one backend that keeps running). When unsure whether a step is UI-only or backend-shared, keep it. `backend-gateway` is never disablable, so its steps always stay. Edit the recipe in place, anchored (same rule as the deploy file — no parse→dump).
- **Preserve standard service naming** — the Cloud go-live checklist requires service naming to match the sample deploy file exactly; do not rename services.
- **No plain-text secrets** — env/placeholder values only.
- Verify the Docker SDK version pin is present (`.git.docker`) and untouched.

Anchored, formatting-preserving edits. Do NOT parse→dump the whole YAML (it would reformat and drop comments) — locate the section and edit in place.

**Never touch `docker.mount`** (the `native` / `docker-sync` / `mutagen` per-OS file-sync blocks) — it is not service config. macOS **must** stay on `mutagen`; native bind-mounts are unusably slow for Spryker on Mac. Change only the service/engine/application keys the interview decided; leave everything else in the file byte-for-byte as shipped.

## Verify & close

`deploy.dev.yml` is valid YAML and passes `docker/sdk bootstrap` without errors (bootstrap acceptance is the real proof, run in boot-and-verify). Update the `configure-services` step.

## Standalone: other deploy-file operations (post-setup, not a wizard step)

The same editing discipline applies to two lifecycle operations after setup — invoke on demand (`docker/sdk bootstrap <file>` is the proof for both):

### A. New environment deploy file
Copy the project's existing deploy file (`deploy.dev.yml`, or the closest env) to `deploy.<project>-<env>.yml`, then edit only what differs per environment. Carry over: top matter (`version`/`namespace`/`tag`/`environment`/`image`), `composer`, `assets`, `regions.<REGION>.services`, `groups.<REGION>.applications`, `services` engines + dev services, `docker`. Per-env deltas from the repo's own samples:
- **dev** (`deploy.dev.yml`): `environment: docker.dev`, `composer.mode: ''`, `assets.mode: development`, `ssl.enabled: false`, `xdebug.enabled: true`, all dev services present, `*.spryker.local` hosts.
- **staging/production** (`deploy.aws-env-template.yml`): `composer: --no-dev --classmap-authoritative`, `assets.mode: production` (+ brotli/gzip), `ssl.enabled: true`, `xdebug.enabled: false`, `docker.mount: baked`, `SPRYKER_HOOK_*` deploy hooks, secrets as `CHANGE_ME`, real-ip/basic-auth YAML anchors on frontend endpoints, trimmed dev services (mail only), `*.cloud.spryker.toys` hosts.
- **multi-region**: add a second `regions.<REGION>` block and a matching `groups.<REGION>` (own hosts, own session namespaces, distinct `broker`/`key_value_store`/`search` namespaces) — see `deploy.dev.multi-region.yml`.

**Cloud service naming (go-live requirement):** for any Cloud-bound env, application group keys and service names must match the standard sample-file naming — do **not** carry over the dev file's custom keys (`yves_eu`, `mportal_eu`, flagged there with `# incorrect for the cloud usage`). `backend-gateway` must always be present.

### B. Service change on an existing deploy file
Disable an application or dev service (or swap a dev service) on any deploy file — never an infra engine — the **exact rules from the Edits section above**, applied post-setup rather than pre-boot. Same recipe-pruning for a disabled app's UI/asset/build steps (`config/install/*.yml`); never a step feeding the shared backend/console/import; never `backend-gateway`.
