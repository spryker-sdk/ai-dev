---
name: spryker-feature-install
description: >
  Install a Spryker feature into the project end to end, from a capability card or a feature name:
  "install the X feature", "add spryker-feature/x", "integrate feature X", "install the X Glue API",
  after find-existing-capability proposed an install, or a feature that spryker-upgrade Phase 6
  accepted. Resolves the feature, its required features and the release it needs (moving only the
  modules it needs); parses the official install guide into a checklist with one row per step;
  reconciles every row with the reference demo shop (plugin subset and order, merged config, files
  the guide does not name); applies it in the project layer one guide section at a time; suggests
  storefront placement; verifies each guide Verification block; and finds and proves the release
  steps existing environments need (imports, republish, environment variables). Always confirms
  before changing anything; never edits vendor/ or generated code; never auto-commits.
---

# Spryker Feature Install

Take a Spryker feature from "not in the project" to "installed, wired, verified and releasable".
The goal is the documented integration, not a lookalike: a feature that ships is installed, then
extended, not rebuilt. Typical entry: `find-existing-capability` returned `not_installed` or
`partially_installed` and the developer agreed to install.

## Non-negotiables — read these before Phase 0, re-read them on every compaction

1. **Nothing changes before the developer confirms** the feature, the packages, the release, the
   optional integrations and the branch (gate #1, Phase 3).
2. **One feature per run, at the release the feature needs.** A feature that exists at the
   project's release installs at that release. A feature of a later release may move the modules it
   needs; it may not move another `spryker-feature/*` package — that is a release upgrade, and the
   run stops and offers `spryker-upgrade` (Phase 1).
   *Failure signature:* a `composer require` that moves another `spryker-feature/*` package.
3. **Every guide step is a checklist row, and every row gets a decision.** The guide is never
   followed from the prose. A step the parser cannot classify is a row of kind `unknown`, decided by
   hand. `check-checklist.php` blocks the install while a row has no decision, and the report while a
   row is open. *Failure signature:* an edit to a project file that no checklist row names.
4. **Merge into existing config and plugin stacks; never overwrite them.** The guide shows values
   for a fresh project. A `...` in a guide snippet always means merge.
   *Failure signature:* a diff line that removes a project value the guide does not name.
5. **The reference demo shop decides the plugin subset and order; the project decides its existing
   config.** A guide plugin that the reference does not wire is a question for the developer. A
   plugin of a capability the project does not have (merchants, company accounts, marketplace
   offers) is not a step.
6. **Data rows go to `project-data`.** This skill writes no row in `data/import/**`.
7. **A command the guide runs locally is a release step until a recipe covers it** (Phase 7).
8. **Project layer only, and no shortcuts:** never `vendor/`, `src/Generated/`, `src/Orm/` or a
   hand-edited `composer.lock`; never `dev-*` constraints or `--ignore-platform-reqs`; never a
   suppression; never `docker/sdk reset` or `clean-data`; never a commit or push without the
   developer's yes.

**Plan tracking is mandatory.** After Phase 0, create one task per phase in your harness's task
list, and keep the phase table in `$STATE/state.md`: it survives a compaction.

**Locations.** `$FI` is `<skill-dir>/scripts`, `$UP` is `<skill-dir>/../spryker-upgrade/scripts`,
`$YI` is `<skill-dir>/../yves-twig-integration/scripts`. `<skill-dir>` is the path reported as
`Base directory for this skill:` when this skill loaded. `$STATE` is
`.ai-dev/feature-install/<feature-slug>/` in the project. Substitute each one inline as a literal
path; never set a shell variable and never `cd`. Run every script from the project root.

**Delegation.** Invoke skills by slash command (`/spryker-refresher`, or
`/spryker-ai-dev-sdk:spryker-refresher` on a plugin install). Agent names carry the plugin prefix
on a plugin install, and the separator differs by harness (see
`../spryker-customization/references/delegation.md`). Siblings: `find-existing-capability`,
`spryker-docs-research`, `spryker-refresher`, `project-data`, `yves-twig-integration`,
`boot-and-verify`, `spryker-upgrade`; agents `spryker-verifier` and `spryker-issue-diagnoser`.

## Tools

Match tools by **tool name** (server names vary by install; some clients namespace them
`mcp__<server>__<tool>`).

| Tool | Use |
|---|---|
| `searchSprykerCapabilities`, `getSprykerCapability` | Resolve the feature; the card's Install section, modules and `installStatus` (from `composer.lock`). |
| `searchAlgoliaDocumentation` | Find install guide pages; `github_api_url` is the markdown. |
| `getSprykerModules`, `getSprykerModuleMap` | What the project has now; exact plugin interfaces. |
| `getTransferStructureByName` | Transfer fields before a transfer XML edit. |

If the capability tools are not connected, start the MCP server and ask for a reload as
`find-existing-capability` describes. After two failed reconnects, take the feature name and guide
URL from the developer, or fetch `https://spryker-capability-map.s3.eu-central-1.amazonaws.com/<slug>.md`
and compare its packages with `composer.lock` by hand; the install status is then unverified.

## Phase 0 — Preflight and baselines

1. Run the checks in [references/environment-preflight.md](references/environment-preflight.md). A
   stack that is down, a container that does not see the host files, a `vendor/` behind
   `composer.lock` or a broken frontend dependency tree is a **pre-existing environment issue**, not
   a feature failure. A plan-only run (Phases 1–4) needs no stack.
2. Create `$STATE/state.md` with the phase table (Phase, Status, Evidence). Copy `composer.lock` to
   `$STATE/composer.lock.before`.
3. Before the install (Phase 5), record the baselines. The `$UP` scripts write into the directory
   that `SPRYKER_UPGRADE_STATE_DIR` names:
   ```bash
   SPRYKER_UPGRADE_STATE_DIR=$STATE/baseline php $UP/check-plugin-usage.php || true
   SPRYKER_UPGRADE_STATE_DIR=$STATE/baseline php $UP/check-config-constants.php || true
   SPRYKER_UPGRADE_STATE_DIR=$STATE/baseline php $UP/check-dead-overrides.php snapshot
   SPRYKER_UPGRADE_STATE_DIR=$STATE/baseline php $UP/twig-shadow-map.php snapshot
   script -q /dev/null docker/sdk cli php -d memory_limit=2048M vendor/bin/phpstan analyze -c phpstan.neon src/ -l 6 2>&1 | tee $STATE/baseline/phpstan.txt
   script -q /dev/null docker/sdk cli vendor/bin/console code:sniff:style 2>&1 | tee $STATE/baseline/sniff.txt
   script -q /dev/null docker/sdk testing codecept run --no-exit 2>&1 | tee $STATE/baseline/codecept.txt
   SPRYKER_UPGRADE_STATE_DIR=$STATE/baseline SPRYKER_BACKOFFICE_USER=<user> SPRYKER_BACKOFFICE_PASSWORD=<password> php $UP/backoffice-smoke.php --url <zed base url> --baseline
   ```
   For the storage and search counts, copy `$UP/storage-search-counts.php` into `$STATE/baseline/`
   and run it in the container with `--snapshot before`. A red baseline is not this run's damage:
   record it in `state.md`.

## Phase 1 — Resolve the feature and its release

1. **The feature.** From a card: `getSprykerCapability(slug)`. From a name: `searchSprykerCapabilities`
   with the name and its synonyms, then open the best card. Its Install section lists the
   `spryker-feature/<name>` package and guide URL, `Requires features:`, the Glue API guide and the
   Storefront API packages. A card with several features offers alternatives: take the one that
   matches the project.
2. **What is there.** `installed` → nothing to install: hand back to `find-existing-capability`
   (extend it). `partially_installed` → install only `missingPackages`.
3. **Required features first.** Check each one against `composer.lock`. Each one that is missing is
   its own run, before this one; offer it.
4. **The project type** in `state.md`: B2B or B2C, with or without marketplace. Phase 4 uses it.
5. **The install release.** Read the project's release (`spryker-feature/spryker-core` in
   `composer.lock`) and the releases of the feature (`composer show -a spryker-feature/<x>`):
   - The feature exists at the project's release → install at that release.
   - The feature first exists in a later release → dry-run in the container:
     `composer require spryker-feature/<x>:^<install release> -W --dry-run`. A conflict that names
     a `spryker/*` module and a minimum version → add `spryker/<module>:^<version>` to the same
     `require` and dry-run again, until it resolves. Each major bump among the moved modules needs
     its migration guide, as `spryker-upgrade` Lane 0 describes.
   - Stop and offer `spryker-upgrade` (`/spryker-upgrade`, or `/spryker-ai-dev-sdk:spryker-upgrade`
     on a plugin install) when another `spryker-feature/*` package must move, a conflict names a
     package that is not a `spryker/*` module (a root pin of a Symfony or other third-party
     package), the same package comes back in a later round, or nothing resolves. Name the
     blocking packages from the composer output.
   Record the install release and the added modules in `state.md`.

## Phase 2 — Collect the sources

Read [references/sources.md](references/sources.md) on entry. Put everything in `$STATE/sources/`:
the install guide (the docs page and the include it wraps, plus `_config.yml`), every prerequisite
and linked guide, the `spryker-feature/<x>` `composer.json` at the install release, the reference
demo shop at that release tag, and its history as a bare clone. Record in `state.md` each source that
was not found.

## Phase 3 — Checklist and confirmation (developer gate #1)

1. Parse the guide, then append each prerequisite and linked guide:
   ```bash
   php $FI/parse-integration-guide.php $STATE/sources/docs/<path of the docs page> --docs-root $STATE/sources/docs --release <install release> --out $STATE/checklist.json
   php $FI/parse-integration-guide.php <next guide> --docs-root $STATE/sources/docs --out $STATE/checklist.json --append
   ```
   Exit 1: open each `unknown` row at its `lines` and set its `kind` by hand.
2. `php $FI/check-feature-installed.php $STATE/checklist.json --mark` — modules already installed,
   and wiring rows already present (`already-present`).
3. Give every data row its `data_class` ([references/data-classes.md](references/data-classes.md)).
4. **Present one confirmation block and wait for the answer** (your harness's question tool, else
   in chat). It carries:
   - the feature, the packages `composer require` adds, the install release, the modules Phase 1
     added, and required features to install first;
   - the version constraint in the project's style (`^<release>` for features, `^x.y.z` for
     modules);
   - the optional blocks, one choice each: every `optional-scenario:*` tag, the Glue, Storefront and
     Backend APIs (default: only the APIs the project already exposes), and demo data;
   - that storefront placement comes after the backend steps, as a suggestion list;
   - the environment: a bootstrap when the preflight found the stack down; any pre-existing issue;
   - the branch: `ai-feature/<slug>` from `HEAD` when `git status` is clean; else ask first;
   - commits: one per guide section (recommended), or none until the report.

   Set `skip:<reason>` on every row of a block the developer did not choose.

## Phase 4 — Reconcile

Read [references/reconcile.md](references/reconcile.md) on entry.

1. Add the files the reference changed for the feature that no row names:
   ```bash
   php $FI/find-feature-change.php --repo $STATE/sources/reference-history.git --package spryker-feature/<x> --checklist $STATE/checklist.json --add-rows
   ```
   Exit 1: record that no reference change was found; the guide rows are then the only source.
2. For each row without a decision, compare it with the reference demo shop at the same place. Set
   `reference` (`same`, `differs`, `absent`, with the path) and `decision` (`apply-guide`,
   `apply-reference`, `merge`, `already-present`, `skip:<reason>`) by the reconcile rules.
3. Gate: `php $FI/check-checklist.php $STATE/checklist.json --phase decide` exits 0.

## Phase 5 — Install

1. Run the `composer` rows in the container, with the install release and the modules Phase 1
   added. On a conflict, report it and stop. Then:
   ```bash
   php $FI/check-lock-diff.php --before $STATE/composer.lock.before --checklist $STATE/checklist.json
   ```
   Exit 1 lists packages that moved outside the feature's dependency tree (a module Phase 1 added is
   expected), or a dev constraint. The developer accepts each move, or you narrow the update.
2. When any module moved, check the project's customisations against it:
   ```bash
   SPRYKER_UPGRADE_STATE_DIR=$STATE/baseline php $UP/check-typed-members.php
   SPRYKER_UPGRADE_STATE_DIR=$STATE/baseline php $UP/check-dead-overrides.php verify
   SPRYKER_UPGRADE_STATE_DIR=$STATE/baseline php $UP/twig-shadow-map.php diff
   ```
   A finding is damage from the module move: resolve it as `spryker-upgrade` Lanes 1 and 2
   describe, before the first guide section.
3. Work through the guide sections in guide order; prerequisite guides first. Per section, by
   [references/apply-steps.md](references/apply-steps.md):
   - apply the section's rows by their decision; set `status: done` or `failed` on each;
   - hand the section's data rows to `project-data` (the hand-off format in
     [references/data-classes.md](references/data-classes.md));
   - schema rows: run `propel:diff` and show the migration before `propel:migrate`; the guide's
     `propel:install` is for a fresh local database only;
   - codegen and setup: `spryker-refresher` with the changed files, then the section's own setup
     commands;
   - re-run `check-plugin-usage.php` and `check-config-constants.php`; a new finding blocks the
     next section;
   - commit the section, if the developer chose commits.
4. Gate: `php $FI/check-feature-installed.php $STATE/checklist.json --wiring` exits 0.

## Phase 5b — Storefront placement (optional, suggested)

Only when the feature has Yves code and the project has a storefront. Install guides register
widgets; every vendor template the project overrides has lost the vendor's calls to them.

1. `php $YI/yves-integration-scan.php --package=<vendor/package>,...` for the feature's Yves
   packages.
2. Present its suggestion table; ask which placements to apply (all, some, none). A
   `not_registered` item is an unfinished `widget` row: finish it in Phase 5 first.
3. On a pick, invoke `yves-twig-integration` (`/yves-twig-integration`, or
   `/spryker-ai-dev-sdk:yves-twig-integration`) with the picked items. On "none", record the table as
   a follow-up. In an unattended run, apply nothing: the table goes into the report.

## Phase 6 — Verify (developer gate #2)

1. **Authentication first** for every check that needs a login: one fixture customer login through
   Glue (`POST /access-tokens`) and one through Yves. Test credentials come from
   `tests/cypress-tests/cypress/fixtures/**` and the project's seed files; never ask for or print
   real credentials. A failed login makes the logged-in checks **BLOCKED (environment)**: hand it to
   `spryker-issue-diagnoser`.
2. Give every `verification` row that is not skipped to the `spryker-verifier` agent as one
   acceptance criterion, in the guide's words. Record `verdict` PASS, FAIL or BLOCKED with the
   evidence path. Weigh a FAIL as a hypothesis before acting: browser problems look like feature
   failures.
3. Run PHPStan, the sniffer, the tests, the Back Office crawl and the storage and search counts
   (`--snapshot after`, then `--compare before after`) again, each against its baseline. A new
   failure is this run's damage.
4. `getSprykerCapability(slug)` shows `installed` (or `partially_installed` for API packages skipped
   on purpose).
5. A FAIL goes to `spryker-issue-diagnoser`; fix its root cause as a checklist row. A FAIL or
   BLOCKED that stays goes to the developer: fix it, or accept it (`verdict: ACCEPTED` with a
   `verdict_note`: who and why).

## Phase 7 — Release steps

Do [references/release-steps.md](references/release-steps.md) exactly, in its order: list the
candidates with `php $FI/diff-release-steps.php $STATE/checklist.json`, write each uncovered one into
`$STATE/release-steps.md`, and prove the log with the comparison procedure, or record why it was not
proven.

## Phase 8 — Report

1. Gate: `php $FI/check-checklist.php $STATE/checklist.json --phase report` exits 0.
2. Write `$STATE/report.md` in the shape that
   [references/release-steps.md](references/release-steps.md) § The report gives: installed,
   differences from the guide, data, storefront placement, verification, release steps, not verified.
3. Leave uncommitted changes unstaged for review. Stage only the files you changed, by path, and
   commit or push only on the developer's yes.

## Hard rules

- All state lives in `$STATE`. Never commit `$STATE/sources/` or the baselines.
- Offline, or a source not found → say so, record it in `state.md`, and stop for that source. Never
  write a step from memory.
- A step is applied once. A row marked `already-present` is checked, not applied again.
- Never mark a row done without evidence, and never drop a failing row silently.
- Never claim "verified" for a row whose verdict is not PASS; an accepted row is reported as
  accepted, with its note.
