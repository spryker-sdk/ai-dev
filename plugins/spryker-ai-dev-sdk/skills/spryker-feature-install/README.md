# spryker-feature-install

Install a Spryker feature into a project **end to end**, from a capability card or a feature name:
resolve the feature, its required features and the release it needs; turn the official install guide
into a checklist with one row per step; reconcile each row with the reference demo shop; apply it in
the project layer; suggest storefront placement; verify; and prove the release steps that existing
environments need.

Where `find-existing-capability` decides "install it instead of building it", this skill does the
install — always after the developer confirms the feature, packages, release and optional
integrations.

## When it triggers

"Install the X feature", "add `spryker-feature/x`", "integrate feature X", "install the X Glue API",
after `find-existing-capability` proposed an install (`not_installed` / `partially_installed`), or a
feature that `spryker-upgrade` Phase 6 accepted.

## Flow schema

```mermaid
flowchart TD
    A([Feature name or capability card]) --> P0["0 - Preflight and baselines<br/>stack, container sync, vendor vs lock,<br/>frontend deps; PHPStan, sniffer, tests, crawl"]
    P0 --> B["1 - Resolve<br/>card: feature, required features, guide<br/>install release; modules the feature needs"]
    B --> UP{"Another feature package<br/>or a root pin must move?"}
    UP -- "yes" --> X(["Stop: offer spryker-upgrade"])
    UP -- "no" --> S["2 - Sources<br/>guide, prerequisite guides,<br/>reference demo shop and its history"]
    S --> C["3 - Checklist and confirmation<br/>parse-integration-guide, check-feature-installed<br/>data classes; developer gate #1"]
    C --> R["4 - Reconcile<br/>find-feature-change adds reference-only rows<br/>decision per row; gate: check-checklist decide"]
    R --> I["5 - Install<br/>composer, check-lock-diff, override checks<br/>one guide section at a time, spryker-refresher<br/>gate: check-feature-installed --wiring"]
    I --> Y["5b - Storefront placement<br/>yves-integration-scan suggestions<br/>yves-twig-integration on the developer's pick"]
    Y --> V["6 - Verify<br/>auth precondition, spryker-verifier per<br/>Verification row, baselines compared"]
    V --> T["6b - Tests<br/>port the reference tests, add functional,<br/>API or Cypress tests the project runs"]
    T --> RS["7 - Release steps<br/>diff-release-steps, prove with<br/>deployed vs fresh comparison"]
    RS --> RP(["8 - Report<br/>status: check-dod DONE or NOT DONE"])
```

## Scripts

`$FI` in every example is `<skill-dir>/scripts`. Run the scripts from the project root;
`SPRYKER_PROJECT_ROOT` overrides it. All run on host PHP, with no Spryker bootstrap.

| Script | Phase | What it answers |
|---|---|---|
| `parse-integration-guide.php` | 3 | which steps does the guide have, of which kind, in which scenario? |
| `check-feature-installed.php` | 3 (`--mark`), 5 (`--wiring`) | which modules and wiring does the project have already; is the decided wiring in place; which plugins belong to modules the project does not install? |
| `find-feature-change.php` | 4 | which pull request brought the feature into the reference demo shop, and which of its files does no row name? |
| `check-checklist.php` | 4 (`--phase decide`), 8 (`--phase report`) | is every row decided; is every row done, every verification passed, and the feature covered by a passing test? |
| `check-lock-diff.php` | 5 | which packages did the install move outside the feature's dependency tree; does a required package have a dev constraint? |
| `glossary-key-check.php` | 5 | which translation keys of the feature's Yves modules have no glossary row? |
| `diff-release-steps.php` | 7 | which commands, imports, republishes and variables does the deploy recipe not cover? |
| `check-dod.php` | 8 | is the integration DONE? Each of the ten Definition of Done items, met or not, from the evidence in the state directory |

`lib.php` is the shared library. The baselines and the override checks reuse
`../spryker-upgrade/scripts/`; storefront placement uses `../yves-twig-integration/scripts/`. The
maintainer test suite is not part of the plugin: it lives in the plugin repository, in
`tests/scripts/feature-install/`.

## State

Everything a run writes is in the project, under `.ai-dev/feature-install/<feature-slug>/`:

| Path | Content |
|---|---|
| `state.md` | The phase table: phase, status, evidence; the install release and added modules |
| `sources/` | The guides, the feature `composer.json`, the reference demo shop clone and its history |
| `checklist.json` | One row per guide step, with its decision, status and verdict |
| `dod.json` | The Definition of Done evidence no script can measure: scope, baselines, accepted module moves, after-install checks, approved content, accepted unproven release steps |
| `composer.lock.before` | The lock before the install |
| `baseline/` | The baselines of the `spryker-upgrade` scripts, PHPStan, sniffer and tests |
| `data-handoff-<section>.md` | The fixed row lists for `project-data` |
| `release-steps.md` | The release-steps log |
| `report.md` | The install report for colleagues, written only when `check-dod.php` says DONE (`references/report.md`) |

## The checklist row

| Field | Content |
|---|---|
| `id`, `guide`, `section`, `lines` | Where the step comes from |
| `kind` | `composer`, `config`, `schema`, `event`, `transfer`, `oms`, `navigation`, `glossary`, `data-row`, `data-import`, `plugin`, `route`, `widget`, `queue`, `search-index`, `frontend`, `code`, `recipe`, `deploy`, `console`, `verification`, `test`, `linked-guide`, `unknown` |
| `tag` | `required`, `optional-scenario:<name>`, `demo-data`, `fresh-install-only` |
| `data_class` | Data rows only: `feature-data`, `project-content`, `demo-data`, or null until decided |
| `target` | The file, the command, the plugin class, or the linked page |
| `plugins`, `packages`, `env` | The plugin classes a snippet wires, the packages a composer line requires, the environment variables a snippet reads |
| `source` | `guide`, or `reference-only` for a file only the reference demo shop changed |
| `reference` | `same`, `differs` or `absent`, with the reference path |
| `decision` | `apply-guide`, `apply-reference`, `merge`, `already-present`, `skip:<reason>` |
| `status` | `open`, `done`, `skipped`, `failed` |
| `verdict`, `verdict_note` | Verification rows: `PASS`, `FAIL`, `BLOCKED`, or `ACCEPTED` with the note |

A plugin row with a class name as its `target` and a `note` comes from a plugin table that has no
wiring snippet in the guide: the guide lists the plugin and shows nowhere how to wire it.

## Design decisions baked in

- **The status comes from the Definition of Done.** `check-dod.php` decides DONE or NOT DONE; a blocker stops the run when it is found.
- **Confirm before changing.** Nothing is required or edited before the developer agrees to the
  feature, release, constraint and optional integrations.
- **Every step is a row; every row is decided.** The checklist gates block the install and the
  report while a row is undecided or open.
- **The reference demo shop decides the plugin subset and order; the project decides its config.**
  The reference is `b2b-demo-marketplace` for every project type; plugins of capabilities the
  project does not have are skipped.
- **The release the feature needs, not the latest.** The feature may move the modules it needs;
  another feature package or a third-party root pin means an upgrade.
- **The guide is the source of truth for steps; the installed code for names.**
- **Project layer only.** Never `vendor/`, `src/Generated/` or `src/Orm/`.
- **The feature is covered by tests** in the suites the project runs; only the developer can waive it.
- **Release steps are proven.** A command the guide runs locally is a release step until a recipe
  covers it.
- **Blocked is not failed.** A failed fixture login makes logged-in checks BLOCKED (environment).
- **No destructive commands, no auto-commit.**
