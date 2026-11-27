---
name: spryker-feature-install
description: "Installs a Spryker feature end to end from its install guide, checked against the demo shop, with verification and release steps. Use for 'install the X feature' or after a reuse check."
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
8. **The installed feature is covered by tests** in the suites the project runs (Phase 6b). Without
   them, the next upgrade has nothing that notices the feature stopped working. Only the developer
   can waive this, with a reason.
9. **Project layer only, and no shortcuts:** never `vendor/`, `src/Generated/`, `src/Orm/` or a
   hand-edited `composer.lock`; never `dev-*` constraints or `--ignore-platform-reqs`; never a
   suppression; never `docker/sdk reset` or `clean-data`; never a commit or push without the
   developer's yes. Ask first before you write to a database by hand or change a user account.
   Content you write yourself (translations, CMS text) is recorded, and the report lists it for
   review.
10. **The status is `check-dod.php`'s, never yours** (§ Definition of Done). A blocker stops the run
    when you find it (§ Blockers).

## Definition of Done

A feature integration is **DONE** only when all ten items hold, each one with its evidence in
`$STATE`. `php $FI/check-dod.php $STATE` decides it from that evidence.

| # | Item | Evidence |
|---|---|---|
| 1 | The developer confirmed the scope: feature, install release, update mode, branch. | `dod.json` `scope` (Phase 3) |
| 2 | Every baseline ran and holds a result: PHPStan, sniffer, tests, Back Office crawl, storage and search counts. | `baseline/` tool output, `compare-baselines.php` (Phase 0) |
| 3 | Only modules the feature needs moved; every other move has the developer's reason. | `composer.lock.before` vs `composer.lock`, `check-needed-moves.php`; `dod.json` `accepted_moves` (Phase 5) |
| 4 | Every checklist row is decided, and done or skipped with a reason. | `checklist.json` |
| 5 | The decided wiring is in place. | `checklist.json` and the project's DependencyProviders |
| 6 | No regression against the baselines, and the Yves, Zed and Merchant Portal builds pass. | `after/` tool output, `compare-baselines.php` (Phase 6) |
| 7 | Every verification passed. BLOCKED and FAIL are not done; ACCEPTED counts with the developer's note. | `checklist.json` verdicts (Phase 6) |
| 8 | Passing tests cover the feature, or the developer waived them with a reason. | `checklist.json` `test` rows and their files, `tests_waived` (Phase 6b) |
| 9 | Every data file the install wrote is imported by a manifest a recipe runs, and every text you wrote is recorded for review. | `dod.json` `data_files`, `check-data-manifests.php`; `dod.json` `authored_content` (Phase 5) |
| 10 | Every release step is verified, or the developer accepted shipping it unproven. | `release-steps.md`; `dod.json` `release_steps_accepted` (Phase 7) |

Items 2, 3, 5, 6, 8 and 9 are measured from files, not from what you write down. `dod.json` holds
only what no file shows: the scope, the developer's reasons, the texts you wrote, the data files the
hand-off wrote, and waivers (`"waived": {"baseline:<name>": "<reason>"}`, `"check:<name>"`). Its
format is in the header of `scripts/check-dod.php`. Only the developer's answer can make a waiver or
a reason.

**The status is `check-dod.php`'s first line.** While it says `NOT DONE`, every message to the
developer opens with that line and the unmet items, the words "installed", "works", "done" or
"ready" do not describe the run, and nothing is offered for review. The install report
([references/report.md](references/report.md)) is written only after `DONE`.

## Blockers

A blocker is anything that stops a Definition of Done item from being met and that the install
itself cannot fix: a login that fails, a baseline that cannot run, a missing frontend dependency
tree, a check that needs a decision or data only the developer has.

1. **Stop when you find it**, in the phase where you find it. Do not continue with the next phase
   and report it at the end.
2. Record it in `dod.json` (`blocked: <why>`) and in `state.md`.
3. Ask the developer, in one question: what blocks which item, and the options — fix it (with what
   you need from them), waive the item with their reason, or stop the run.
4. Continue only on their answer.

**Plan tracking is mandatory.** After Phase 0, create one task per phase in your harness's task
list, and keep the phase table in `$STATE/state.md`: it survives a compaction.

**Locations.** `$FI` is `<skill-dir>/scripts`, `$UP` is `<skill-dir>/../spryker-upgrade/scripts`,
`$YI` is `<skill-dir>/../yves-twig-integration/scripts`. `<skill-dir>` is the path reported as
`Base directory for this skill:` when this skill loaded. `$STATE` is
`.ai-dev/feature-install/<feature-slug>/` in the project. Substitute each one inline as a literal
path; never set a shell variable and never `cd`. Run every script from the project root.

**Tool output.** `run-tools.php` prints one line per tool and writes the full output to files in
`$STATE`; `compare-baselines.php` prints one line per check. Read these lines. Open a log file
only for the failing part and search it for that part; never read the whole file.

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

**If the capability tools are not available**, tell the two causes apart, as `find-existing-capability`
describes:

- **Not registered** (no `ai-dev:mcp-server` entry in `.mcp.json` or `.vscode/mcp.json`, and the
  harness's MCP server list does not show it): go straight to the script below. There is nothing to
  reconnect.
- **Registered but not connected:** start the server and ask the developer for a reload. After two
  failed reconnects, use the script.

The script searches the same capability map and reads `composer.lock`. Run it from the project root,
one quoted argument per query (the feature name and its synonyms):

```bash
php <skill-dir>/../find-existing-capability/scripts/capability-search.php "recurring orders" "subscription order" "scheduled reorder"
```

Per hit it prints `slug`, `installStatus`, `features`, `missingPackages`, `composerRequire` and
`url`. In place of `getSprykerCapability`, fetch the hit's `url`: it is the card. Without PHP or
network, use the web fallback of `find-existing-capability` (`INDEX.md`). **Never guess a card slug
or a package name.** Record in `state.md` that the search ran through a fallback.

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
   SPRYKER_UPGRADE_STATE_DIR=$STATE/baseline php $UP/run-tools.php baseline phpstan sniffer tests --tests-args=--no-exit
   SPRYKER_UPGRADE_STATE_DIR=$STATE/baseline SPRYKER_BACKOFFICE_USER=<user> SPRYKER_BACKOFFICE_PASSWORD=<password> php $UP/backoffice-smoke.php --url <zed base url> --baseline
   ```
   `run-tools.php` writes `phpstan-baseline-run.txt`, `sniff-baseline.txt` and
   `codecept-baseline.txt`, and pins the invocation in `tool-invocation.json` for Phase 6. It detects
   the sniffer entry point; pass `--sniffer "<command>"` when it names the wrong one,
   `--env NAME=VALUE` for each environment variable the developer sets for the tests, and
   `--codecept-options "<options>"` once for each test configuration the project runs.
   For the storage and search counts, copy `$UP/storage-search-counts.php` into `$STATE/baseline/`
   and run it in the container with `--snapshot before`.
4. Check the baselines: `php $FI/compare-baselines.php $STATE` — every line under "Baselines" is
   `ok`. Red tests and existing findings are a valid baseline: they are what the project had before.
   A baseline that did not run, or that an environment problem made fail (a failed login writes no
   crawl baseline), is a blocker: stop and ask now (§ Blockers). Only the developer can waive it.

## Phase 1 — Resolve the feature and its release

1. **The feature.** From a card: `getSprykerCapability(slug)`. From a name: `searchSprykerCapabilities`
   (or the search script, § Tools) with the name and its synonyms, then open the best card. Its Install section lists the
   `spryker-feature/<name>` package and guide URL, `Requires features:`, the Glue API guide and the
   Storefront API packages. A card with several features offers alternatives: take the one that
   matches the project.
2. **What is there.** `installed` → nothing to install: hand back to `find-existing-capability`
   (extend it). `partially_installed` → install only `missingPackages`.
3. **Required features first.** The candidates are the card's `Requires features:` and the guide's
   Prerequisites table. A prerequisite is **optional** when the guide says so (`(optional)` in the
   table, or the text below the table; the parser records it as `optional: true` in Phase 3) or when
   the feature package's `composer.json` does not require it. Check each one against
   `composer.lock`:
   - a missing required one is its own run, before this one: offer it;
   - a missing optional one: ask whether to install it first. When the developer declines, the
     guide rows that need it are `skip:<feature> not installed`; `check-feature-installed.php`
     reports their classes as not in `vendor/`.
4. **The project type** in `state.md`: B2B or B2C, with or without marketplace. Phase 4 uses it.
5. **The install release.** Read the project's release (`spryker-feature/spryker-core` in
   `composer.lock`) and the releases of the feature (`composer show -a spryker-feature/<x>`). The
   feature exists at the project's release → that is the install release. The feature first exists
   in a later release → that first release is the install release.
6. **Dry-run the install, always, in the container**, also at the project's own release: the
   project's modules are often older than the versions the feature needs, so the require moves
   modules even then. Run it twice and compare:
   `composer require spryker-feature/<x>:^<install release> -w --dry-run` (direct dependencies only)
   and the same with `-W` (all dependencies).
   - A conflict that names a `spryker/*` module and a minimum version → add
     `spryker/<module>:^<version>` to the same `require` and dry-run again, until it resolves.
   - Show the developer the result of both: how many packages each installs, updates and removes,
     every major bump, and every removal. Recommend the one that moves less, unless it does not
     resolve. Each major bump needs its migration guide, as `spryker-upgrade` Lane 0 describes.
   - Stop and offer `spryker-upgrade` (`/spryker-upgrade`, or `/spryker-ai-dev-sdk:spryker-upgrade`
     on a plugin install) when another `spryker-feature/*` package must move, a conflict names a
     package that is not a `spryker/*` module (a root pin of a Symfony or other third-party
     package), the same package comes back in a later round, or nothing resolves. Name the
     blocking packages from the composer output.
   Record in `state.md` the install release, the added modules, and both dry-run results.

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
   - the update mode, `-w` or `-W`, with the moves of each from the Phase 1 dry runs;
   - each missing optional prerequisite: install it first, or skip the rows that need it;
   - the version constraint in the project's style (`^<release>` for features, `^x.y.z` for
     modules);
   - the optional blocks, one choice each: every `optional-scenario:*` tag, the Glue, Storefront and
     Backend APIs (default: only the APIs the project already exposes), and demo data;
   - that storefront placement comes after the backend steps, as a suggestion list;
   - the environment: a bootstrap when the preflight found the stack down; any pre-existing issue;
   - the branch: `ai-feature/<slug>` from `HEAD` when `git status` is clean; else ask first;
   - the tests that will cover the feature (Phase 6b): the suites the project has, and the
     reference tests to port;
   - commits: one per guide section (recommended), or none until the report.

   Set `skip:<reason>` on every row of a block the developer did not choose. Record the answer in
   `dod.json` `scope` (`feature`, `install_release`, `update_mode`, `branch`, and `confirmed`: the
   developer's answer in their words).

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

1. Run the `composer` rows in the container, with the install release, the modules Phase 1 added,
   and the update mode the developer chose. On a conflict, report it and stop. Then:
   ```bash
   php $FI/check-needed-moves.php --before $STATE/composer.lock.before --accepted $STATE/dod.json
   ```
   It judges every moved package: `needed` (a requirer or a conflict rules out its old version),
   `not needed` (its old version satisfies every requirer), or `unknown`. Move each `not needed`
   package back to its version in `composer.lock.before` (`composer require <package>:<old version>`,
   then restore the constraint), and run the check again until only `needed` moves are left. A move
   that stays without being needed needs the developer's reason in `dod.json` `accepted_moves`;
   accepting the update mode at gate #1 is not a reason for each move.
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
     [references/data-classes.md](references/data-classes.md)). Record the files it wrote in
     `dod.json` `data_files`, then `php $FI/check-data-manifests.php <file>...`: each file must be
     imported by a manifest that a deploy recipe runs, for every environment that needs the rows;
   - glossary: `php $FI/glossary-key-check.php --package=<feature package> --locales=<project
     locales>` lists the keys the feature uses or ships that have no row for a project locale;
   - text the guide or the module does not ship (a translation for a project locale, CMS text) is
     content you write (or hand to `translate-content`): record each file in `dod.json`
     `authored_content` (`file`, `what`). The report lists it under "What was validated and how"
     as "not validated — needs a review", like any code you wrote;
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
3. Run the baseline tools again, into `$STATE/after/` with the invocation Phase 0 pinned:
   `SPRYKER_UPGRADE_STATE_DIR=$STATE/after php $UP/run-tools.php after phpstan sniffer tests
   --tests-args=--no-exit --pin-dir $STATE/baseline` (it writes `phpstan-after.txt`,
   `sniff-after.txt`, `codecept-after.txt`); the Back Office crawl without
   `--baseline` (`SPRYKER_UPGRADE_STATE_DIR=$STATE/baseline` as in Phase 0: it marks each failure
   that is in the baseline);
   the storage and search counts with `--snapshot after`, then `--compare before after`. Run each
   frontend build the project has into `after/yves-build.txt`, `after/zed-build.txt`,
   `after/mp-build.txt`, each ending with its exit code
   (`… 2>&1 | tee <file>; echo "exit ${PIPESTATUS[0]}" >> <file>`). Then
   `php $FI/compare-baselines.php $STATE`: every line is `ok`. A new failure is this run's damage.
   For the list of new PHPStan errors, sniffer violations and failing tests, run
   `SPRYKER_UPGRADE_STATE_DIR=$STATE/after php $UP/compare-tool-runs.php after --baseline-dir $STATE/baseline`.
4. `getSprykerCapability(slug)` shows `installed` (or `partially_installed` for API packages skipped
   on purpose).
5. A FAIL goes to `spryker-issue-diagnoser`; fix its root cause as a checklist row. A FAIL or
   BLOCKED that stays goes to the developer: fix it, or accept it (`verdict: ACCEPTED` with a
   `verdict_note`: who and why).

## Phase 6b — Cover the feature with tests

The tests pin what Phase 6 verified, so the next upgrade notices when the feature stops working.
Write them after Phase 6 passes, never before: a test written on a broken install pins the damage.

1. **Find what the project runs.** Functional tests: `codeception.yml` and suites under
   `tests/<Namespace>Test/` (Zed, Client, Yves, Glue). E2E tests: `cypress.config.*` or a
   `tests/cypress*` directory. Use only the kinds the project already has; do not introduce a
   framework.
2. **Start from the reference.** The `test` rows that `find-feature-change.php` added are the
   reference demo shop's tests for the feature. Port each one into the project's namespace, fixtures
   and data, or skip it with the reason (a scenario the developer declined, a capability the project
   lacks).
3. **Cover what the reference does not.** One test per behaviour the feature adds, not per class:
   - backend behaviour (a Verification row with a Back Office, API or database result, a plugin
     stack the feature extends): a functional test through the Facade or the public Client or
     Service, written with `codecept-functional`;
   - an API block the developer chose: a Glue API test in the project's Glue suite;
   - a storefront or Back Office flow, when the project has Cypress: an E2E test with
     `cypress-tests`.

   The rules of [../spryker-upgrade/references/testing.md](../spryker-upgrade/references/testing.md)
   apply: no tests for `*Factory`, `*Config` or `*DependencyProvider` classes, no getter or wiring
   tests, no mocks of the feature's own modules.
4. **Each test is a checklist row** of kind `test`, with the test file as its `target`. Set
   `status: done` only after it passes in the booted environment. Run the project's suite again: the
   new tests must not turn an existing test red.
5. **A project without any test suite:** ask the developer. Only their answer can set
   `tests_waived` in `checklist.json` (who and why); the report then names the feature as not
   covered by tests.

## Phase 7 — Release steps

Do [references/release-steps.md](references/release-steps.md) exactly, in its order: list the
candidates with `php $FI/diff-release-steps.php $STATE/checklist.json`, write each uncovered one into
`$STATE/release-steps.md`, and prove the log with the comparison procedure, or record why it was not
proven. Each log row ends with its status: `verified`, `not needed`, `covered by recipe`, or
`to verify`. When the developer does not allow the proof, ask whether they accept shipping the
unproven steps, and record their reason in `dod.json` `release_steps_accepted`.

## Phase 8 — Report

1. Run `php $FI/check-dod.php $STATE`. Its first line is the run's status.
2. **NOT DONE:** the report opens with that line and the unmet items, each with what the
   developer can do (fix, waive with a reason, accept). Then stop and ask. Do not describe the run
   as installed, working, done or ready.
3. **DONE:** write `$STATE/report.md` with the template and rules in
   [references/report.md](references/report.md). Problems found in the project that this install
   did not cause go to the developer as ticket candidates, not into the report.
4. Leave uncommitted changes unstaged for review. Stage only the files you changed, by path, and
   commit or push only on the developer's yes.

## Hard rules

- All state lives in `$STATE`. Never commit `$STATE/sources/` or the baselines.
- Offline, or a source not found → say so, record it in `state.md`, and stop for that source. Never
  write a step from memory.
- A step is applied once. A row marked `already-present` is checked, not applied again.
- Never mark a row done without evidence, and never drop a failing row silently.
- Never claim "verified" for a row whose verdict is not PASS; an accepted row is reported as
  accepted, with its note.
