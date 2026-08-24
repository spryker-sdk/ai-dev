# Spryker AI Dev SDK — plugin index

Skills and agents for Spryker project development. Invoke a skill as
`/spryker-ai-dev-sdk:<name>`; most are also model-invocable from a matching request.

**Start here.** `/spryker-ai-dev-sdk:ai-dev-setup` installs the `spryker-sdk/ai-dev` Composer
package, wires its console commands, registers the Spryker MCP server, and (optionally) installs
`CLAUDE.md` + `.claude/rules/`. Restart Claude Code afterwards — the running session will not pick
up the new MCP server. Commit what setup changed before running the wizard.

## Starting a new project (fresh, un-booted demoshop clone)

| Skill | What it does |
|---|---|
| `project-starter-wizard` | Collects the nine setup decisions (questionnaire or batched interview) and orchestrates every skill below in autonomous or collaborative mode. |
| `project-ci-generator` | Turns the inherited product/vendor CI into one lean project pipeline, and removes the support files no surviving job references. |
| `configure-services` | Chooses what infrastructure the project runs on — engines, dev services, applications — in the deploy file. |
| `configure-codebase` | Registers a custom namespace instead of `Pyz` across config, composer autoload, frontend build and codeception. |
| `define-stores` | Creates or redefines the DMS store set and region pre-boot, including the hardcoded store/locale literals that block boot. |
| `brand-project` | Applies the brand identity — project name, dev domain, docker namespace, palette, logo. |
| `project-data` | The one skill for `data/import/**`: populate, reshape, reduce, clean up, or remove demo/import data. |
| `boot-and-verify` | Takes a transformed project from "files written" to "verified running", and re-applies data changes on a booted stack. |
| `cypress-migration` | One-time move off Spryker's demo-shop suites onto a project-owned Cypress baseline, with CI wiring. |
| `curate-golive-data` | Pre-go-live pass making kept data production-safe — real tax rates, own imagery, demo accounts removed. |
| `translate-content` | The opt-in per-locale localization pass setup defers — glossary, catalog, CMS, navigation, labels. |

## Working on an existing project

| Skill | What it does |
|---|---|
| `product-requirement-document` | Interactive, research-grounded PRD for a Spryker feature — real actors, real endpoints, business-focused. |
| `spryker-customization` | Implements a customization from a PRD or acceptance criteria, intake to commit, at a chosen quality bar (PoC or MVP). |
| `spryker-bugfix` | End-to-end bug workflow: reproduce → root-cause → fix → tests → static checks → review → QA → verification. |
| `spryker-upgrade` | Upgrades the project's modules/features to a newer release, detecting the damage that fails silently. |
| `spryker-docs-research` | Summarizes official Spryker public documentation. Docs only — never reads the installed codebase. |
| `spryker-refresher` | Runs the post-change console commands (transfers, frontend, caches) in dependency order after edits. |
| `spryker-runtime` | Actually runs the app — log in to Yves/Back Office/Merchant Portal, drive Chrome, run console commands, call endpoints. |
| `data-import` | Create, update, fix, or implement a data import. |
| `spryker-import-tools` | `csv.php` / `validate.php` for manipulating and statically validating import CSVs and manifests. |
| `propel-schema` | Create, update, or review Propel schema definitions. |
| `yves-atomic-frontend` | Create, extend, or override Yves atomic frontend components (atoms, molecules, organisms). |
| `payment-template` | Scaffolds and implements a new PSP / payment gateway integration from the `payment-template` repo. |
| `spryker-profiler` | Reads and configures the Spryker/Symfony WebProfiler — real measurements for "why is this slow". |

## Diagnostics, tests and QA

| Skill | What it does |
|---|---|
| `codecept-functional` | Create, update, fix, or run Codeception functional/unit tests following Spryker conventions. |
| `cypress-tests` | Create, run, review, or validate the project's Cypress E2E specs. |
| `spryker-qa-coverage` | Turns a PRD or feature description into test cases and executes them against the live app, with evidence. |
| `static-validation` | Runs PHP and frontend static analysis over only the changed files (committed, uncommitted, untracked). |
| `code-review` | Reviews code for compliance with Spryker standards. |
| `ai-runtime-debugging` | Inspect runtime state — temporary debug logs the agent can read back, or XDebug stepping. |

## Agents

| Agent | What it does |
|---|---|
| `spryker-feature-expert` | Authority on Spryker features/modules and their canonical primitives. Research only. |
| `spryker-issue-diagnoser` | Diagnoses a failure across logs, DB, queue/search, browser and docs. Diagnostic only, never fixes. |
| `spryker-verifier` | Verifies a behaviour in a running environment; PASS/FAIL/BLOCKED per acceptance criterion with evidence. |
| `spryker-code-reviewer` | Reviews code for compliance with Spryker standards. |
| `spryker-data-seeder` | Creates small additive test-data seeds through the normal data-import path. |
| `spryker-screenshot-collector` | Captures screenshots/GIFs of Spryker pages and flows for demos and docs. |

## Packaging note

These skills also ship inside the `spryker-sdk/ai-dev` Composer package under
`vendor/spryker-sdk/ai-dev/…`, which is Composer-managed — `composer update spryker-sdk/ai-dev` may
overwrite an installed copy. The durable home for edits is this repository
(`github.com/spryker-sdk/ai-dev`).
