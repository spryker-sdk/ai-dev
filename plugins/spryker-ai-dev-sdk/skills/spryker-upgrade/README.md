# Spryker Upgrade Tooling

Deterministic detectors for the *silent* failure modes of a Spryker module upgrade on a project
with heavy `src/Pyz` customization. Orchestrated by the `/spryker-upgrade` Claude Code skill
(`SKILL.md` next to this file); the full **78-row coverage matrix** — including the process-level modes
these scripts cannot cover (Propel schema merges, glossary keys, behavioural changes) — is in
[references/coverage-matrix.md](references/coverage-matrix.md). Every script is a standalone CLI,
usable in CI.

All scripts run on host PHP (reflection or static parsing only, no Spryker bootstrap) and keep their
snapshots and reports in `.spryker-upgrade/state/` inside the project, created self-gitignoring on
first use. The one exception is `backoffice-smoke.php`, which needs the booted Back Office.

**Where they live and how to call them.** `$UP` in every example below is the scripts directory:
`.claude/skills/spryker-upgrade/scripts` (setup install) or
`${CLAUDE_PLUGIN_ROOT}/skills/spryker-upgrade/scripts` (plugin install). Run them **from the project
root** — the project is discovered by walking up from the working directory, so nothing is derived
from where the scripts themselves sit. Two overrides exist for CI and for calling from elsewhere:

```bash
SPRYKER_PROJECT_ROOT=/path/to/project        # skip discovery
SPRYKER_UPGRADE_STATE_DIR=/path/to/state     # move baselines/reports (e.g. a CI cache directory)
```

## The eighteen scripts

| Script | Phase | What it answers |
|---|---|---|
| `check-added-comments.php` | 0 (`--record-base`), every lane, 7 | were explanatory comments or suppressions added since the upgrade began? |
| `check-baselines.php` | 0 (gate) | do all Phase 0 baselines exist, so Phase 1 may start? |
| `backoffice-smoke.php` | 0 (`--baseline`), 4.9, 6.5 | does every Back Office navigation page and table data endpoint still answer? |
| `check-platform-alignment.php` | 0, 1.2 | will a lock resolved on this host install on the project's image? |
| `check-test-coverage.php` | 0, 0.5 | which customised modules would not notice if the upgrade broke them? |
| `check-vendor-class-replacement.php` | 0 | does the project declare classes in a vendor's namespace? |
| `check-tooling-alignment.php` | 1.2, 5 | do tooling, the Docker SDK pin and the deploy files match the target release's reference demo shop? |
| `check-constraint-style.php` | 0, 1.5 | which root constraints are patch-locked, exact, or merged in from another repository? |
| `resolve-constraints.php` | 1.5 | what must the root constraints become for the release group to resolve? |
| `unpin-feature-driven-modules.php` | 1.5 | which root pins can go so the feature meta-packages decide (cohort deadlocks)? |
| `list-major-bumps.php` | 3 | which packages crossed a major, arrived, or left? |
| `check-typed-members.php` | 0, 3, Lane 1 | which untyped overrides fatal against PHP 8.3-typed core members? |
| `check-dead-overrides.php` | 0 (`snapshot`), 3 (`verify`) | which overrides lost their vendor method or parent class? |
| `twig-shadow-map.php` | 0 (`snapshot`), 3 (`diff`) | which shadowed templates/assets changed upstream? |
| `merge-shadowed-files.php` | Lane 2 | which shadowed-file conflicts merge cleanly? |
| `check-legacy-css-classes.php` | Lane 2 | which legacy CSS classes does vendor still emit or select? |
| `check-plugin-usage.php` | 0, 3, Lane 3 | which wired plugins are missing or deprecated? |
| `check-config-constants.php` | 0, 3, Lane 4 | which config references point at removed vendor constants/types? |

`bootstrap.php` is the shared library the scripts load. `upgrade-scripts.test.php` is the
maintainer test suite for the scripts (`php $UP/upgrade-scripts.test.php`); it is not part of an
upgrade run.

## What these detectors typically find on an older project

Typical findings, and why each matters:

| Finding | Why it matters |
|---|---|
| Root constraints injected from **another repository** via composer-merge-plugin | Nothing in the project can fix them; composer blames "your root composer.json" for constraints that are not in it |
| Core adopted **PHP 8.3 typed constants/properties** | Every untyped override becomes a fatal on class load — one in a console command aborts `vendor/bin/console` entirely |
| **Tilde-pinned** module constraints | Bumping the feature meta-packages alone can never resolve |
| A **cohort deadlock** (Angular 20, Bootstrap 5) | A family of modules must move together; per-package bumping oscillates |
| **Constructor arity** changes in classes Pyz factories instantiate | Invisible to reflection — only PHPStan catches them |
| A whole module removed in favour of a feature | Its wired plugins vanish, and the extension points it wired register nothing by default in core |
| Most breaking churn lives in `x.1.0` **minors**, not at major boundaries | Scoping review to majors misses most of it |
| Many **shadowed frontend files** changed upstream | Few merge cleanly when the overrides restructured components; the rest need design decisions |
| Two vendor packages that must move together | Every Back Office table can fail while every page the upgrade touched still renders — only a full crawl sees it |

Published migration guides can be stale, absent, or describe a change that is not in the release;
the tag diff is the source of truth.

## Order to run them in

The scripts depend on each other's output; running them out of order produces spurious findings:

```bash
# Phase 0: base ref, detector baselines, then (booted) quality baselines
php $UP/check-added-comments.php --record-base
php $UP/check-platform-alignment.php           # is this host a valid place to resolve at all?
php $UP/check-test-coverage.php                # override surface vs. tests
php $UP/check-vendor-class-replacement.php     # classes declared in vendor namespaces
php $UP/check-constraint-style.php             # patch-locked / merged constraints
php $UP/check-typed-members.php                # must be clean before upgrading
php $UP/check-dead-overrides.php snapshot
php $UP/twig-shadow-map.php snapshot
php $UP/check-plugin-usage.php || true         # record pre-existing damage
php $UP/check-config-constants.php || true
cp composer.lock .spryker-upgrade/state/composer.lock.before
# ... PHPStan, sniffer, evaluator and codecept runs tee'd into the state dir (see SKILL.md Phase 0)
php $UP/backoffice-smoke.php --url <zed base url> --baseline
php $UP/check-baselines.php                    # gate: Phase 1 starts only when this exits 0

# Phase 1.2: tooling, Docker SDK, deploy files
php $UP/check-tooling-alignment.php --reference .spryker-upgrade/state/reference

# Phase 1.5: resolve
php $UP/check-constraint-style.php --relax
php $UP/resolve-constraints.php --max-rounds=8
php $UP/unpin-feature-driven-modules.php --match=...   # only on a cohort deadlock

# Phase 3 and the lanes: detection, then resolution
php $UP/list-major-bumps.php                  # -> migration guide worklist
php $UP/check-typed-members.php               # FIRST: fatals block the console
php $UP/check-dead-overrides.php verify
php $UP/twig-shadow-map.php diff
php $UP/merge-shadowed-files.php --dry-run
php $UP/check-plugin-usage.php
php $UP/check-config-constants.php
php $UP/check-added-comments.php              # end of every lane, and Phase 7

# Phase 4.9 / 6.5: the whole Back Office against the baseline
php $UP/backoffice-smoke.php --url <zed base url>
```

## check-added-comments.php — no explanatory comments, no suppressions

Upgrade code carries no explanatory comments: methods and variables are named so the code explains
itself.

```bash
php $UP/check-added-comments.php --record-base [--force]   # Phase 0: store HEAD as the base ref
php $UP/check-added-comments.php                            # compare against the stored base
php $UP/check-added-comments.php --base <ref>               # compare against an explicit ref
```

`--record-base` writes `base-ref`; a second call keeps the existing ref unless `--force` is given.
Without flags it lists comment lines added since the base in the project's own code (`src/` and
`config/`, excluding generated code) and writes `added-comments-report.json`. Allowed without being
reported: the license header, docblock tags, `{@inheritDoc}`, `Specification:` blocks, and any comment
containing `upgrade-debt`. Suppressions (`phpcs:ignore`, `@phpstan-ignore`, `@psalm-suppress`,
`eslint-disable`, `@ts-ignore`, …) are reported with kind `suppression`: an upgrade fixes each finding
instead of silencing it. Exit 0 clean, 1 findings, 2 no base ref.

## check-baselines.php — the Phase 1 gate

```bash
php $UP/check-baselines.php                   # all baselines required
php $UP/check-baselines.php --no-backoffice   # only when the environment cannot boot
```

Checks the state directory for `base-ref`, `codecept-baseline.txt`, `phpstan-baseline-run.txt`,
`sniff-baseline.txt`, `evaluator-baseline.txt` and `backoffice-smoke-baseline.json`, and that each
holds a real result of its tool: `2>&1 | tee` creates the file even when the command failed. The
check is a codecept summary line (`OK (`, `Tests:`, `FAILURES!`), a PHPStan `[OK]` or `Found N
errors` line, sniffer or evaluator output that is not only a tool error or usage message, a commit
hash in `base-ref`, and a `results` list in the Back Office baseline; an empty file always fails.
The Phase 1.2 re-takes (`codecept-`, `phpstan-`, `sniff-`, `evaluator-post-tooling.txt`) are checked
the same way when they exist. It names the command that produces each missing file or file without
a result. Every "after" check of the upgrade is compared against a "before" run; without it a new
failure cannot be told from a pre-existing one. Exit 0 all present with a result, 1 otherwise.

## backoffice-smoke.php — crawl the whole Back Office

```bash
SPRYKER_BACKOFFICE_USER=... SPRYKER_BACKOFFICE_PASSWORD=... \
  php $UP/backoffice-smoke.php --url <zed base url> [--baseline] [--max 200] [--timeout 30] [--insecure]
```

Logs in, requests every page linked from the Back Office navigation, and every table data endpoint on
those pages. A vendor-to-vendor conflict can break every page with a table while the pages the upgrade
touched still render, so a check limited to the touched pages misses it. `--baseline` writes
`backoffice-smoke-baseline.json` (Phase 0); without it the script writes `backoffice-smoke-report.json`
and marks each problem `inBaseline`. Links that look destructive (delete, remove, cancel, logout,
deactivate) are never followed. Exit 0 ok, 1 any problem, 2 usage error, unreachable URL, login page
without a login form, or failed login; exit 2 writes no report and no baseline.

Table endpoints are requested as a plain GET without DataTables parameters, so a table endpoint that
fails only here is confirmed in the browser (the skill hands that to the `spryker-verifier` agent)
before it is called damage.

## check-tooling-alignment.php — tooling, Docker SDK and deploy files vs the reference

```bash
php $UP/check-tooling-alignment.php --reference .spryker-upgrade/state/reference
```

Compares the project with the reference demo shop of the target release, cloned beforehand
(`git clone --depth 1 --branch <tag>` or `gh`; the script never touches the network): the
`require`/`require-dev` constraints of the tooling set — `phpstan/phpstan` and the PHPStan extensions
(`phpstan/*`, `spryker-sdk/phpstan-*`), `spryker-sdk/evaluator`, `spryker/code-sniffer`,
`phpunit/phpunit` and `codeception/*`; `.git.docker` and whether the `docker/` checkout matches it;
and, for every `deploy*.yml` present in both, `image.tag` and the engine/version of the search,
broker, database, key-value, session and scheduler services. Rows only the project has
(`project-only`) and rows only the reference has (`missing-in-project`) are informational, never a
failure, except a `.git.docker` the project lacks. Report: `tooling-alignment-report.json`. Exit 0
aligned, 1 differs, 2 bad reference.

## check-test-coverage.php — is the upgrade verifiable?

Everything a Spryker project overrides fails *quietly* when core moves: a dead override still loads,
a replaced plugin stack still boots, a stale template still renders. The question this script
answers is therefore which customisations have a test that would notice if they stopped working.

```bash
php $UP/check-test-coverage.php            # gaps, highest risk first — exit 1 on HIGH+uncovered
php $UP/check-test-coverage.php --all      # every customised module
php $UP/check-test-coverage.php --top=50
```

Per `<Layer>/<Module>` it measures the risk surface — methods overriding a vendor parent (split into
**logic** overrides and **wiring** overrides in `*DependencyProvider`, `*Config` and `*Factory`),
vendor plugins registered in dependency providers, and shadowed templates — then attributes coverage
from `tests/*Test/<Layer>/<Module>/` directories containing real `*Cest.php`/`*Test.php` files, plus any
test file referencing a `Pyz\<Layer>\<Module>\` class. Module test directories holding only `_support/`
helpers are reported as `supportOnlyTestDirs` and counted as **uncovered** — they look like coverage
and assert nothing.

Logic overrides dominate the score. Wiring gets no test hint, and a module with only wiring overrides
is LOW risk: a test that asserts which class or value a factory, config or dependency-provider method
returns stays green whatever the upgrade does to behaviour, and dependency-provider stacks are checked
statically by `check-plugin-usage.php`. The hint for a logic override is one test per business
behaviour the override changes, called through `<Module>Facade` (Client and Service through their
public API), run as a functional test in the booted environment. Report:
`.spryker-upgrade/state/test-coverage-report.json`.

Suites that cover API endpoints rather than the override surface are the normal starting point, which
is why Phase 0.5 offers to write tests *before* the upgrade: written afterwards they pin the upgraded
behaviour and can no longer detect that it changed.

## check-vendor-class-replacement.php — classes declared in vendor namespaces

No other detector sees this override style. A file that simply *is*
`Spryker\Zed\Gui\Communication\Table\AbstractTable` has no parent to compare against — it replaces
the class outright, and when it is listed in composer's `autoload.files` it is included eagerly, so
the vendor implementation never loads at all.

```bash
php $UP/check-vendor-class-replacement.php            # exit 1 if a vendor class is replaced
php $UP/check-vendor-class-replacement.php src lib    # extra roots
```

It reads the project's own `autoload`/`autoload-dev` maps to learn which namespace roots the project
legitimately owns, then flags every class declared under `src/` outside them:

- `VENDOR_CLASS_REPLACED` — vendor ships the same FQCN. Reports the vendor path, both line counts and
  a ready `diff -u`, and whether the file is force-loaded (project always wins) or merely competing
  in the classmap (whichever the autoloader dumped first wins — behaviour depends on dump order).
- `VENDOR_NAMESPACE_ADDITION` — a namespace the project does not own, with no vendor file behind it.
  It works until upstream adds a class with that name, then collides. This also catches a file under `src/Pyz/`
  declaring a non-Pyz namespace, which PSR-4 cannot load at all.
- `GLOBAL_NAMESPACE_COPY` — a copy that lost its `namespace` line, so it overrides nothing while
  still being parsed on every request.

Run it in Phase 0. The diff shows what the copy changes and what it lacks: a long copy of a core
class typically differs in a handful of lines, and some of those are vendor improvements the copy is
missing, so upstream changes are discarded before any upgrade starts. Report:
`.spryker-upgrade/state/vendor-class-replacement-report.json`.

## check-constraint-style.php — patch-locked constraints (upgrade blocker)

Run before any composer update. A project pins hundreds of individual modules next to the
`spryker-feature/*` meta-packages; any module pinned `~x.y.z` or exact produces "conflicts with your
root composer.json require" instead of upgrading, so bumping the feature packages alone cannot resolve.

```bash
php $UP/check-constraint-style.php            # report — exit 1 if patch-locked
php $UP/check-constraint-style.php --relax    # rewrite ~/exact -> ^, review the diff
```

Branch/wildcard constraints (`dev-main`, `*`, `@dev`) are reported as *floating* and left alone.
Exactly-pinned **third-party** packages are reported separately and never auto-relaxed — they block
Spryker modules needing a newer version (for example, an exact `"twig/twig": "3.20"` pin blocks a
security-driven bump), but third-party majors carry their own breaking changes, so each is a manual
decision. Constraints merged in by composer-merge-plugin are reported as `mergedConstraints`. Report:
`.spryker-upgrade/state/constraint-style-report.json`.

## resolve-constraints.php — iterative constraint resolution

Conflicts arrive in waves: each root bump reveals the next transitive layer. This script runs a full
composer update, parses the root conflicts, raises those constraints to what the tree demands, and
repeats.

```bash
php $UP/resolve-constraints.php --max-rounds=8 [--dry-run]
```

Every bump is logged to `.spryker-upgrade/state/constraint-resolution-log.json`, and bumps crossing a
**major** boundary are flagged — that flagged list is the migration-guide worklist. It treats
`^0.19 -> ^0.20` as major, because caret on a `0.x` package is major-locked.

The composer runs happen on the host with `--ignore-platform-reqs` and only discover constraints.
When the resolver is done, keep the `composer.json` edits, restore `composer.lock` with git and `vendor/`
with `docker/sdk cli composer install`; the Phase 2 full update in the container is the resolution.

`UNRESOLVED` entries are deliberately left for a human: a third-party/eco package pinning an old core
major, or a transitive dependency blocked by a security advisory with no safe version. The script
never disables composer's advisory blocking to force a resolution.

## unpin-feature-driven-modules.php — break a cohort deadlock

Some majors are cohort migrations, not module migrations: the Angular 20 move bumps the whole
`*-merchant-portal-gui` family at once because it shares `spryker/zed-ui`. With each pinned
individually, composer sees half the cohort demanding `zed-ui ^3` and half `^4`, and no per-package
bump breaks the tie (`resolve-constraints.php` reports OSCILLATION).

```bash
php $UP/unpin-feature-driven-modules.php --match=zed-ui,gui-table,merchant-portal-gui --dry-run
php $UP/unpin-feature-driven-modules.php --all      # every feature-governed module
```

Removes root pins for modules a `spryker-feature/*` package already governs, so the meta-packages
drive those versions — which is how Spryker's own demo shops are laid out. It reads the feature
requirements from **composer.lock**, because feature packages are metapackages and install no files.
Report: `.spryker-upgrade/state/unpinned-modules.json`.

## check-typed-members.php — PHP 8.3 typed constants/properties (fatal on load)

When the target release's core adopts PHP 8.3 typing, an untyped override of a typed constant or
property is a **compile-time fatal**, so it aborts
`vendor/bin/console` itself and every command with it.

```bash
php $UP/check-typed-members.php                                   # src/Pyz
php $UP/check-typed-members.php src/Pyz vendor/<v>/<pkg>/Bundles  # + merged tree
```

Scans **statically** rather than loading classes, for two reasons: PHP reports only the first
offending member per class (hiding the rest until you fix one and re-run), and a class that fatals
cannot be reflected at all. Reports `CONSTANT` (add the parent's type) and `PROPERTY` (usually the
redeclaration only narrowed a docblock and should be deleted — core may promote it as a typed
constructor property, which cannot be redeclared narrower). Report:
`.spryker-upgrade/state/typed-members-report.json`.

## merge-shadowed-files.php — batch three-way merge for Lane 2

```bash
php $UP/merge-shadowed-files.php --dry-run   # classify only
php $UP/merge-shadowed-files.php --apply     # write the clean merges
```

Runs `git merge-file` for every entry in `twig-conflicts-report.json` and sorts the outcomes:
`CLEAN` (applied), `IDENTICAL` (the override matched the old vendor file exactly — it carried no
customisation, so the vendor version is adopted and the override becomes a deletion candidate),
`CONFLICTED` (project file untouched; the conflicted merge is written to `<file>.merge-conflict`
for review) and `REMOVED` (vendor template gone — a semantic decision, never merged).

Expect a low clean rate where overrides restructured components rather than tweaking them.
`*.merge-conflict` files are gitignored review artifacts; never commit them. Report:
`.spryker-upgrade/state/merge-results.json`.

## check-dead-overrides.php — removed vendor methods

PHP lets `src/Pyz` override a method that a new module version deleted — the project
business logic silently stops being called.

```bash
php $UP/check-dead-overrides.php snapshot   # before composer update
php $UP/check-dead-overrides.php verify     # after — exit 1 on conflicts
```

`snapshot` records every Pyz method overriding a `Spryker*` ancestor method. `verify` reports
`OVERRIDE_ORPHANED` (vendor method gone) and `CLASS_BROKEN` (vendor parent class gone). Classes are
reflected in child processes, so one class that fatals on load costs its batch, not the scan. Report:
`.spryker-upgrade/state/dead-overrides-report.json`.

## twig-shadow-map.php — shadowed frontend/presentation changes

Project files fully shadow vendor files via template resolution, so vendor changes never reach
the page. Two shadowing surfaces are mapped:
- Yves themes: `src/Pyz/Yves/<M>/Theme/<theme>/` → `vendor/spryker-shop/<m>/.../Theme/default/`
- Zed presentation (Backoffice twig, OMS mail templates):
  `src/Pyz/Zed/<M>/Presentation/` → `vendor/spryker/<m>/.../Presentation/`

```bash
php $UP/twig-shadow-map.php snapshot   # before composer update
php $UP/twig-shadow-map.php diff       # after — exit 1 on conflicts
```

`snapshot` maps every shadowing project file (twig/scss/ts/js/css) to its vendor counterpart, copies
the pre-upgrade vendor files into `.spryker-upgrade/state/vendor-baseline/` as merge bases, and records
the vendor file listing per scope. `diff` reports `VENDOR_FILE_CHANGED` with a ready-to-run
`git merge-file -p <project> <baseline> <new-vendor>` three-way merge command, `VENDOR_FILE_REMOVED`
for renamed/deleted vendor files, and informational `NEW_VENDOR_FILE` notes for files that appeared in
an overridden module scope (template splits, new sub-components an override may need to reference).

Known limits: assumes vendor theme `default`; scans `src/Pyz` only (not a custom namespace); and a Twig
override that `extends` the core file explicitly (`'@SprykerShop:<Module>'`, `'@Spryker:<Module>/…'`) is
reported like a full shadow although vendor changes outside its blocks still reach the page — review its
blocks, don't text-merge it.

## check-plugin-usage.php — replaced plugin stacks

```bash
php $UP/check-plugin-usage.php   # any time — exit 1 on MISSING
```

Scans all Pyz dependency providers for imported vendor plugin classes and reports:
- `MISSING` — plugin class gone after upgrade (stack replaced/removed); must be rewired.
- `DEPRECATED` — the docblock note usually names the replacement. The skill analyses each one (old vs
  replacement, difference, consequences) and the developer decides at gate #3.
- `PROJECT PLUGINS NEEDING PORTING` — Pyz plugins implementing a deprecated/removed vendor
  interface; these need code porting, not just rewiring.

This is also the check that covers dependency providers — they get no tests. Report:
`.spryker-upgrade/state/plugin-usage-report.json`.

## check-config-constants.php — broken config references

Project configuration (`config/**/*.php`) keys settings by vendor `*Constants` interfaces. An upgrade
that removes/renames an interface or constant breaks bootstrap of every application.

```bash
php $UP/check-config-constants.php   # any time — exit 1 on problems
```

Reports `TYPE_MISSING` (imported/inline vendor type gone) and `CONSTANT_MISSING` (constant gone
— config fatals, or a renamed setting silently stops applying). Report:
`.spryker-upgrade/state/config-constants-report.json`.

## list-major-bumps.php — majors, migration guides, feature gate input

```bash
cp composer.lock .spryker-upgrade/state/composer.lock.before   # during preflight
php $UP/list-major-bumps.php [--json]         # after update — exit 1 on majors
```

Classifies every spryker* package change in the lock diff: `MAJOR` (migration guide mandatory —
emits a docs search URL, the module CHANGELOG URL, and a GitHub compare URL per package),
`minor/patch`, `NEW` (input for the developer opt-in feature gate), `REMOVED` (must be
explained). Migration guides are located via web search
(`site:docs.spryker.com upgrade the <Module> module`) — the docs URLs are per-PBC and not
mechanically derivable. Report: `.spryker-upgrade/state/lock-diff-report.json`.

## check-legacy-css-classes.php — legacy CSS classes vs what vendor actually emits

For releases that cross a CSS framework major (Bootstrap 3 → 5). Rewriting every class the
framework's changelog lists as removed is unsafe: most of those classes are conventions core still
emits, and some are selected by vendor JavaScript, so rewriting them breaks vendor behaviour.

```bash
php $UP/check-legacy-css-classes.php                  # Bootstrap 3->5 default list, Zed
php $UP/check-legacy-css-classes.php --layer=Yves     # storefront instead
php $UP/check-legacy-css-classes.php --classes=a,b    # your own list
php $UP/check-legacy-css-classes.php --verbose        # show the vendor evidence lines
```

It checks whether **vendor, at the installed release, still emits or selects the class itself**,
which is answerable from source with no browser, no compiled CSS and no built assets. Verdicts:

- `MIGRATE` — absent from vendor templates *and* vendor JS. A genuine leftover; safe to convert.
- `KEEP` — vendor still emits it in its own templates, so core styles it deliberately.
- `KEEP (JS)` — **vendor JavaScript selects or toggles it.** Rewriting detaches project markup from
  vendor behaviour. Typical Back Office cases: `has-error` (`gui` `tabs.js` marks invalid tabs),
  `hidden` (`init.js`/`tabs.js` toggle it), `form-group` (`sales-order-threshold-gui` does
  `.parents('.form-group').addClass('hidden')`), `btn-default` (`init.js` swaps it on hover) and
  `control-label` (`discount`'s query builder generates the markup).
- `PAIR` — vendor emits the legacy class *and* its modern equivalent on the same element
  (`nav-item pull-left float-start`). Mirror the pair; do not replace.

It scans **every** Spryker vendor package's `assets/<Layer>/js`, not just the module that seems to
own the behaviour, because the JS dependencies are spread across packages.

Exit 1 only when something is `MIGRATE`. It never rewrites anything: `KEEP (JS)` rows in
particular need a human to leave them alone. If vendor templates cannot be found at all it exits 2
rather than reporting everything as `MIGRATE`, because a false `MIGRATE` verdict leads to breaking
rewrites.

## check-platform-alignment.php — is this host a valid place to resolve at all?

Run this in **Phase 0**, and again in Phase 1.2 after the image tag moved — always before the first
`composer update`. It answers one question: will a lock resolved on this machine install on the
machine the project actually runs on?

```bash
php $UP/check-platform-alignment.php                 # report
php $UP/check-platform-alignment.php --target=8.3.2  # override the detected deployment PHP
```

The mechanism: for example, a project declares `require.php: ">=8.3"` and runs `spryker/php:8.3`. A
host on PHP 8.5 satisfies `">=8.3"`, so composer raises no objection and resolves dev tooling
(`doctrine/instantiator`, `symfony/type-info`, `lcobucci/clock`, `phpunit`) to versions needing PHP
8.4+. The lock installs on that host and in no container:

```
Your lock file does not contain a compatible set of packages.
doctrine/instantiator 2.1.0 requires php ^8.4 -> your php version (8.3.32) does not satisfy that
```

Without the check this surfaces only when `docker/sdk up` fails at `composer install`, several phases
later, and any test run on the host in the meantime ran on the wrong PHP minor.

It reports:

- **host PHP vs every `deploy*.yml` image tag** — and flags deploy files that *disagree* with each
  other, since then there is no single platform to resolve for;
- **`config.platform.php` absent** while the host and deployment minors differ — the actual hazard;
- **`config.platform.php` pointing at the wrong minor**;
- **locked packages that cannot install on the target PHP** — the decisive check, and what
  `docker/sdk up` would otherwise discover at install time;
- **extensions the lock requires that this host lacks** (`ext-redis`, `ext-pgsql`). This is a separate
  failure: a platform pin fixes the PHP *version*, not the *extension set*, so composer cannot resolve
  those packages here at all. The answer is to run composer in the container — never
  `--ignore-platform-req`, which fakes the requirement and re-creates the uninstallable lock.

Target precedence is `--target` → `config.platform.php` → the image tag's `.0` floor. The declared
platform wins because it is what composer actually resolves against; inferring `.0` from an `8.3` tag
otherwise false-positives on any package with a patch-level constraint such as `~8.3.2`.

Exit 1 on anything that can produce an uninstallable lock, 0 when aligned.

## CI wiring

Two of these are cheap enough to gate every PR, and both catch damage that is otherwise invisible
until runtime:

```bash
php $UP/check-typed-members.php     # fatals on class load
php $UP/check-plugin-usage.php      # missing (not deprecated) plugins
```

Neither needs a snapshot, a database or a search backend — just an installed `vendor/`. Failing the
build on exit code 1 stops a change that would return 500s in the Back Office before it deploys.

For dependency-bumping PRs (dependabot, the Spryker upgrader), also wrap the change in a
`snapshot` → `verify` pair for `check-dead-overrides.php` and `twig-shadow-map.php`, since those
compare against pre-upgrade state and cannot work from a single point in time. Where CI boots the
application, `backoffice-smoke.php --baseline` on the base branch and a plain run on the PR catch
vendor-to-vendor conflicts no static check sees.

Note `check-constraint-style.php` exits 1 merely for *reporting* exactly-pinned third-party packages,
which is informational — treat its output as a review item, not a gate.

`check-test-coverage.php` is also cheap enough for CI, but gate it on a *ratchet* rather than on zero:
record `totals.logicOverridesInUncoveredModules` from `.spryker-upgrade/state/test-coverage-report.json`
and fail when a PR increases it. New customisation then arrives with a test, without requiring full
coverage before merging.

## Known limits

- **Yves theme fallback** assumes the vendor theme is `default`.
- **`check-typed-members.php`** resolves the parent from source text; a parent reached through an
  unusual alias or a trait may be missed. It also only inspects classes that declare untyped members,
  so it says nothing about classes that are already fully typed.
- **`check-test-coverage.php`** attributes coverage by module path and by class reference, so a test
  that exercises a customised module only indirectly (through an API endpoint, or via a differently
  named suite) counts as absent. It measures *presence* of tests over the risk surface, never their
  quality or assertion depth — a covered module with one weak assertion still reads as covered.
- **`backoffice-smoke.php`** requests table endpoints without DataTables parameters and follows only
  navigation links; pages reached only through a form or a row action are not visited.
- **`check-tooling-alignment.php`** compares against the one reference demo shop it is given; a
  project derived from a different demo shop needs that one as the reference.
- **`resolve-constraints.php`** cannot decide cohort migrations — it detects the deadlock
  (OSCILLATION / "would LOWER") and hands over to `unpin-feature-driven-modules.php`.
- **No detector covers** Propel schema merges, glossary keys, ACL/navigation for new Backoffice
  routes, or pure behavioural change. Those are Phase 5 process gates and tests, and the matrix marks
  them as such.

## Scope rule these detectors serve

Every detector here answers one question: **did the project's existing behaviour survive the version
bump?** None of them asks whether the project should adopt something new.

An upgrade updates what the project already has; a version bump does not authorise integrating the
architecture, DI mechanism or feature that the new version enables. A capability the project never
used and still does not use is therefore not a finding, and a new version's recommendation is no
reason to adopt something inside an upgrade. When restoring existing behaviour appears to require
adopting something new, report it as a vendor BC break instead of re-architecting. See the Scope
section of `SKILL.md`. The two places where something new is adopted are both
explicit: the tooling/Docker SDK alignment of Phase 1.2 (the release's own toolchain) and the
deprecation replacements the developer chooses at gate #3.
