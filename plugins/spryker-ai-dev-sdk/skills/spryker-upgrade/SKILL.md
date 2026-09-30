---
name: spryker-upgrade
description: >-
  Upgrade this Spryker project's modules/features to a newer release. First records baselines
  (tests, PHPStan, code sniffer, evaluator and a crawl of the whole Back Office) and checks whether
  the project's customisations are covered by tests, offering Facade/Client-level functional tests
  where they are not. Then aligns dev tooling, the Docker SDK and the deploy files with the reference
  demo shop of the target release, resolves the constraint blockers that stop a release-group bump,
  and detects the silent damage (PHP 8.3 typed-member fatals, dead method overrides, shadowed
  Twig/component changes, replaced plugin stacks, broken config constants, transfer strict
  mismatches, vendor packages that must move together). Deprecated plugins are analysed for the
  developer to decide; every major module bump needs its migration guide. Trigger on "upgrade the
  project", "update to latest release", "update spryker modules/features", "run the upgrade", or any
  request to bump spryker-feature/* or spryker/* packages.
---

# Spryker Project Upgrade

## Non-negotiables — read these before Phase 0, re-read them on every compaction

These five rules apply to every phase and lane; § Hard rules at the end does not repeat them.

1. **An upgrade changes versions only.** Never integrate a feature, DI mechanism, storage backend or
   extension point the developer did not explicitly ask for, even when the new version supports it.
   A capability the project never had is outside the definition of damage. If restoring existing
   behaviour looks impossible without adopting the new thing, report it as a **vendor BC break**
   instead of re-architecting. Full rule: § Scope.
   *Failure signature:* the plan contains a file the project never had (`config/bundles.php`, a new
   service config, a new provider registration) that no phase's detector asked for.
2. **Baselines come first.** Phase 1 does not start until `check-baselines.php` exits 0: without the
   pre-upgrade results nothing tells upgrade damage from what was already broken.
   *Failure signature:* a constraint edit or `composer update` before `check-baselines.php` passed.
3. **Lane 0 is mandatory.** No major bump ships without its migration guide processed, or an
   explicit `none published` record naming the module and version pair.
   *Failure signature:* `list-major-bumps.php` lists a module that appears in no Lane 0 record.
4. **Re-run the detectors after every resolution lane.** Fixes create new conflicts, and a fix in
   one lane routinely resolves or reveals entries in another.
   *Failure signature:* a lane closed on a report file whose mtime predates that lane's last edit.
5. **A red detector, PHPStan or sniffer regression, or failing test blocks the next phase. Never
   suppress.** Not with a baseline entry, not with a phpcs/phpstan ignore, not by narrowing the
   analysed paths.
   *Failure signature:* the diff touches `phpstan-baseline.neon`, a `@phpstan-ignore`, a phpcs
   exclude, or a detector's exclude list — none of which is ever part of an upgrade's deliverable.

**Plan tracking is mandatory.** At the end of Phase 0, create one `TaskCreate` task each for Phase
0.5, 1, 1.2, 1.5, 2, 3, Lane 0, Lanes 1–4, Lane 5 (if reached), Phase 4.9, 5, 5.5, 6, 6.5 and 7, and
drive them with `TaskUpdate`: `in_progress` on entry, `completed` only once that phase's or lane's
checks are green; a detector re-run that reopens a lane reopens its task. `TaskList` survives
compaction; the current phase and lane otherwise do not.

**Locate the scripts:** `.claude/skills/spryker-upgrade/scripts/` (setup install) or
`${CLAUDE_PLUGIN_ROOT}/skills/spryker-upgrade/scripts/` (plugin install). `$UP` below stands for
whichever resolves — substitute it inline as a literal path; never set a shell variable and never
`cd`, both prompt on every call. Run them from the project root: they write every snapshot and report
into `<project>/.spryker-upgrade/state/`, created self-gitignoring. The eighteen scripts are listed in
`README.md` next to this skill; your job is to run the phases in order, do the semantic resolution
work, and stop at the gates that belong to the developer.

**No explanatory comments and no suppressions in code.** Use method and variable names that say what
the code does. Allowed: the license header, docblock tags, `{@inheritDoc}`, Spryker `Specification:`
blocks, and an `upgrade-debt:` docblock on a temporary shim. While `.spryker-upgrade/state/` exists, a
plugin hook denies edits that add explanatory comments or suppressions (see the plugin's
`hooks/README.md`), and `check-added-comments.php` — which also reports added `phpcs:ignore`,
`@phpstan-ignore` and similar as `suppression` — must exit 0 at the end of every lane and in Phase 7.
Comments copied verbatim from a vendor file into a project override count as added (Lane 2).

## Scope: an upgrade updates only what the project already has

**The deliverable is the project doing exactly what it did before, on newer versions.** Modernising
the project or moving it onto the vendor's recommended architecture is out of scope.

- **Moving a module to a newer version does not authorise adopting what that version enables** — a
  new architecture, DI mechanism, storage backend or extension point.
- **No new feature is integrated unless the developer explicitly asked for it.** This is Phase 6's
  rule, and it applies equally to new capabilities inside a module the project already had — easier
  to miss, because no new package shows up in the lock diff.
- **Damage is the project's existing behaviour changing or disappearing.** A feature the project
  never had is outside that definition.

Example: `spryker/container` 1.11.0 ships `ContainerDelegator`, whose presence flips `Kernel::boot()`
onto a Symfony-DI code path; a project without `config/bundles.php` stops booting its application
plugins, and the Back Office login breaks. **Wrong:** "adopt the Symfony container — add
`config/bundles.php` and service config" — a new architecture nobody asked for. **Right:** restore the
behaviour the project had with the minimal change, and if the new version offers no such path, report
a vendor BC break as a blocker with evidence and let the developer decide.

**Restoring behaviour usually means a narrowly-guarded project-side shim** — override the project's
own bootstrap/factory and call what the vendor stopped calling. **Guard on the exact broken
condition**, not on the version, so the shim disables itself once upstream is fixed (a shim that always
fires becomes a double-execution bug), and **check idempotence** — if the vendor never sets its
`$booted` flag, an unguarded re-boot registers event subscribers again on every request. Mark it with
an `upgrade-debt:` docblock (removal condition, vendor issue). When the only apparent fix is
"integrate the new thing", stop and say so; offer it as separate, explicitly-scoped work.

**Use the siblings rather than reinvent:** `spryker-docs-research` (Lane 0 guides),
`codecept-functional` (Phase 0.5 tests), `cypress-migration` (storefront E2E), `static-validation`
(phpcs/phpstan per lane), `spryker-runtime` / `boot-and-verify` (booting and exercising the app), and
the `spryker-verifier` agent (Phase 6.5 visual and login-gated checks).

**Environment.** In most Spryker projects `docker/` is a git submodule, and an uninitialised one
looks exactly like an absent one: never call the environment unavailable before `git submodule
status`, `git submodule update --init --depth 1 docker` and `docker info`. Run composer, PHPStan, the
sniffer and the tests in the container (`script -q /dev/null docker/sdk cli <cmd>`); the detector
scripts run on host PHP. Never modify `vendor/`. Read
[references/environment.md](references/environment.md) before the first boot in Phase 0, in Phase
4.9, and when a command behaves differently in the container than on the host.

**Coverage matrix.** Every known failure mode, what detects it and which phase resolves it is in
[references/coverage-matrix.md](references/coverage-matrix.md). Read it when something turns up that
you cannot place, and before the Phase 7 report; a new failure mode gets a row and a check.

## Phase 0 — Preflight and baselines (abort on failure)

1. `git status` must be clean; create branch `upgrade/<target-release>`.
2. Before any edit, record the diff base: `php $UP/check-added-comments.php --record-base`.
3. **Boot the pre-upgrade environment** with `docker/sdk up -t --build --assets --data`
   ([references/environment.md](references/environment.md)); every baseline below and the Phase 0.5
   tests run in it. The boot runs `composer install` in the container when `vendor/` is absent;
   otherwise run `script -q /dev/null docker/sdk cli composer install`.
4. Confirm `vendor/autoload.php` resolves a core class — snapshots taken against an incomplete vendor
   tree silently under-record. Then the detector baselines:
   ```bash
   php $UP/check-platform-alignment.php || true # is this host even valid to resolve on? (do this FIRST)
   php $UP/check-dead-overrides.php snapshot
   php $UP/twig-shadow-map.php snapshot
   php $UP/check-plugin-usage.php || true      # baseline: MISSING here = pre-existing damage
   php $UP/check-config-constants.php || true  # baseline: problems here = pre-existing damage
   php $UP/check-typed-members.php || true     # baseline: should be clean before upgrading
   php $UP/check-constraint-style.php || true  # baseline: patch-locked + merged constraints
   php $UP/check-test-coverage.php || true     # baseline: is the override surface verifiable at all?
   php $UP/check-vendor-class-replacement.php || true  # baseline: classes declared in vendor namespaces
   cp composer.lock .spryker-upgrade/state/composer.lock.before
   ```
5. MISSING plugins, config problems or unloadable classes in these baselines are pre-existing breaks,
   usually leftovers of a removed feature. Surface them, offer to fix first, at minimum record counts.
6. **Static baselines** (record, don't fix). Use the project's sniffer entry point —
   `vendor/bin/console code:sniff:style`, or its phpcs setup — and note which, for Phase 5:
   ```bash
   script -q /dev/null docker/sdk cli php -d memory_limit=2048M vendor/bin/phpstan analyze -c phpstan.neon src/ -l 6 2>&1 | tee .spryker-upgrade/state/phpstan-baseline-run.txt
   script -q /dev/null docker/sdk cli vendor/bin/console code:sniff:style 2>&1 | tee .spryker-upgrade/state/sniff-baseline.txt
   script -q /dev/null docker/sdk cli vendor/bin/evaluator evaluate 2>&1 | tee .spryker-upgrade/state/evaluator-baseline.txt
   ```
7. **Baseline test run** — projects routinely start with red tests, often because of their own
   customisation, so the post-upgrade result means nothing without it:
   ```bash
   script -q /dev/null docker/sdk testing codecept build
   script -q /dev/null docker/sdk testing codecept run --no-exit 2>&1 | tee .spryker-upgrade/state/codecept-baseline.txt
   ```
   Record pass/fail/skip per suite and the exact invocation, environment variables included (matrix
   #66). The red tests become the report's "Tests already red before the upgrade" list; a suite that
   cannot run is named with the reason, never counted as passing.
8. **Back Office crawl baseline** — every navigation page and every table data endpoint; `--baseline`
   writes `backoffice-smoke-baseline.json`. Exit 1 lists pages already failing (record them); exit 2
   is a failed login or an unreachable or wrong URL, and no baseline was written:
   ```bash
   SPRYKER_BACKOFFICE_USER=<user> SPRYKER_BACKOFFICE_PASSWORD=<password> php $UP/backoffice-smoke.php --url <zed base url> --baseline
   ```
   The credentials come from the environment (exported by the developer, or the repository-seeded
   demo account `spryker-runtime` documents); never write them into a file.
9. **Gate the baselines:** `php $UP/check-baselines.php` exits 0 before Phase 1 starts. It names each
   baseline file above that is missing or holds no result of its tool (`tee` writes the file even when
   the command failed or ran nothing): re-run that command. `--no-backoffice` only when the
   environment cannot boot — the report then says the crawl never ran.
10. **Create the task list** (§ Non-negotiables) as the last action of Phase 0.

## Phase 0.5 — Verifiability gate (developer gate #1)

What breaks silently is what customises core: a dead override still loads, a replaced plugin stack
still boots, a stale template still renders.

```bash
php $UP/check-test-coverage.php              # gaps, highest risk first
php $UP/check-test-coverage.php --all         # full per-module surface
```

It intersects the risk surface (overridden vendor methods, wired vendor plugins, shadowed templates,
per `<Layer>/<Module>`) with what the suite touches, and ranks the gaps. Wiring (`*DependencyProvider`,
`*Config`, `*Factory`) gets no test recommendation — `check-plugin-usage.php` covers dependency
providers. Exit 1 means at least one HIGH-risk module has no test at all.

Report two numbers to the developer — business-logic overrides, and how many sit in modules with no
test — and ask with AskUserQuestion:

- **Write tests for the HIGH-risk gaps first (recommended)** — a test written after the upgrade pins
  whatever the upgrade produced.
- **Cover a chosen subset** — typically checkout, pricing, cart, order flow; the developer knows which.
- **Proceed without new tests** — then the report states which lanes were verified statically only.

Read [references/testing.md](references/testing.md) before writing the first test, and hand the
writing to `codecept-functional`. The rules:

- Business behaviour is tested through the **Facade** (Zed) or the public **Client/Service**, as
  functional tests run in the booted Docker environment.
- **One test per behaviour an override changes** — not per method, not per class.
- **Never** a test for a `*Factory`, `*Config` or `*DependencyProvider` class, and no getter or wiring
  test (which class or value a method returns). They stay green whatever the upgrade does.
- No mocked host-PHP unit tests of models as the default.

Commit the tests on their own, green against the current version, before the upgrade branch diverges.

## Phase 1 — Target selection (developer gate #2)

**Build the current-state inventory from `composer.lock`.** `composer.lock` records the installed
state; `composer.json` records the intent. The two diverge routinely: loose constraints, modules bumped since the feature was pinned.

```bash
php -r '$l=json_decode(file_get_contents("composer.lock"),true); foreach($l["packages"] as $p) { if (str_starts_with($p["name"],"spryker-feature/")) { echo $p["name"]," ",$p["version"],PHP_EOL; } }'
grep '"spryker-feature/' composer.json    # the intent, for comparison only
```

1. **Report divergence before the update**, one line per feature: `<feature>: lock <resolved> / json
   <constraint>`. Name every constraint that does not pin the resolved version exactly (`^`, `~`, `*`,
   `dev-*` — a divergence to state, never a version to infer from) and every feature present in one
   file only (matrix row 38: a lock too stale to trust). *Failure signature:* a target chosen from a
   `^x.y` constraint, so the report's "current" release is not the one the lock installed.
2. Find newer releases (`composer show -a spryker-feature/spryker-core | grep versions`) and ask with
   AskUserQuestion — never pick silently: **next release group (recommended)** — the smallest
   reviewable step, repeating the skill per release — or **latest release**, one big jump.

## Phase 1.2 — Tooling, Docker SDK and deploy files

The target release also means a Docker SDK version, an image, and the dev tooling its code is checked
with; the Spryker composer update touches none of them. Align them with the **reference demo shop of
the target release**, each as its own commit, following
[references/tooling-and-docker-sdk.md](references/tooling-and-docker-sdk.md) step by step:

1. Clone the reference demo shop at the release tag into `.spryker-upgrade/state/reference/` (`git
   clone --depth 1 --branch <tag> …`), then run `php $UP/check-tooling-alignment.php --reference
   .spryker-upgrade/state/reference` (`project-only` and `missing-in-project` rows are informational).
2. **Docker SDK together with the deploy files.** Take the Docker SDK version from the reference,
   update `.git.docker` and the `docker/` submodule pointer, read the Docker SDK release notes between
   the two versions, and update every `deploy.*.yml` (image tag, service versions, new and renamed
   keys), keeping the project's own topology. Validate with `docker/sdk boot`; show the developer the
   deploy diff. Then rebuild with `script -q /dev/null docker/sdk up -t --build`: steps 3–5 run in the
   container, which runs the old image until that rebuild.
3. **Platform pin.** Before the first composer command, `php $UP/check-platform-alignment.php` must be
   clean for the image just set: `config.platform.php` set to the deployment PHP, composer run in the
   container, never `--ignore-platform-req` (matrix #56/#57).
4. **Tooling set** — `phpstan/phpstan` and the PHPStan extensions the project has,
   `spryker-sdk/evaluator`, `spryker/code-sniffer`, `codeception/*`, `phpunit/phpunit` — to the
   reference's constraints, updated alone, in the container, as its own commit.
5. **Re-take the PHPStan, sniffer and evaluator baselines** right after that commit (and the test
   baseline when codeception or phpunit moved) into `phpstan-`, `sniff-`, `evaluator-` and
   `codecept-post-tooling.txt`, keeping the Phase 0 files, then re-run `check-baselines.php`. From
   here on a tool's baseline is its `*-post-tooling.txt` file, so new tool rules are not upgrade damage.

If the new tools cannot resolve against the pre-upgrade Spryker modules, revert the tooling edit, run
Phase 2 without it, then do the tooling step directly after Phase 2 — before Phase 3 — with the same
re-takes. The baseline is then the Phase 0 findings plus only the findings from rules new in the tool
version, as the reference file describes. The image tag defers the same way if the old lock cannot
install on the new PHP; record which image each re-baseline ran on.

## Phase 1.5 — Constraint style (before the release-group update)

Bumping the `spryker-feature/*` meta-packages alone cannot resolve while individual modules are pinned
`~x.y.z`, exactly, or `^0.x`. Read [references/constraints.md](references/constraints.md) when you
enter this phase. First set every `spryker-feature/*` constraint in `composer.json` to the target
release; then:

```bash
php $UP/check-constraint-style.php            # report patch-locked + merged constraints
php $UP/check-constraint-style.php --relax    # rewrite ~/exact -> ^ (review the diff)
php $UP/resolve-constraints.php --max-rounds=8
# only on OSCILLATION / "would LOWER" (a cohort that must move together):
php $UP/unpin-feature-driven-modules.php --match=<substr,substr> --dry-run
php $UP/unpin-feature-driven-modules.php --match=<substr,substr>
php $UP/resolve-constraints.php --max-rounds=8
```

`resolve-constraints.php` runs composer on the host with `--ignore-platform-reqs` only to discover
constraints. When it is done, keep its `composer.json` edits and discard the rest: restore
`composer.lock` (`git checkout -- composer.lock`), then `vendor/` (`script -q /dev/null docker/sdk
cli composer install`). Phase 2's full update in the container is the authoritative resolution. The
resolver's major-crossing list is the Lane 0 worklist. UNRESOLVED entries — an eco package pinning
an old core major, a security advisory — go to the developer, never around them: never disable
advisory blocking, and never drop a package to make the tree resolve (Lane 5).

## Phase 2 — Composer update

1. Confirm every `spryker-feature/*` constraint is on the chosen release.
2. Run a **full** update in the container:
   `script -q /dev/null docker/sdk cli composer update --with-all-dependencies`. Not a filtered
   `spryker*/*` update: that re-uses the lock's root requirements, so removed pins keep conflicting
   (matrix #35), and it leaves the tooling behind. Phase 1.2's constraints say where tooling may move.
3. On resolution failure read `composer why-not`, adjust the blocking constraint, retry. Never
   `--ignore-platform-reqs`. Record every constraint the resolver changed — the diff is reviewed.
4. A tooling step Phase 1.2 deferred runs now, as its own commit, re-baselined before Phase 3.

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

Run `check-typed-members.php` first: typed-member fatals abort `vendor/bin/console` itself (with
composer-merge-plugin, add `vendor/<vendor>/<pkg>/Bundles`). Subtract the Phase 0 baselines and present
a summary: packages moved (majors highlighted), conflicts per lane, NEW packages, deprecated items.

**Reading PHPStan without infrastructure:** errors on `Orm\Zed\*` / `Spy*EntityTransfer` need a
database, `Generated\Shared\Search\*IndexMap` a search backend, `Constant APPLICATION_*` the Spryker
bootstrap. What remains is damage, typically constructor arity no reflection-based detector sees.

## Lane 0 — Migration guides (mandatory for every major bump)

For each package in the MAJOR list of `.spryker-upgrade/state/lock-diff-report.json`:
1. Locate the guide — the `spryker-docs-research` skill, or WebSearch
   `site:docs.spryker.com upgrade the <Module> module` ("Upgrade the <Module> module" pages under
   `docs.spryker.com/docs/pbc/all/<pbc>/<version>/…/upgrade-modules/`).
2. WebFetch it and extract the sections for the crossed major boundary only (e.g. 10.x → 11.0).
3. **Sort the steps into two piles before executing any of them** — guides freely mix them, and the
   distinction is the Scope rule applied:
   - **Required to keep working** — interface swaps, constant→config-method moves, plugin rewiring,
     schema/transfer adjustments, console commands. Execute these; they restore existing behaviour.
   - **New capability the guide offers** — "to use the new X, register Y". Do not execute these; they
     are Phase 6 material, even when the guide numbers them alongside the mandatory ones.

   Ambiguous? Ask what breaks if it is skipped — "nothing the project currently does" means new.
   Cross-check the "required" pile against the Phase 3 detectors' findings: a guide step no detector
   corroborates and no existing behaviour needs is a strong candidate for the "new capability" pile.
4. No guide → the module's CHANGELOG.md and the GitHub compare URL from the report; extract the
   `[BC]`/breaking notes for the crossed majors. A guide that contradicts the tag diff loses to the
   diff (matrix #37).
5. If neither yields clarity, stop for that module and ask the developer — never invent steps.
6. Record per module: guide URL (or "none published"), steps applied, steps deliberately not applied
   as new capabilities (with what they would have added), steps skipped for other reasons + why. The
   table goes into the final report verbatim.

## Lanes 1–4 — Conflict resolution

Work lane by lane; commit each lane separately. **Closing a lane** means: all Phase 3 detectors
re-run and green against the baseline, no PHPStan regression, `php $UP/check-added-comments.php`
exits 0. Read the lane's section of [references/lanes.md](references/lanes.md) when you enter it.

- **Lane 1 — Dead overrides and broken classes** (`dead-overrides-report.json`,
  `typed-members-report.json`, PHPStan). Order: typed members (the console will not start until they
  are gone), constructor arity, signature changes, dead overrides. Every touched behaviour gets a test
  through the Facade/Client.
- **Lane 2 — Shadowed frontend/presentation files** (`twig-conflicts-report.json`).
  `merge-shadowed-files.php --dry-run`, then `--apply`; resolve the CONFLICTED rest semantically
  (vendor structure wins, project business content wins); never commit `*.merge-conflict` files;
  drop comments copied verbatim from the vendor counterpart (an `upgrade-debt:` marker stays);
  finish with a clean `npm run yves` in the container.
- **Lane 3 — Plugin stacks and deprecations** (`plugin-usage-report.json`) — below.
- **Lane 4 — Config constants and transfer definitions** (`config-constants-report.json`,
  `transfer:generate`). Constants move to config methods per the guide; transfer `strict` attributes
  match core at both transfer and property level.

### Lane 3 — Plugin stacks and deprecations (developer gate #3)

**MISSING is the only category that is upgrade damage**, and it is fixed without asking: rewire to the
replacement the Lane 0 guide or the old class's `@deprecated` note names, in the guide's order, and
carry over the extension points a removed module wired. Project plugins on a removed interface are
ported, behaviour-tested first.

**Deprecated items are neither left alone nor swapped blindly.** Collect every deprecated plugin
(`check-plugin-usage.php` DEPRECATED, and PORTING on a deprecated interface) and every other deprecated
vendor API the project uses where detectable (PHPStan deprecation rules, `@deprecated` on vendor
parents and instantiated classes). Per item, analyse:

- the old item and its replacement;
- the difference between them — inputs, outputs, when it runs;
- the possible consequences — logic change, different extension point, new config or data needed,
  position in the stack;
- a recommendation: swap, swap plus config, port, or keep for now.

Present the table ([references/lanes.md](references/lanes.md) has the format) with AskUserQuestion
(multiSelect: apply now / defer / keep) and apply only what the developer chose, one item at a time,
linting every touched file and running the covering test — a chosen replacement is not a Scope
violation. Deferred and kept items go into the report with their analysis.

### Lane 5 — A dependency with no compatible release (developer decision)

When `resolve-constraints.php` reports UNRESOLVED for a third-party/eco package, establish why: a
version allowing the target majors, else what it uses from the blocking module (stable APIs → an
upstream constraint widening; a removed class → upstream code work). Present drop / fork / wait with
the feature's footprint (`grep -rl` across `src/` and `config/`, file count) and let the developer
choose — never drop unilaterally. The drop procedure is in [references/lanes.md](references/lanes.md).

## Phase 4.9 — Boot the upgraded environment (always attempted)

Boot the upgraded code as in [references/environment.md](references/environment.md)
(`docker/sdk boot deploy.dev.yml`, then `docker/sdk up -t --build --assets --data`), with the Docker SDK
on the Phase 1.2 version and the same topology as the Phase 0 baseline. As soon as the stack is up,
run the Back Office crawl (Phase 6.5 command): a vendor-to-vendor conflict breaks many pages at once,
and this is the cheapest point to find it. "Could not boot" is only acceptable
after `git submodule status` and `docker info` have been shown; then record which checks it costs.

## Phase 5 — Regenerate artifacts & verify

```bash
script -q /dev/null docker/sdk cli vendor/bin/console transfer:generate
script -q /dev/null docker/sdk cli vendor/bin/console propel:migration:diff   # inspect; NEVER auto-migrate
script -q /dev/null docker/sdk cli vendor/bin/console search:setup:index-map
script -q /dev/null docker/sdk cli vendor/bin/console navigation:build-cache
script -q /dev/null docker/sdk cli vendor/bin/evaluator evaluate
script -q /dev/null docker/sdk cli php -d memory_limit=2048M vendor/bin/phpstan analyze -c phpstan.neon src/ -l 6
script -q /dev/null docker/sdk cli vendor/bin/console code:sniff:style   # the entry point Phase 0 recorded
script -q /dev/null docker/sdk testing codecept build
script -q /dev/null docker/sdk testing codecept run -x Acceptance
script -q /dev/null docker/sdk cli npm run yves
php $UP/check-tooling-alignment.php --reference .spryker-upgrade/state/reference
```

Read [references/verification.md](references/verification.md) before the first of these. The rules:

- **Check `git status` after every command** — regeneration deletes tracked migration files and writes
  generated config into unignored paths (matrix #61/#62). Command names differ; read `console list`.
- A non-empty migration diff goes to the developer before anything touches a database.
- **Sniffer:** auto-fix the PHP files the upgrade changed first (`-f`), then compare with the sniffer
  baseline. A new violation blocks, like a PHPStan or evaluator regression.
- Tests: compare per suite with the test baseline, with the pinned invocation.
- A tool's baseline is its Phase 1.2 re-take (`*-post-tooling.txt`), else its Phase 0 file; after a
  deferred tooling step, the Phase 0 findings plus the findings from rules new in the tool version.

## Phase 5.5 — The full suite against the final code, in the real environment

Until the suite has run in the booted environment against the final code, it has not verified the
upgrade. Run it after Phase 5's regeneration, never before.

```bash
script -q /dev/null docker/sdk testing codecept build
script -q /dev/null docker/sdk testing codecept run 2>&1 | tee .spryker-upgrade/state/codecept-final.txt
```

Compare per suite with the test baseline (as in Phase 5) and classify every failure before fixing:

| Kind | Signature | Correct response |
|---|---|---|
| **App damage** | behaviour changed; the test's assertion is still what the project wants | fix `src/`, not the test |
| **Harness damage** | a vendor `SprykerTest` Tester/Helper/fixture moved, changed signature, or a `_support` class vanished; `codecept build` usually fails first | fix the test-side usage. Report separately — this is damage in the harness, not the app |
| **Already red / environment noise** | fails identically on the baseline; missing fixture data, no webdriver | not upgrade fallout. List it under "Tests already red before the upgrade"; do not "fix" it into the upgrade commit |

**A Phase 0.5 test that fails after the upgrade has detected a behaviour change.** Explain the change, get the developer to
confirm it is intended, then move the expectation in its own commit with the reason in the message.
Never relax an assertion or delete a test to get green.

## Phase 6 — New features gate (developer gate #4)

Two kinds of "new" need this gate, and only the first is visible in the lock diff:

1. **New packages** — the NEW list in `.spryker-upgrade/state/lock-diff-report.json`.
2. **New capabilities inside modules the project already had** — Lane 0's second pile, a
   `class_exists` branch (matrix #64), a new extension point.

Fetch each description (`composer show <pkg>` + release notes) and ask with AskUserQuestion
(multiSelect): integrate now / defer / never. Nothing new is wired without an explicit yes; each
accepted feature follows its integration guide, in its own commit. REMOVED packages: explain each.

## Phase 6.5 — Back Office crawl and browser verification gate

A page list derived from what the upgrade touched never visits the pages a vendor-to-vendor conflict
breaks. So this phase has two parts, in order:

1. **The deterministic crawl of the whole Back Office** — every navigation page and every table data
   endpoint — against the final code:
   ```bash
   SPRYKER_BACKOFFICE_USER=<user> SPRYKER_BACKOFFICE_PASSWORD=<password> php $UP/backoffice-smoke.php --url <zed base url>
   ```
   `backoffice-smoke-report.json` marks each problem `inBaseline`. **Any page or endpoint that is not
   in the baseline is upgrade damage**, however unrelated it looks (matrix #72). Fix and re-run until
   there is no new failure. The crawl requests table endpoints as a plain GET without DataTables
   parameters, so a table endpoint that fails only in the crawl is confirmed in the browser (step 2)
   before it is called damage.
2. **The visual and manual checks go to the `spryker-verifier` agent**, not the main agent. Spawn it
   with acceptance criteria built from what the upgrade touched (merged templates, table overrides, CSS
   framework majors, layout and login templates, the asset build, the storefront if `yves` exists) —
   the criteria table is in [references/verification.md](references/verification.md). It returns
   PASS / FAIL / BLOCKED per page with a screenshot and the browser console. One at a time.

Console errors, 500s and missing styling are upgrade damage; an unvisited page counts as unverified.

## Phase 7 — Report & handoff

`php $UP/check-added-comments.php` exits 0 a last time. Then write the report with the sections
[references/verification.md](references/verification.md) lists (shape:
[EXAMPLE-UPGRADE-REPORT.md](EXAMPLE-UPGRADE-REPORT.md)), including **Tests already red before the
upgrade** under its own heading and the gate #3 deprecation table. Separate what is proven from what
merely has not failed yet, and name the damage class every skipped check would have caught. Ask
before committing; suggest one PR per release group.

## Hard rules

- All state lives in `.spryker-upgrade/state/` (gitignored); never commit baselines, reports, the
  reference demo shop clone or `*.merge-conflict` artifacts. Prefer explicit `git add <path>` over
  `git add -A` while a Lane 2 pass is in flight.
- Offline / lookup failure → say so and stop for that module; never invent migration steps.
- Damage is fixed: MISSING plugins, fatals, shadowed vendor changes, new Back Office failures.
  Deprecated plugins and APIs are an analysed list the developer decides on at gate #3 — never
  ignored, never swapped without that decision.
- Tests pin behaviour through the Facade or the public Client/Service, before the upgrade. Never
  tests for Factory, Config or DependencyProvider classes, and no getter/wiring tests.
- Never claim "verified" for what could not run: a suite already red, unrunnable, or not touching the
  customised modules proves nothing; without a booted Back Office the visual and data-migration
  results are unverified; declined tests at gate #1 mean the report names the static-only lanes.
