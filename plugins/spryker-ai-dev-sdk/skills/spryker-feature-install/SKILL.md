---
name: spryker-feature-install
description: >
  Install a Spryker feature into the project end to end, from a capability card or a feature name:
  resolve the feature metapackage and its required features, confirm scope with the user, fetch the
  official install guide, `composer require`, apply the guide's integration steps in the project layer
  only (config, dependency-provider plugin wiring, transfers, schema, navigation, routes, glossary,
  data import), run codegen and setup, verify, and report done / skipped steps. Use when the user says
  "install the X feature", "add spryker-feature/x", "integrate feature X", "install the X Glue API",
  or after `find-existing-capability` proposed installing a feature instead of building one. Always
  confirms before changing anything; never edits vendor/ or generated code; never auto-commits.
---

# Spryker Feature Install

Take a Spryker feature from "not in the project" to "installed, wired and verified", following the **official install guide** for that feature. The goal is the documented integration, not a lookalike: a feature that ships should be installed, then extended, not rebuilt.

Typical entry: `find-existing-capability` returned `not_installed` or `partially_installed` for a capability and the user agreed to install it. Also used standalone ("install the Cart feature", "add `spryker-feature/wishlist`").

Delegate with your harness's subagent tool; invoke skills by slash command (`/spryker-refresher`, or `/spryker-ai-dev-sdk:spryker-refresher` on a plugin install). **Agent names carry the plugin prefix on a plugin install and the separator differs by harness** (`spryker-ai-dev-sdk:spryker-verifier` or `spryker-ai-dev-sdk/spryker-verifier`); try the bare name only on a setup install, and if it does not resolve retry with your harness's prefixed form (see `../spryker-customization/references/delegation.md`).

## Tools

Match tools by **tool name** (server names vary by install; some clients namespace them `mcp__<server>__<tool>`).

| Tool | Use |
|---|---|
| `searchSprykerCapabilities`, `getSprykerCapability` | Resolve the feature and read the card's Install section, modules and `installStatus` (computed from `composer.lock`). |
| `searchAlgoliaDocumentation` | Find the install guide pages; returns `github_api_url`s for the markdown. |
| `getSprykerModules`, `getSprykerModuleMap` | What the project has now; exact plugin interfaces and stack positions. |
| `getTransferStructureByName` | Check transfer fields before editing a transfer XML. |

If the capability tools are not connected, start the MCP server and ask for a reload the way `find-existing-capability` describes; after two failed reconnects take the feature name and guide URL from the user, or fetch `https://spryker-capability-map.s3.eu-central-1.amazonaws.com/<slug>.md` with the web fetch tool and compare its packages with `composer.lock` by hand, and say that the install status is then unverified.

## Step 0 - Environment preflight

The install needs a running, in-sync stack (composer in the container, codegen, `propel:install`, verification), and the planning steps below do not. Run the checks in [references/environment-preflight.md](references/environment-preflight.md) before Step 2, so a stack that is down, a container that does not see the host files, a `vendor/` behind `composer.lock` or a broken frontend dependency tree is reported as a **pre-existing environment issue** and not mistaken for a feature failure. Reading the card and the guide needs no stack; skip the boot for a plan-only run.

## Step 1 - Resolve the feature

1. From a card: `getSprykerCapability(slug)`. From a name: `searchSprykerCapabilities` with the name and its synonyms, then open the best card. Read the **Install** section; it lists:
   - `Feature:` the metapackage (`spryker-feature/<name>`) and its docs install guide URL, or "no install guide found" - then search the docs with `searchAlgoliaDocumentation` ("install the <name> feature") and, without a guide, tell the user the integration steps come from the module code and the feature's other guides, not from an official guide;
   - `Requires features:` other features that must be installed first;
   - `Glue API:` the guide for the Glue API integration, when one exists;
   - `Storefront API (when the project exposes it):` the `*-rest-api` / `*-storefront-api` packages.
2. Read `installStatus`, `features[]` and `missingPackages`. `installed` means there is nothing to install: say so and hand back to `find-existing-capability` (extend it). `partially_installed` means install only what is missing.
3. **Required features first.** Check each feature in `Requires features:` against `composer.lock` / `getSprykerModules`. For each one that is missing, resolve its card the same way and offer to install it first (recursively). Spryker Core and other base features are usually present already; verify, do not assume.

## Step 2 - Confirm before changing anything

Present one confirmation block and wait for the user's answer. It carries:

- the feature, the packages `composer require` will add, and any required features that would be installed first;
- the **version constraint**: match the project's other `spryker-feature/*` constraints and release line in `composer.json` (for example the same `^202xxx.x` style); do not introduce a different style. For module packages the project pins as `^x.y.z` (the `*-rest-api` entries, for example), the explicit constraint is the resolved version, see Step 4;
- the **optional integrations** the user wants: Glue API, Storefront API, Back Office, data import with demo data. Default to the project's existing surfaces (an API the project does not expose is not installed);
- that the **storefront placement** (Step 6b) comes after the backend steps as a suggestion list: the guide registers the feature's widgets but does not place them in the project's Twig templates, and nothing is placed unless the developer picks it then;
- which guide will be followed and its version line;
- the **environment**: when the preflight found the stack down or not bootstrapped, propose the bootstrap here (the deploy file, `docker/sdk boot <deploy file>` plus `docker/sdk start` or `docker/sdk up`, port clashes with other running stacks; see the reference) and list any pre-existing environment issue it found; never `reset`;
- the branch: work on a dedicated branch (`ai-feature/<slug>` from `HEAD`, as `spryker-customization` does with `ai-customize/<slug>`) when `git status` is clean; if it is not, ask first.

No `composer require` and no edit before this answer.

## Step 3 - Fetch the install guide

The Install section gives a docs URL (usually `/latest/` or a versioned path). Read the page by:

- the `spryker-docs-research` skill, or
- `searchAlgoliaDocumentation` and the markdown at its `github_api_url` (the docs markdown lives in `github.com/spryker/spryker-docs`), or
- the web fetch tool on the page itself.

**Version match.** When the URL is `/latest/` and the project's release line (from `composer.json` and the installed `spryker/*` versions) is older than the docs' current one, prefer the guide for the project's line if the docs site offers it; if you cannot tell which line a step belongs to, say so and ask rather than apply a step that may not exist in the project's modules. Treat the guide as the source of truth for **steps and order**, but docs are not trusted for exact identifier spellings: confirm class, constant and transfer names against the installed packages in `vendor/` (read-only) or `getSprykerModuleMap` before writing them. Check every plugin the guide names for `@deprecated` in its vendor class: use the named replacement, skip a deprecated plugin that has none, and record each case in the checklist.

**No guide, or a guide that assumes a base feature that has none** (the marketplace guide assumes the base wishlist wiring; the card says "no install guide found" for the base feature): take the project-layer wiring from a reference project that ships the feature, the suite or the demo shop of the same release line, read through `gh api` or raw GitHub, or from a project on disk. Verify every class against `vendor/`, and record the reference source in the checklist.

Turn the guide into a **checklist** of its steps (install, integrate, verify), grouped as the guide groups them. This checklist is the run's state: every step ends `done` or `skipped (reason)`.

## Step 4 - `composer require`

Run inside the project's PHP environment. On Docker SDK that is `docker/sdk cli composer require <package>:<constraint>`, the form `spryker-refresher` and `spryker-upgrade` use (wrap in `script -q /dev/null` where the command needs a TTY). Without Docker SDK, run `composer require` in the project root.

- Pass an explicit `^x.y.z` constraint for every package, matching the project style: for a bare module package use the version the resolver would pick, found with `composer require --dry-run` first and then required as `^<that version>`; a bare `composer require <package>` writes a looser `^x.y` than the project's entries.
- Require the feature metapackage agreed in Step 2, with the agreed constraint. The card's `composerRequire` names the feature only when the card has exactly one; for a card with several features (alternatives such as `wishlist` and `marketplace-wishlist`) it names the missing module packages, so take the feature the user chose from the Install section instead. Require bare module packages only when the capability has no feature metapackage.
- Add the API packages only if the user chose that integration.
- On a resolution conflict, do not loosen constraints or add `--ignore-platform-reqs` on your own. Report the conflict (which package, which constraint) and stop; the cause is often a project behind the feature's release line, which is `spryker-upgrade` territory.
- Never edit `vendor/`; never hand-edit `composer.lock`.

## Step 5 - Apply the guide's integration steps

Apply the guide's steps in order, **in the project layer only**: under the project's namespace directories in `src/` (find them via `composer.json` `autoload.psr-4`; when there is a custom namespace ahead of `Pyz` in `KernelConstants::PROJECT_NAMESPACES`, new and overriding code goes there, extending the `Pyz` counterpart when one exists), plus `config/` and `data/import/`. Never `vendor/`, never `src/Generated/`, never `src/Orm/`. The `spryker-customization` project-layer rules apply; its `rules/` files (plugins, dependency providers, transfers) define the conventions.

Typical step kinds, and the care each needs:

| Guide step | Care |
|---|---|
| Config (`config/Shared/config_default.php`, module config, env overrides) | Read the existing file first; add keys in the surrounding style; no real secrets. |
| Dependency-provider plugin wiring | **Read the current plugin stack first** and insert at the guide's position; respect the existing order. Follow the project's own pattern for stack overrides (`parent::` plus a positional insert anchored on a named neighbour class, never a numeric index). Skip a plugin the project has already registered. |
| Transfer definitions (`*.transfer.xml`) | Project layer; check fields with `getTransferStructureByName`. |
| Propel schema (`*.schema.xml`) | Project layer; follow `propel-schema` conventions. |
| Navigation (`navigation.xml`), routes, route providers, module configs | Add the entry; do not reorder existing ones. |
| Widget plugins (`ShopApplicationDependencyProvider::getGlobalWidgets()`) | Register the widgets the guide lists. Registration alone renders nothing in templates the project overrides; placing them is Step 6b. |
| Glossary and translations | Add the keys for every configured locale. Guides often have no glossary step, so run `php <skill-dir>/scripts/glossary-key-check.php --module=<Yves modules of the feature>` from the project root (`<skill-dir>` is the path reported as `Base directory for this skill:`): it lists the `'key' \| trans` keys of those modules that no `data/import/**/glossary*.csv` row defines, per locale. Propose rows from the reference project or the suite glossary, append them to the project's glossary file, and report keys with no source; a missing key renders raw on the page. |
| Data import (import config, `data/import/**`, demo data) | Set up the importer wiring when the guide requires it. Load **demo data only if the user asked**; otherwise skip and record "demo data skipped". Use `project-data` and the `data-import` skill for CSV and importer work. |
| Storefront API resources (`resources/api/storefront/*.resource.yml` in a module) | When the module ships them and the project already generates API Platform resources (`src/Generated/Api/Storefront` exists; `config/GlueStorefront` alone is no proof), the guide's `GlueApplicationDependencyProvider` route and relationship plugin steps do not apply: mark them `skipped (API Platform resource)`, keep the attribute-mapper and strategy plugin stacks (the resource mapper reads them through `#[Plugins(dependencyProviderMethod: ...)]`), and rely on code generation in Step 6. |
| Publish and sync, queues, event wiring | Wire as the guide says; queue-worker restarts are project specific, report them. |
| New service in `deploy.dev.yml` (search, key-value, broker) | Do not change the deploy file silently; this usually needs a `docker/sdk up`, which is a rebuild. Ask the user. Never run `docker/sdk reset` or `clean-data`. |

Where a step conflicts with something the project already built (a plugin already registered, a field already present), stop on that step, show the conflict and let the user choose; do not overwrite. Where a step is for an integration the user declined, mark it `skipped (not requested)`.

## Step 6 - Codegen and setup

Invoke `spryker-refresher` with the list of files you changed; it owns the file-pattern to command mapping (`transfer:generate`, `propel:install`, `cache:class-resolver:build`, router cache warm-up, `navigation:build-cache`, glossary and data import, search setup, cache clears, frontend builds). Resolve the Glue applications the project really has before any `glue` command: list `public/Glue*/index.php` and cross-check the `entry-point: Glue*` values in the active deploy file, then run `vendor/bin/glue <command>` for `public/Glue` and `GLUE_APPLICATION=GLUE_BACKEND vendor/bin/glue <command>` for `public/GlueBackend`; use `GLUE_APPLICATION=GLUE_STOREFRONT` only when `public/GlueStorefront/index.php` exists and an entry point uses it. A recipe step (`config/install/docker.yml`) for an application that does not exist is `skipped (no such Glue application)`, not run. Do not improvise the command chain, and run Yves/Zed frontend builds only when the guide touches the frontend. Then run the **feature-specific setup commands the guide lists** that the refresher mapping does not cover (for example a search setup or a feature importer), verifying each command exists first with `docker/sdk console list`.

A new `*.schema.xml` triggers `propel:install`, which changes the database schema: say so before it runs. If a command fails, show its output and diagnose before retrying (`spryker-issue-diagnoser`); do not skip a failing guide step to look green.

## Step 6b - Optional: storefront placement (suggested, not applied by default)

Only when the feature has Yves code (a card module under `vendor/spryker-shop/`, or a package with a `src/*/Yves/` directory) and the project has a storefront. Install guides stop at registering widgets; the vendor templates place them, and every vendor template the project overrides has lost those calls.

1. Run the `yves-twig-integration` scanner scoped to the feature's Yves packages (`--package=<vendor/package>,...`). It lists, per widget and component, whether a template the project renders already uses it, and where the vendor places it in templates the project replaced.
2. Present its suggestion table and ask which placements to apply (all, some, none). `not_registered` items mean a widget from the guide is still unregistered: finish that in Step 5 first.
3. On a pick, invoke the `yves-twig-integration` skill (`/yves-twig-integration`, or `/spryker-ai-dev-sdk:yves-twig-integration` on a plugin install) with the picked items; it places each one in the project's design and verifies it. On "none", record the suggestions as a follow-up and continue.

Do not apply a placement in an unattended run; the table goes into the report.

## Step 7 - Verify

- **Authentication precondition first** for any check that needs a login: one fixture customer login through Glue (`POST /access-tokens`) and one through Yves. Test credentials come from `tests/cypress-tests/cypress/fixtures/**` (customer emails, `defaultPassword`) and the project's seed and fixture files; never ask for or print real credentials. When the login fails, report the logged-in checks as **BLOCKED (environment)** and hand over to `spryker-issue-diagnoser` (a stack problem that predates the install, such as no OAuth token ever issued), instead of failing the feature checks.
- Run the guide's own **verify** steps (the pages, console commands or API calls it names) and record the evidence.
- Use `boot-and-verify` for standalone verification of what the feature touches (storefront, Back Office, search, queues); this is a running stack, not a first boot.
- Hand per-behaviour checks to the `spryker-verifier` agent with the guide's verification steps as acceptance criteria; it only asserts and reports. Weigh a FAIL as a hypothesis before acting (browser reasons look like feature failures).
- Confirm the feature's modules are now reported as installed (`getSprykerCapability(slug)` should show `installed`, or `partially_installed` when you skipped API packages on purpose).

## Step 8 - Report

Leave the changes **unstaged for review**; never commit or push without the user's explicit confirmation, and stage only the files you changed, by path.

```markdown
## Feature install report: <feature>

- Installed: <packages and versions; required features installed first>
- Guide followed: <URL, release line, any version doubt>
- Checklist:
  - [done] <step> - <file or command>
  - [skipped - <reason>] <step>
- Storefront placement: <applied placements (widget - project file), declined, or the suggestion table as a follow-up; "no Yves code" when it did not apply>
- Verification: <what was run, evidence, PASS/FAIL>
- Follow-ups: <e.g. Glue API integration not done; demo data not loaded; deploy/service change needs docker/sdk up; queue worker restart>
- Files changed: <list>
```

## What you do not do

- Do not change anything (composer, files) before the Step 2 confirmation.
- Do not edit `vendor/`, `src/Generated/`, `src/Orm/` or `composer.lock` by hand.
- Do not loosen version constraints or ignore platform requirements to make a `composer require` pass.
- Do not install an optional integration (Glue API, Storefront API, demo data) the user did not choose.
- Do not reorder or overwrite existing plugin stacks, navigation or config; insert at the guide's position.
- Do not run `docker/sdk reset`, `clean-data` or any environment-destructive command; report when one is needed.
- Do not mark a guide step done without evidence, and do not drop a failing step silently.
- Do not commit or push without confirmation.
