---
name: spryker-upgrade
description: >-
  Upgrade this Spryker project's modules/features to a newer release. First checks whether the
  project's customisations are covered by tests at all and offers to close that gap, then resolves
  the constraint blockers that stop a release-group bump from resolving, then detects the silent damage
  (PHP 8.3 typed-member fatals, dead method overrides, shadowed Twig/component changes, replaced
  plugin stacks, broken config constants, transfer strict mismatches) and requires a migration
  guide for every major module bump. Trigger on "upgrade the project", "update to latest release",
  "update spryker modules/features", "run the upgrade", or any request to bump spryker-feature/*
  or spryker/* packages.
---

# Spryker Project Upgrade

**Locate the scripts:** `.claude/skills/spryker-upgrade/scripts/` (setup install, relative to the
project cwd) or `${CLAUDE_PLUGIN_ROOT}/skills/spryker-upgrade/scripts/` (plugin install). `$UP`
below is shorthand for whichever resolves — substitute it inline as a literal path; **never set a
shell variable and never `cd`**, both prompt on every call. Always invoke them **from the project
root**: they discover the project from the working directory (override with `SPRYKER_PROJECT_ROOT`)
and write every snapshot and report into `<project>/.spryker-upgrade/state/`, which is created
self-gitignoring so no baseline or merge artifact can ever be committed.

Orchestrated upgrade of a Spryker project to a newer release. Deterministic detection is done by
the fourteen scripts in `$UP/` (see `README.md` next to this skill); your job is to run
the phases in order, do the semantic resolution work, and stop at the decision gates that belong to
the developer.

Start by establishing whether the upgrade can be *verified* at all (Phase 0.5). Everything a Spryker
project overrides fails quietly, so an upgrade with no tests over the override surface is not an
upgrade you can report on — offer to close that gap before moving a single constraint.

**Sibling skills to use rather than reinvent:**
- `spryker-docs-research` — locating and reading migration guides / release notes for Lane 0.
- `codecept-functional` — writing the characterization tests Phase 0.5 asks for.
- `cypress-migration` — E2E coverage for overridden templates (Lane 2's verification side).
- `static-validation` — phpcs/phpstan over the changed files after each lane.
- `spryker-runtime` / `boot-and-verify` — booting and exercising the app once the code resolves.

This was validated end to end on a real 202410.0 → 202606.0 upgrade across five release groups;
`EXAMPLE-UPGRADE-REPORT.md` is that run written up, and the matrix below is what it
actually hit rather than what the docs predicted.

**Environment:**

> **Never conclude the environment is unavailable from a missing or empty `docker/` directory.**
> In most Spryker projects `docker/` is a **git submodule** and an un-initialised submodule looks
> exactly like an absent one — an empty directory. Establish it with three commands before making
> any claim, and re-run them before writing "not verifiable" in a report:
> ```bash
> git submodule status                 # a leading '-' means present but NOT initialised
> git submodule update --init --depth 1 docker
> docker info --format '{{.ServerVersion}} CPUs={{.NCPU}} Mem={{.MemTotal}}'
> ```
> This is not hypothetical: on the validation run the entire Phase 5 verification set was reported
> as "no docker environment on this host" when the submodule simply had not been initialised and the
> Docker daemon had been running the whole time. Everything downstream — migration diff, index map,
> the asset build, every browser check — was reachable. Declaring work unverifiable is a
> load-bearing claim; earn it.

- Prefer running composer and CI-grade checks inside docker: `script -q /dev/null docker/sdk cli
  <cmd>` (a pseudo-TTY is required in non-interactive shells). The detector scripts use reflection
  or static parsing only and run fine on host PHP.
- Without `docker/sdk`, console commands need the environment passed explicitly and a raised memory
  limit — see Phase 5.
- Mutagen two-way sync can resurrect deleted files — delete in the container too, then
  `mutagen sync flush <sync-session>`.
- Never modify files under `vendor/` — all resolution happens in `src/Pyz` and `config/`. The one
  exception is a package whose bundles are merged into the root via composer-merge-plugin: that is
  another repository and must be fixed there (matrix #39/#40).

## Coverage matrix — every upgrade failure mode and what catches it

| # | Use case | Detection | Resolution |
|---|----------|-----------|------------|
| 1 | Overridden vendor method removed/renamed (logic silently unhooked) | `check-dead-overrides.php` (OVERRIDE_ORPHANED) | Lane 1 |
| 2 | Vendor parent class/interface removed → Pyz class fatals | `check-dead-overrides.php` (CLASS_BROKEN) | Lane 1 |
| 3 | Vendor interface gained a method → Pyz implementor fatals | `check-dead-overrides.php` (CLASS_BROKEN) + PHPStan | Lane 1 |
| 4 | Constructor/signature change in classes Pyz factories instantiate | PHPStan (level 6, docker) | Lane 1 |
| 5 | Vendor constant/class removed that `config/*` references | `check-config-constants.php` | Lane 4 |
| 6 | Vendor constant removed that Pyz code references | PHPStan | Lane 1 |
| 7 | Shadowed Yves Twig/scss/ts changed in vendor (change never reaches shop) | `twig-shadow-map.php` (VENDOR_FILE_CHANGED) | Lane 2 |
| 8 | Shadowed Zed Presentation twig (Backoffice, OMS mails) changed | `twig-shadow-map.php` (Zed scopes) | Lane 2 |
| 9 | Shadowed vendor template removed/renamed | `twig-shadow-map.php` (VENDOR_FILE_REMOVED) | Lane 2 |
| 10 | New vendor file in an overridden component scope (template split, new sub-component) | `twig-shadow-map.php` (NEW_VENDOR_FILE, info) | Lane 2 |
| 11 | Frontend build contract changes (ShopUi, webpack, tsconfig, node deps) | `npm run yves` build in docker | Lane 2 |
| 12 | Wired vendor plugin removed → stack replaced | `check-plugin-usage.php` (MISSING) | Lane 3 |
| 13 | Wired vendor plugin deprecated (replacement named in note) | `check-plugin-usage.php` (DEPRECATED) | Lane 3 |
| 14 | Project plugin implements deprecated/removed vendor interface | `check-plugin-usage.php` (PORTING) | Lane 3 |
| 15 | New mandatory plugin wiring required by a feature | migration guide steps (Lane 0) | Lane 0/3 |
| 16 | Major version bump → documented breaking changes | `list-major-bumps.php` (MAJOR) | **Lane 0 — guide mandatory** |
| 17 | Propel schema changes (vendor + project schema XML overrides merge) | `propel:migration:diff` (Phase 5) | developer-reviewed migration |
| 18 | Transfer definition changes (vendor + Pyz transfer XML merge) | `transfer:generate` + PHPStan (Phase 5) | Lane 1 |
| 19 | Data import format/plugin changes | `check-plugin-usage.php` + migration guides | Lane 3 |
| 20 | New infra requirements (ES/Redis/PHP versions, deploy.*.yml) | release notes review (Lane 0) | deploy file update, developer confirm |
| 21 | New Backoffice routes needing ACL/navigation entries | migration guide + `navigation:build-cache` smoke | Lane 0 |
| 22 | Missing glossary keys for new vendor templates | `data:import glossary` + E2E smoke | Phase 5 |
| 23 | Pure behavior change, no API change | Codeception suites (Phase 5) | characterization tests |
| 24 | NEW packages/features arriving with the release | `list-major-bumps.php` (NEW) | **Gate #3 — developer opt-in** |
| 25 | Direct module constraints patch-locked (`~x.y.z`/exact) block feature-driven upgrade | `check-constraint-style.php` | Phase 1.5 |
| 26 | Caret on a `0.x` package is major-locked (`^0.19` excludes `0.20`) | `resolve-constraints.php` (flags `[MAJOR]`) | Phase 1.5 + Lane 0 |
| 27 | Transitive conflicts appear in waves — each bump reveals the next layer | `resolve-constraints.php` (iterative rounds) | Phase 1.5 |
| 28 | Third-party/eco package pins an old core major and blocks everything | `resolve-constraints.php` (UNRESOLVED) | developer decision: update, replace, or drop |
| 29 | `Generated\` transfers absent before `transfer:generate` (false positive) | `check-config-constants.php` (reported separately) | re-check after Phase 5 |
| 30 | Overrides of modules shipped by `spryker-eco`/`spryker-feature`, not `spryker-shop` | `twig-shadow-map.php` (glob-resolved vendor roots) | Lane 2 |
| 31 | Transitive dep blocked by a security advisory | composer output in Phase 1.5 | check the advisory's affected range — usually a **safe newer version exists** and the real blocker is an exact root pin; never disable advisory blocking |
| 32 | Exactly-pinned third-party package blocks a Spryker module needing a newer one | `check-constraint-style.php` (thirdPartyPinned) | manual bump — third-party majors have their own breaking changes |
| 33 | A third-party/eco package has NO release compatible with the target release | `resolve-constraints.php` (UNRESOLVED) + packagist version scan | **developer decision: drop the feature, fork, or wait for upstream** |
| 34 | A whole cohort must bump together (Angular 20 / Bootstrap 5 waves) — per-package bumps deadlock | `resolve-constraints.php` (OSCILLATION / "would LOWER") | `unpin-feature-driven-modules.php`, then let features drive |
| 35 | A *filtered* `composer update` re-uses the lock's root requirements, so removed pins still conflict | phantom "root composer.json require" for a package absent from composer.json | always resolve with a **full** `composer update` |
| 36 | Most breaking churn ships in `x.1.0` **minors**, not at the major boundary | `twig-shadow-map.php` diffs file content over the whole range, not version numbers | Lane 2 — never scope review to major boundaries |
| 37 | A published migration guide is stale or contradicts the release | cross-check the guide against the actual tag diff / release notes | trust the diff; record the discrepancy in the report |
| 38 | A badly out-of-sync `composer.lock` makes composer report **old** root constraints for packages no longer in composer.json | conflict cites a constraint absent from composer.json | back up the lock (`.spryker-upgrade/state/composer.lock.before`), delete it, resolve fresh from composer.json |
| 39 | `composer-merge-plugin` injects root constraints from **another repository** (`extra.merge-plugin.include`) | `check-constraint-style.php` (mergedConstraints) | fix upstream — no downstream edit can override a merged root constraint |
| 40 | Merged includes are read from the **installed** `vendor/` copy, so a fixed upstream branch has no effect until it is installed | conflicts persist citing old constraints even after pointing at a fixed branch | update the providing package **alone, first** (before bumping anything else), then resolve the release group |
| 41 | Core adopts **PHP 8.3 typed class constants / typed properties**; any untyped override is a fatal on class load | `check-typed-members.php` | Lane 1 — add the type, or delete a redeclaration that only narrowed a docblock |
| 42 | PHP reports only the **first** incompatible member per class, hiding the rest | `check-typed-members.php` scans statically instead of loading | fix all reported at once rather than iterating one fatal per run |
| 43 | Core **appends or reorders constructor arguments** in classes Pyz factories instantiate — invisible to reflection | PHPStan (`constructor invoked with N parameters, M required`) | Lane 1 — mirror core's current argument list, including order |
| 44 | Transfer XML `strict` attribute differs from core's, at **transfer or property level** | `transfer:generate` aborts with `TransferDefinitionMismatchException` | Lane 4 — match core; scan **both** levels, one error is reported per run |
| 45 | A deprecated plugin's replacement is **already imported and registered** next to it | swapping produces a duplicate import (PHP lint catches it) | delete the deprecated entry instead of substituting |
| 46 | Two deprecated plugins **consolidate into one** successor, registered in different lists | `check-plugin-usage.php` shows two entries naming the same replacement | decision, not a swap — pick which list keeps it; never batch-apply |
| 47 | A deprecated plugin's successor sits on a **different extension point** | compare the two classes' `Extension`/`Dependency\Plugin` interfaces | move it to the other dependency-provider key; semantics change, so treat as porting |
| 48 | Removing a feature leaves an `{% embed %}` **wrapping** project markup | grep for the removed partial | unwrap — keep the inner block, drop the wrapper; deleting the embed loses the fields |
| 49 | `merge-shadowed-files.php --apply` leaves `*.merge-conflict` review artifacts | `git status` after a Lane 2 pass | gitignored; never commit them — a stray `git add -A` will |
| 50 | PHPStan output is dominated by environment gaps (no DB, no search backend, no bootstrap) | categorise before reading | only errors NOT explained by Propel/IndexMap/`APPLICATION_*` are upgrade damage |
| 51 | Customised module has **no test**, so a dead override / replaced stack fails silently and nothing notices | `check-test-coverage.php` (HIGH risk + uncovered) | **Phase 0.5 — propose characterization tests before upgrading** |
| 52 | Test suite is **already red** before the upgrade, so "tests pass/fail" proves nothing afterwards | Phase 0 baseline suite run, results recorded | compare post-upgrade run against the recorded baseline, never against "green" |
| 53 | A module's test directory holds only `_support/` helpers — looks like coverage, asserts nothing | `check-test-coverage.php` (`supportOnlyTestDirs`) | treat as uncovered |
| 54 | The upgrade breaks the **tests** rather than the app: vendor `SprykerTest` helpers/Testers/fixtures moved or changed signature | `codecept build` + suite run in Phase 5 | fix the test-side usage; this is damage in the harness, report it separately from app damage |
| 55 | A CSS framework major (Bootstrap 3→5) removes classes the project still uses — but most are **vendor conventions core still emits**, and some are selected by **vendor's own JS**, so blind migration causes the outage it was meant to prevent | grep the class in vendor's `*/src/**/<Layer>/**/*.twig` **and** `assets/<Layer>/js` **at the target release** — never reason from the framework's changelog | keep any class vendor still emits or selects; migrate only classes absent from vendor. Where vendor pairs old+new (`pull-left float-start`), mirror the pair |
| 56 | Resolving on the **host** produces a lock that installs only there: the host's PHP is newer than the project's image, so dev-tool dependencies resolve to versions requiring a PHP the container does not have. `require.php: ">=8.3"` does **not** prevent this — a host on 8.5 satisfies it | `docker/sdk up` dies at `composer install` with *"lock file does not contain a compatible set of packages"*; or `composer check-platform-reqs` **inside the container** | set `config.platform.php` in `composer.json` to the deployment PHP (derive it from `image.tag` in every `deploy.*.yml`, and prefer the lowest patch that satisfies the lock, since `spryker/php:8.x` is a floating tag), then **re-resolve inside the container** |
| 57 | The host is not a valid resolution environment at all — it is missing **extensions** the project requires (`ext-redis`, `ext-pgsql`), so composer cannot resolve those packages even with the platform pinned | `composer update` on the host reports *"ext-redis is missing from your system"* | run composer **in the container** (`docker/sdk cli composer …`). Never paper over it with `--ignore-platform-req`: that reintroduces #56 by pretending a requirement is met |
| 58 | Tests pass on host PHP but the project runs a **different PHP minor** — the suite never exercised the deployed runtime | compare `php -v` against `image.tag`; any gap invalidates "tests pass" as an upgrade claim | re-run the suite in the container (Phase 5.5) before reporting any test result |
| 59 | The verification plan assumes a storefront the project does not have (headless / API-only projects ship `zed` + `glue` and no `yves`) | `grep -oE "application: [a-z_]+" deploy.*.yml \| sort -u` | build the browser checklist from the applications that exist; for a headless project the Back Office is the only UI |
| 60 | A **manifest** is bumped but its **lock** is not, so the thing that consumes the lock keeps installing the old version — silently. `package.json` vs `package-lock.json` is the common case: `npm ci` reads only the lock, so a Bootstrap-5 asset toolchain bump never reaches CI | `git diff --stat` shows the manifest changed and the lock did not; or run the asset build and see the lock move | update the lock **by running the installer** (`npm install`, `composer update <pkg>`), never by hand-editing. Any manifest edit verified only by reading is unverified |
| 61 | `propel:install` **deletes tracked files** — it runs migration cleanup, removing `src/Orm/Propel/*/Migration_*` files. Gitignore does not protect them if they were committed before the ignore rule existed | `git status` immediately after Phase 5 | restore with `git checkout --`. Treat every Phase 5 command as potentially write-heavy and diff the working tree after each; never assume regeneration is read-only |
| 62 | Generated artifacts land in a path the project does not ignore, so they show up as untracked noise in the upgrade diff (e.g. `src/Orm/Propel/*/Config/propel.json`) | untracked files appear after Phase 5 | add the path to `.gitignore` next to the sibling generated dirs; never commit generated output into the upgrade branch |
| 63 | A live 500 looks like upgrade damage but the failing call path is **identical in the pre-upgrade versions** | fetch the pre-upgrade file at its exact tag (`raw.githubusercontent.com/<vendor>/<pkg>/<old-version>/…`) and compare the specific method | this is the cheapest way to separate upgrade damage from pre-existing breakage when no baseline exists — do it before blaming the release, and before "fixing" anything |
| 55 | The project declares a class **in a vendor's own namespace** and force-loads it via `autoload.files`, so the vendor implementation never loads at all | `check-vendor-class-replacement.php` (VENDOR_CLASS_REPLACED) | **decide before upgrading** — the copy is frozen at an older version, so upstream changes are already being discarded; re-copy from the target release and re-apply the delta |
| 56 | A copied vendor class **lost its namespace** and sits in the global namespace, so it overrides nothing while still being parsed on every request | `check-vendor-class-replacement.php` (GLOBAL_NAMESPACE_COPY) | verify nothing references the global name, then delete — the vendor class was in use all along |
| 57 | A file under `src/Pyz/` declares a **non-Pyz namespace**, so PSR-4 cannot load it — it resolves only under an optimized/classmap dump, making behaviour differ between dev and prod | `check-vendor-class-replacement.php` (VENDOR_NAMESPACE_ADDITION) + `check-dead-overrides.php` (unloadable) | move it to the namespace's real path or delete it if unreferenced |
| 58 | Characterization tests fail with `Class Generated\Shared\Transfer\* not found` on a fresh checkout | `src/Generated` is gitignored and absent | run `transfer:generate` BEFORE writing any test — not a code problem |

If during the run you meet a failure mode not in this table, add it to the table and to the
detectors (or Phase 5 checks) before finishing the run — the matrix must stay exhaustive.

## Phase 0 — Preflight (abort on failure)

1. `git status` must be clean; create branch `upgrade/<target-release>`.
2. Baseline snapshots (all BEFORE touching composer):
   ```bash
   php $UP/check-dead-overrides.php snapshot
   php $UP/twig-shadow-map.php snapshot
   php $UP/check-plugin-usage.php || true      # baseline: MISSING here = pre-existing damage
   php $UP/check-config-constants.php || true  # baseline: problems here = pre-existing damage
   php $UP/check-typed-members.php || true     # baseline: should be clean before upgrading
   php $UP/check-constraint-style.php || true  # baseline: patch-locked + merged constraints
   php $UP/check-platform-alignment.php || true # is this host even valid to resolve on? (do this FIRST)
   php $UP/check-test-coverage.php || true     # baseline: is the override surface verifiable at all?
   php $UP/check-vendor-class-replacement.php || true  # baseline: classes declared in vendor namespaces
   mkdir -p .spryker-upgrade/state && cp composer.lock .spryker-upgrade/state/composer.lock.before
   ```
   The snapshots must be taken against a COMPLETE vendor tree — run `composer install` first and
   confirm `vendor/autoload.php` resolves a core class, or the maps silently under-record.
3. If baselines already report MISSING plugins, config problems, or unloadable classes,
   surface them: these are pre-existing breaks, not upgrade fallout — typically leftovers from a
   previously removed feature. Offer to fix first; at minimum record the counts so post-upgrade
   reports can be de-noised against them.
4. Baseline quality gates (record, don't fix):
   ```bash
   script -q /dev/null docker/sdk cli vendor/bin/evaluator evaluate
   script -q /dev/null docker/sdk cli php -d memory_limit=2048M vendor/bin/phpstan analyze -c phpstan.neon src/ -l 6
   ```
5. **Baseline test run** — the post-upgrade suite result is meaningless without it, because projects
   routinely start with red or skipped tests:
   ```bash
   script -q /dev/null docker/sdk cli vendor/bin/codecept build
   script -q /dev/null docker/sdk cli vendor/bin/codecept run --no-exit 2>&1 | tee .spryker-upgrade/state/codecept-baseline.txt
   ```
   Record pass/fail/skip counts per suite. If a suite cannot run here at all (no DB, no search
   backend, no webdriver), say which and why — an unrunnable suite is not a passing suite.

## Phase 0.5 — Verifiability gate (developer gate #1)

Do this before touching a single constraint. An upgrade can only be trusted as far as it can be
verified, and the parts of a Spryker project that break *silently* are exactly the parts that
customise core: a dead override still loads, a replaced plugin stack still boots, a stale template
still renders. Nothing turns red — the behaviour just leaves.

```bash
php $UP/check-test-coverage.php              # gaps, highest risk first
php $UP/check-test-coverage.php --all         # full per-module surface
```

It intersects the upgrade risk surface (overridden vendor methods, wired vendor plugins, shadowed
templates, per `<Layer>/<Module>`) with what the test suite actually touches, and ranks the gaps.
Exit 1 means at least one HIGH-risk module has no test at all.

Read it honestly and report two numbers to the developer: how many **business-logic overrides**
exist, and how many of those sit in modules with no test. Then present the options with
AskUserQuestion — this is a decision about risk appetite, not something to decide silently:

- **Write characterization tests for the HIGH-risk gaps first (recommended)** — pin current
  behaviour *before* the upgrade so the tests fail if the upgrade changes it. A characterization
  test written after the upgrade only pins whatever the upgrade produced, which proves nothing.
- **Cover a chosen subset** — typically the modules whose logic is business-critical (checkout,
  pricing, cart, order flow) even if the score ranks something else higher; the developer knows
  which those are.
- **Proceed without new tests** — legitimate for a small step or a project with thin
  customisation, but record it: the final report must then state that the affected lanes were
  verified statically only.

If tests are to be written, hand the writing to the `codecept-functional` skill (and
`cypress-migration` for E2E paths) — this skill decides *what* needs covering, those know the
project's suite conventions. Write them in this order and commit them **on their own, before the
upgrade branch diverges** — they must be provably green against the current version:
1. modules with HIGH risk and business-logic overrides — a test per overridden method's observable
   output, not per class;
2. dependency providers carrying large vendor plugin stacks — assert the *stack contents* (class
   names, order where it matters), since that is what a replaced stack changes;
3. overridden templates — one acceptance/Cypress path per customised page, asserting the project's
   own additions are on the page rather than re-testing core markup.

Keep them characterization tests: assert what the code does today, including quirks. The goal is a
tripwire for the upgrade, not a specification. When a test fails on first run, assume the assumption
was wrong before assuming the code is — an absent array-typed transfer field yields `[]`, not `null`,
and that IS the behaviour to pin.

**Split the worklist by what you can actually prove.** Overrides in `Business/`, `Service/`,
`Client/` are usually pure logic, provable green on plain host PHP with mocked constructor
dependencies. Overrides in `Communication/` (forms, tables, controllers) and `Persistence/` need a
container and a database. Draft the second group, but never report it green from a host that cannot
run it — separate the two counts in the report. Practical notes for a host run:

```bash
vendor/bin/console transfer:generate     # FIRST — src/Generated is gitignored, so a fresh checkout
                                         # fails every test with "Class Generated\...\* not found"
php -d register_argc_argv=On vendor/bin/codecept build -c tests/PyzTest/<Layer>/<Module>
php -d register_argc_argv=On vendor/bin/codecept run   -c tests/PyzTest/<Layer>/<Module>
```
Codeception refuses to start without `register_argc_argv`, and a new module suite needs its own
`codeception.yml`; keep its `modules.enabled` minimal (`Asserts` + the project's Environment helper)
so a unit suite does not drag in Propel or locator helpers it does not need.

## Phase 1 — Target selection (developer gate #2)

Determine current release: `grep -m1 '"spryker-feature/' composer.json`. Find newer releases:
`composer show -a spryker-feature/spryker-core | grep versions`. Ask with AskUserQuestion:
- **Next release group (recommended)** — smallest reviewable step; repeat the skill per release.
- **Latest release** — one big jump; more conflicts per pass.
Never pick silently.

## Phase 1.5 — Constraint style (do this BEFORE the first composer update)

### First: resolve for the project's platform, not the machine you are sitting on

Do this before the *first* `composer update`, because every lock produced without it may be
uninstallable and you will not find out until something tries to deploy.

```bash
php -v | head -1                                            # the machine you would resolve on
grep -hoE 'tag: spryker/php:[0-9.]+' deploy*.yml | sort -u   # what the project actually runs
python3 -c "import json;print(json.load(open('composer.json')).get('config',{}).get('platform','ABSENT'))"
```

If the host PHP minor differs from the image and `config.platform` is absent, **stop and fix that
first**:

```json
"config": { "platform": { "php": "8.3.2" } }
```

`require.php` does **not** protect you — `">=8.3"` is satisfied by a host on 8.5, so composer will
happily select dependencies (usually dev tooling: `doctrine/instantiator`, `symfony/*`,
`phpunit`) that require a PHP the container does not have. Choose the **lowest patch that satisfies
the lock** rather than the image's current patch, because `spryker/php:8.x` is a floating tag and
environments pull it at different times.

Then **run composer inside the container** anyway:

```bash
script -q /dev/null docker/sdk cli composer update …
script -q /dev/null docker/sdk cli composer check-platform-reqs   # must be 0 failures
```

The platform pin fixes the PHP *version*; it does not give the host the project's *extensions*. A
developer machine without `ext-redis` or `ext-pgsql` cannot resolve `spryker/redis` at all. Never
reach for `--ignore-platform-req` to get past that — it re-creates exactly the unrunnable lock the
pin was added to prevent.

On the validation run this was found only when `docker/sdk up` failed at `composer install`, several
phases after the damage was done, and it also meant the whole characterization suite had been
running on the wrong PHP minor.

### Then: the constraint style itself

A Spryker project pins hundreds of individual modules next to the `spryker-feature/*`
meta-packages. Any module pinned with `~x.y.z` (patch-only) or an exact version will produce
"conflicts with your root composer.json require" instead of upgrading, so bumping the feature
packages alone cannot resolve. Same trap for `^0.x` — caret on a `0.x` package is major-locked.

```bash
php $UP/check-constraint-style.php            # report patch-locked constraints
php $UP/check-constraint-style.php --relax    # rewrite ~/exact -> ^ (review the diff)
```

Then bump the feature constraints to the target release and let the resolver iterate — conflicts
arrive in waves, each bump revealing the next transitive layer:

```bash
php $UP/resolve-constraints.php --max-rounds=8
```

It bumps root constraints to what the tree demands, round by round, logs every change to
`.spryker-upgrade/state/constraint-resolution-log.json`, and flags each bump that crosses a major boundary —
that flagged list IS the Lane 0 migration-guide worklist. Review `git diff composer.json`.

**OSCILLATION / "would LOWER"** means a cohort must move together — two halves of a plugin family
demanding different majors of a shared module. Per-package bumping cannot break that tie; hand it to
the un-pinner so the feature meta-packages decide, then resolve again:

```bash
php $UP/unpin-feature-driven-modules.php --match=<substr,substr> --dry-run
php $UP/unpin-feature-driven-modules.php --match=<substr,substr>
php $UP/resolve-constraints.php --max-rounds=8
```
Modules not governed by any feature package are never unpinned, so bump those explicitly to a
version whose own requirements match the cohort (find it from packagist metadata).

UNRESOLVED entries need a human decision and must be surfaced, never worked around:
- a third-party/eco package pinning an old core major — before offering options, check **what it
  actually uses** from the blocking module: if it references a class the new major removed, no
  constraint change can help and it needs upstream code work (Lane 5);
- a transitive dependency blocked by a security advisory. Read the advisory's affected range first:
  usually a safe newer version exists and the real blocker is an exact root pin. **Never** set
  `policy.advisories.block: false` or add ignore-ids to get past this — it is a security decision
  for the developer, and disabling it hides real vulnerabilities.

## Phase 2 — Composer update

1. Confirm every `spryker-feature/*` constraint is on the chosen release.
2. `script -q /dev/null docker/sdk cli composer update "spryker-feature/*" "spryker/*" "spryker-shop/*" "spryker-eco/*" --with-all-dependencies`
3. On resolution failure read `composer why-not`, adjust the blocking constraint, retry.
   No `--ignore-platform-reqs` inside docker; on host it IS needed.
4. Record every constraint the resolver changed — the composer.json diff is part of the review.

## Phase 3 — Detection (run all, collect before resolving)

```bash
php $UP/list-major-bumps.php                   # majors/new/removed classification
php $UP/check-typed-members.php src/Pyz        # exit 1 = PHP 8.3 typed-member fatals
php $UP/check-dead-overrides.php verify        # exit 1 = conflicts
php $UP/twig-shadow-map.php diff               # exit 1 = conflicts
php $UP/check-plugin-usage.php                 # exit 1 = missing plugins
php $UP/check-config-constants.php             # exit 1 = broken config refs
script -q /dev/null docker/sdk cli php -d memory_limit=2048M vendor/bin/phpstan analyze -c phpstan.neon src/ -l 6
```

Run `check-typed-members.php` FIRST: typed-member fatals abort `vendor/bin/console` itself, so
nothing else in Phase 5 can run until they are fixed. If the project uses composer-merge-plugin,
scan the merged tree too — its bundles break the console just as effectively:
`php $UP/check-typed-members.php src/Pyz vendor/<vendor>/<pkg>/Bundles`

Subtract the Phase 0 baseline findings from plugin/config reports (only NEW findings are
upgrade fallout). Present a summary table: packages moved (majors highlighted), conflicts per
lane, NEW packages.

**Reading PHPStan on a host without infrastructure.** Most of its output will be environment
noise, not damage. Categorise before drawing conclusions — errors mentioning `Orm\Zed\*` or
`Spy*EntityTransfer` need `propel:install` + a database, `Generated\Shared\Search\*IndexMap` needs
a search backend, and `Constant APPLICATION_*` needs the Spryker bootstrap. Only what remains after
removing those is upgrade damage. In the reference run that residue was **4 constructor-arity
errors** — real breakage no reflection-based detector can see.

## Lane 0 — Migration guides (MANDATORY for every major bump)

For **each** package in the MAJOR list of `.spryker-upgrade/state/lock-diff-report.json`:
1. Locate the guide — use the `spryker-docs-research` skill, or WebSearch
   `site:docs.spryker.com upgrade the <Module> module` directly. Guides live
   as "Upgrade the <Module> module" pages (e.g.
   `docs.spryker.com/docs/pbc/all/<pbc>/<version>/base-shop/install-and-upgrade/upgrade-modules/upgrade-the-<module>-module.html`).
2. WebFetch the page and extract the sections covering the crossed major boundary (a guide
   covers multiple majors; only the crossed range applies, e.g. 10.x → 11.0 section).
3. Execute every applicable step: interface swaps, constant→config-method moves, plugin
   rewiring, schema/transfer adjustments, console commands. Cross-check each step against the
   detectors' findings — guide steps usually explain WHY a detector fired.
4. If no guide exists (some modules have none), fall back to the module's CHANGELOG.md and the
   GitHub compare URL from the report; extract the `[BC]`/breaking notes for the crossed majors.
5. If neither yields clarity, STOP for that module and surface it to the developer — never
   invent migration steps.
6. Record per module: guide URL (or "none published"), steps applied, steps skipped + why.
   This table goes into the final report verbatim.

## Lanes 1–4 — Conflict resolution

Work lane by lane; commit each lane separately. Re-run all detectors after every lane.

### Lane 1 — Dead overrides & broken classes (`dead-overrides-report.json`, `typed-members-report.json`, PHPStan)

Fix in this order, because each unblocks the next:

**1a. Typed members** (`check-typed-members.php`) — the console will not start until these are gone.
- `CONSTANT`: add the parent's type, e.g. `public const FACADE_X` → `public const string FACADE_X`.
- `PROPERTY`: usually the redeclaration exists only to narrow a docblock type. Core often now
  *promotes* it as a typed constructor property, which cannot be redeclared narrower — so **delete
  the redeclaration** and narrow locally at the usage site instead:
  ```php
  /** @var \Pyz\...\ProjectConfig $config */
  $config = $this->config;
  $config->projectOnlyMethod();
  ```

**1b. Constructor arity** (PHPStan `constructor invoked with N parameters, M required`) — reflection
cannot see this. Compare the project factory's `create*()` against core's and mirror the current
argument list *including order*; core both appends and reorders. Prefer delegating to `parent::` and
adding only the project's extra value, so future core additions flow through by themselves.

**1c. Signature changes** on overridden methods. When core narrows a return type to a bridge that
drops methods the project needs (e.g. a session client exposing only `set`/`remove`), do not fight
it: register the raw dependency under a project key (`PYZ_*`) and expose it via a distinctly named
accessor, leaving core's contract intact. Check whether the project also *overrides the registration*
of that key — if so it may now be handing core the wrong type.

**1d. Dead overrides.** For each OVERRIDE_ORPHANED / CLASS_BROKEN entry:
1. Check Lane 0 first: the migration guide for that module usually names the replacement.
   Otherwise diff the vendor class between versions (GitHub compare URL / composer cache).
2. Resolution preference order:
   a. New extension point exists → move business logic into a project plugin, wire it, delete override.
   b. Logic moved to another method → re-anchor the override against the new structure.
   c. No seam → override the (larger) calling method; mark `// upgrade-debt:`; tell the
      developer an extension-point request to Spryker is warranted.
   d. New core already covers the business requirement → delete the override.
3. Every touched flow needs a test proving behavior survived — write a characterization test
   BEFORE porting if none exists.

### Lane 2 — Shadowed frontend/presentation files (`twig-conflicts-report.json`)

Batch the mechanical part first:
```bash
php $UP/merge-shadowed-files.php --dry-run   # classify
php $UP/merge-shadowed-files.php --apply     # write the clean merges
```
It sorts every conflict into CLEAN (applied), IDENTICAL (the override carried no customisation at
all — delete it), CONFLICTED (left untouched; the merged result is written beside the file as
`<file>.merge-conflict` for review) and REMOVED. **Never commit the `.merge-conflict` files** —
they are gitignored, but a stray `git add -A` will pick them up anyway.

Expect the clean-merge rate to be low. In the reference run only 15 of 163 merged cleanly: the rest
conflict because the override *restructured* the component rather than tweaking it, so no textual
merge can carry the vendor change across. Those need a design decision each, and a worklist is more
useful than a bad merge — generate one rather than forcing it.

1. VENDOR_FILE_CHANGED: run the report's three-way merge command
   (`git merge-file -p <project> <baseline> <vendor-new>`). Clean merge → review and apply;
   conflict markers → resolve semantically (vendor structural changes win, project business
   content wins).
2. Components are triplets — if a `.twig` changed, check sibling `.scss`/`.ts` entries.
3. Small customizations: convert the full override to a block-level extension
   (`{% extends organism/molecule(...) %}` + `{% block %}`) — permanently shrinks the surface.
4. VENDOR_FILE_REMOVED → changelog/guide for the rename; re-point or drop the override.
5. NEW_VENDOR_FILE (info) → check whether the overridden parent template must now include it.
6. Zed Presentation entries (Backoffice twig, OMS mail templates) follow the same merge flow.
7. Rebuild: `script -q /dev/null docker/sdk cli npm run yves` — zero tsc/webpack errors.

### Lane 3 — Plugin stacks (`plugin-usage-report.json`)

**MISSING is the only category that is upgrade damage.** DEPRECATED plugins still work; treat them
as separate maintenance so they cannot destabilise the upgrade.

1. **MISSING vendor plugins** — the replacement comes from the Lane 0 guide or the old class's
   `@deprecated` note (read it from the composer-cache copy of the OLD package, since the class is
   gone from the new one). Rewire in the position/order the guide specifies.
   When a whole module was removed in favour of a feature, check whether the *replacement widgets are
   registered globally* now — the per-page widget-plugin lists that held the old ones may simply be
   deleted. Watch for empty override methods left behind: an override returning `[]` suppresses
   core's own defaults, so delete the method rather than leaving it empty.
   Also carry over the extension points the removed module's dependency provider wired — core
   frequently registers **nothing** by default there, so those plugins are silently lost otherwise.
2. **DEPRECATED vendor plugins** — classify before touching anything:
   - replacement on the **same** extension-point interface, single import, single registration →
     mechanical swap, safe;
   - replacement **already imported and registered** next to the deprecated one → **delete** the
     deprecated entry; substituting duplicates the import and double-registers;
   - two deprecated plugins naming the **same** successor → a consolidation across two lists; pick
     which one keeps it;
   - replacement on a **different** extension point → move it to the other key, and treat it as
     porting because the semantics change;
   - no replacement named → decide whether the behaviour is still wanted.
   Do not batch-apply this category. Compare the two classes' `Extension`/`Dependency\Plugin`
   interfaces to tell "same extension point" from "different", and lint every touched file.
3. **PROJECT plugins needing porting**: characterization test → port to new interface (granularity
   often changes: one plugin may become several strategy plugins) → wire → verify test.

### Lane 4 — Config constants & transfer definitions

**Config** (`config-constants-report.json`): for each TYPE_MISSING / CONSTANT_MISSING the migration
guide names the replacement (typical pattern: `XConstants::FOO` moves to `XConfig::getFoo()` — then
the value belongs in a Pyz config class override, not config_default.php). Apply, and verify with
`script -q /dev/null docker/sdk cli vendor/bin/console config:convert-check || true` plus a
console boot smoke test (`... vendor/bin/console list >/dev/null`).

**Transfer XML.** `transfer:generate` refuses to merge a definition whose `strict` attribute differs
from any other definition of the same thing, and it reports **one violation per run** — so scan for
all of them at once instead of iterating. Check *both* levels, because they are separate failures:

```bash
# property level: <property name="x" strict="true"/>
# transfer level: <transfer name="X" strict="true">
```
For each project transfer/property that core also declares, match core's `strict` value. Note a
strict transfer generates typed constants (`public const string FOO = 'foo'`) rather than untyped
ones — the constants still exist, so `Transfer::FOO` references keep working.

### Lane 5 — A dependency with no compatible release (developer decision)

When `resolve-constraints.php` reports UNRESOLVED for a third-party/eco package, first establish
*why*, because it changes the options:
- scan its versions on packagist for one that allows the target majors;
- if none, check **what it actually uses** from the blocking module. If it only touches stable APIs,
  a constraint widening upstream is enough (that is a PR to them). If it references a class the new
  major **removed**, no constraint change can help — it needs upstream code work.

Present that finding with the options (drop / fork / wait) and let the developer choose. Never drop a
feature unilaterally — measure its footprint first (`grep -rl` the module name across `src/` and
`config/`) and report the file count, because that is what makes the decision.

If the decision is to drop: remove the package, its plugin registrations, the project module, and
every asset/twig/JS reference. Two traps — keep project functionality that merely *hosted* an AI/
vendor hook (e.g. a form field whose only AI link was a `template_path` attribute), and when a
removed partial was `{% embed %}`-ed **around** project markup, unwrap it rather than deleting the
block, or the wrapped fields disappear with it.

## Phase 4.9 — Boot the environment (attempt this; do not assume it is impossible)

Everything from here on needs a running stack. Delegate the boot itself to the `local-dev-env` /
`boot-and-verify` sibling skills where they apply; what follows is what an *upgrade* specifically
needs from the boot.

```bash
git submodule update --init --depth 1 docker        # see the Environment note above
script -q /dev/null docker/sdk boot deploy.dev.yml  # generation only, cheap, validates the deploy file
script -q /dev/null docker/sdk up --build --assets --data
```

**Read the deploy file before booting — it changes the whole verification plan:**

- **Which applications exist.** `grep -oE "application: [a-z_]+" deploy.*.yml | sort -u`. A project
  with no `yves` entry is **headless**: there is no storefront to check, and the Back Office is the
  only UI. Do not write a storefront checklist for a project that has no storefront (the validation
  run nearly did).
- **How many stores.** A 9-store deploy with 8 workers per application will not fit in a laptop's
  Docker memory alongside OpenSearch, MariaDB, RabbitMQ and Jenkins. If `docker info` reports less
  than ~16 GB, expect to verify against **one store**. Prefer an additive
  `deploy.upgrade-verify.yml` (copy, one region/store, optional dev services dropped) over editing
  the project's own deploy files — and say in the report that verification ran on a trimmed topology,
  because a single-store boot does not exercise store-resolution paths.
- **`docker.testing.store`** — the store the test suites will use.

`boot` prints a `sudo … /etc/hosts` command. **You cannot run it** (it needs the developer's
password): surface it verbatim and continue — the build and the whole CLI/test path do not need it,
only browser access by hostname does.

Run the build in the background with a monitor covering **failure** signatures, not just progress:
a filter that greps only for success markers stays silent through an OOM kill or an image-pull
failure, and silence is indistinguishable from "still building".

If the boot genuinely cannot complete (insufficient memory, an image the project has no credentials
for), that is a real limit — record *which* checks it costs, using the Phase 5/5.5/8 lists below as
the inventory of what is now unproven. "Could not boot" is only acceptable after `git submodule
status` and `docker info` have both been shown.

## Phase 5 — Regenerate artifacts & verify

```bash
script -q /dev/null docker/sdk cli vendor/bin/console transfer:generate
script -q /dev/null docker/sdk cli vendor/bin/console propel:migration:diff   # inspect; NEVER auto-migrate
script -q /dev/null docker/sdk cli vendor/bin/console search:setup:index-map
script -q /dev/null docker/sdk cli vendor/bin/console navigation:build-cache
script -q /dev/null docker/sdk cli vendor/bin/evaluator evaluate
script -q /dev/null docker/sdk cli php -d memory_limit=2048M vendor/bin/phpstan analyze -c phpstan.neon src/ -l 6
script -q /dev/null docker/sdk cli vendor/bin/codecept build
script -q /dev/null docker/sdk cli vendor/bin/codecept run -x Acceptance
script -q /dev/null docker/sdk cli npm run yves
```
**Check `git status` after every Phase 5 command.** They are not read-only: `propel:install` runs
migration cleanup and **deletes** tracked `src/Orm/Propel/*/Migration_*` files (gitignore does not
protect files committed before the ignore rule), `propel:config:convert` writes generated config into
a path the project may not ignore, and `npm`/asset builds rewrite lock files. Restore deletions with
`git checkout --`, gitignore new generated paths, and keep genuine lock updates in their own commit.

Command names differ between projects and releases — **read `console list` instead of trusting this
list**. On the validation project the commands were `propel:diff` and `search:setup:source-map`;
`propel:migration:diff` and `search:setup:index-map` did not exist.

- Non-empty `propel:migration:diff` → show the developer BEFORE anything touches a database.
- **A clean `propel:diff` on a freshly-created database proves almost nothing.** `up --data` builds the
  DB from the post-upgrade schema, so an XML↔DB comparison necessarily agrees. It does not tell you
  what migration an *existing* production database needs. To answer that, boot the **pre-upgrade**
  lock, import, then upgrade the code and diff against that database. Report which question you
  actually answered.
- Compare PHPStan/evaluator results against the Phase 0 baseline — only regressions block.
- Compare the suite result against `.spryker-upgrade/state/codecept-baseline.txt`, per suite, and split the failures
  into two kinds — they have different fixes and belong in different parts of the report:
  **app damage** (a test fails because behaviour changed) and **harness damage** (a test fails
  because a vendor `SprykerTest` Tester/Helper/fixture moved or changed signature — `codecept build`
  usually surfaces this first). Do not "fix" harness damage by deleting assertions.
- Any test written in Phase 0.5 that now fails is the gate doing its job: that is a real behaviour
  change to explain, not a test to relax.
- Check release notes (Lane 0) for infra requirement changes (ES/Redis/PHP versions) and diff
  against `deploy.*.yml`; propose updates, developer confirms.
- Bump the project's own `php` constraint to match core. Count what the lock requires
  (`composer.lock` → most common `require.php` among spryker packages); if core is on `>=8.3` the
  project must be too, and the typed-member fixes need it anyway.

**Running console commands outside docker** (fallback when `docker/sdk` is absent): the environment
must be supplied explicitly, and the default memory limit is too low for `transfer:generate` —
it dies at 128M with a misleading fatal *after* partially generating.
```bash
APPLICATION_ENV=development SPRYKER_CURRENT_REGION=GLOBAL DYNAMIC_STORE_MODE=true \
  php -d memory_limit=3072M vendor/bin/console transfer:generate
```
Confirm the real exit code with stdout/stderr separated — a nonzero status can come from a shutdown
handler after the work succeeded, so check the artifacts too (`ls src/Generated/Shared/Transfer | wc -l`).

PHPStan needs `src/Generated/Client/Ide/AutoCompletion.php`, produced by
`dev:ide-auto-completion:generate`. If that command is not registered in the project's console, run
PHPStan against a copy of `phpstan.neon` with the `bootstrapFiles` block removed rather than
skipping static analysis — it is the only thing that catches constructor-arity breakage.

## Phase 5.5 — The full suite against the final code, in the real environment

Host PHP can run pure-logic suites. It cannot run anything needing a database, a search backend, a
broker or a webdriver — and **an unrunnable suite is not a passing suite**. Until the suite has run
in the booted environment against the *final* upgraded code, the tests have not verified the upgrade;
they have verified the subset of it that fits on a laptop without infrastructure.

```bash
script -q /dev/null docker/sdk cli vendor/bin/codecept build
script -q /dev/null docker/sdk testing codecept run 2>&1 | tee .spryker-upgrade/state/codecept-final.txt
```

Run it **after** Phase 5's artifact regeneration, never before: missing `Generated\` transfers and a
stale Propel model produce a wall of failures that have nothing to do with the upgrade.

Compare against `.spryker-upgrade/state/codecept-baseline.txt` **per suite**, and classify every
failure before fixing anything — the three kinds have different fixes and belong in different parts
of the report:

| Kind | Signature | Correct response |
|---|---|---|
| **App damage** | behaviour changed; the test's assertion is still what the project wants | fix `src/`, not the test |
| **Harness damage** | a vendor `SprykerTest` Tester/Helper/fixture moved, changed signature, or a `_support` class vanished; `codecept build` usually fails first | fix the test-side usage. Report separately — this is damage in the harness, not the app |
| **Environment noise** | fails identically on the pre-upgrade baseline; missing fixture data, no webdriver | not upgrade fallout. Do not "fix" it into the upgrade commit |

**A characterization test from Phase 0.5 that now fails is the gate working.** It means a real
behaviour change. Explain it, get the developer to confirm the new behaviour is intended, and only
then move the expectation — in its own commit, with the reason in the message. Never relax an
assertion to get green, and never delete a test to get green.

State the numbers honestly in the report: suites run, suites skipped **and why**, tests passed,
failures per class above. "All tests pass" is only true if every suite actually executed.

## Phase 6 — New features gate (developer gate #3)

From the NEW list in `.spryker-upgrade/state/lock-diff-report.json`: fetch each package's description
(`composer show <pkg>` + docs.spryker.com release notes). Present with AskUserQuestion
(multiSelect): integrate now / defer / never. NOTHING new gets wired without an explicit yes.
Each accepted feature: follow its official feature-integration guide, separate commit.
REMOVED packages: explain each (replaced by what?) in the final report.

## Phase 6.5 — Browser verification gate

Tests assert what someone thought to assert. A release that crosses a **CSS framework major** or
touches Back Office chrome breaks things no PHP test can see: a stylesheet that no longer defines a
class, a table that renders but paginates wrongly, a layout that double-wraps, a JS bundle that
throws on load and silently disables a widget. Derive the page list from **what the upgrade actually
touched**, not from a generic smoke list.

Drive the app through the `spryker-runtime` sibling skill (it owns login, session and Chrome
mechanics). Per page, capture a screenshot **and** read the browser console — a clean-looking page
with `Uncaught TypeError` in the console is a failure.

Build the checklist from the run's own findings:

| If the upgrade touched… | Verify |
|---|---|
| a `*-gui` / CSS framework major | every page whose template you merged in Lane 2, and any page using a legacy class the detector marked `KEEP (JS)` — that is where vendor JS meets project markup |
| `AbstractTable` or any table override | one table with **filter + sort + paging applied together**, and the footer row count while a filter is active. Table bugs hide until the three interact |
| a login / layout template | the login page and one authenticated page, checking the project's own delta (a font, a logo) actually survived |
| a shadowed template you merged | that exact page, comparing against the pre-upgrade screenshot if Phase 0 captured one |
| the asset toolchain (`oryx-*`, webpack, node) | that the build ran, **and** that the built bundle is what the page loads (a stale bundle in `public/` can mask a broken build) |

Console errors, 500s and missing styling are upgrade damage. Record what you could not reach and why
— an unvisited page is not a passing page. If the environment cannot boot, say which of these checks
that costs rather than omitting the section.

## Phase 7 — Report & handoff

Final summary: versions moved (majors highlighted with their migration-guide table from
Lane 0), conflicts found/resolved per lane with file links, remaining `upgrade-debt` markers,
test results vs baseline, pending DB migrations, features accepted/deferred, deploy file
changes. Ask before committing; suggest one PR per release group.

Separate **what is proven** from **what merely has not failed yet**, and give each claim its scope —
"243 unit tests green on host PHP" and "the suite passes" are different statements. For every check
that did not run, name it and name the damage class it would have caught.

## Hard rules

- Lane 0 is not skippable: no major bump ships without its migration guide processed or an
  explicit "none published" record.
- Re-run the detectors after EVERY resolution lane — fixes create new conflicts, and a fix in one
  lane routinely resolves or reveals entries in another.
- A red detector, PHPStan regression, or failing test blocks the next phase; never suppress.
- All state lives in `.spryker-upgrade/state/` (gitignored); never commit baselines, reports or
  `*.merge-conflict` artifacts. Prefer explicit `git add <path>` over `git add -A` while a Lane 2
  pass is in flight.
- Offline / lookup failure → say so and stop for that module; never invent migration steps.
- Distinguish damage from maintenance in every report: MISSING plugins, fatals and shadowed vendor
  changes are damage; DEPRECATED plugins still work. Never let the second destabilise the upgrade.
- Never drop a feature to make the upgrade resolve. Measure its footprint, state the options, and
  let the developer decide (Lane 5).
- Distinguish "verified" from "unverified" when reporting. Constraint resolution and static checks
  prove a lot; without a database, a search backend and a booted Back Office, the visual and
  data-migration results are unverified — say so plainly rather than implying a green run.
- Never claim tests verify the upgrade when they were already red, could not run, or do not touch
  the customised modules. Phase 0.5 exists so that claim can be made honestly — and if the developer
  declined new tests, the report says which lanes are static-only.
- Characterization tests belong BEFORE the upgrade. Written afterwards they pin the upgraded
  behaviour and can no longer detect that it changed.
