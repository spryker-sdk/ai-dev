---
name: spryker-upgrade
description: "Upgrades a Spryker project's modules and features to a newer release with baselines, test coverage, constraint fixes and damage detection. Use for 'upgrade the project' or a package bump."
---

# Spryker Project Upgrade

## Non-negotiables — read these before Phase 0, re-read them on every compaction

These five rules apply to every phase and lane; § Hard rules at the end does not repeat them.

1. **An upgrade changes versions only.** Never integrate a feature, DI mechanism, storage backend or
   extension point the developer did not explicitly ask for, even when the new version supports it.
   A capability the project never had is outside the definition of damage. If restoring existing
   behaviour looks impossible without adopting the new thing, report it as a **vendor BC break**
   instead of re-architecting. Full rule, and its one exception (Phase 6.2 performance fixes): § Scope.
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
   suppress.** Not with a baseline entry, a phpcs/phpstan ignore, or narrower analysed paths.
   *Failure signature:* the diff touches `phpstan-baseline.neon`, a `@phpstan-ignore`, a phpcs
   exclude, or a detector's exclude list — none of which is ever part of an upgrade's deliverable.

**Plan tracking is mandatory.** At the end of Phase 0, create one `TaskCreate` task each for Phase
0.5, 1, 1.2, 1.5, 2, 3, Lane 0, Lanes 1–4, Lane 5 (if reached), Phase 4.9, 5, 5.5, 6, 6.1, 6.2, 6.5, 6.8 and 7,
and drive them with `TaskUpdate`: `in_progress` on entry, `completed` only once its checks are green; a
detector re-run that reopens a lane reopens its task. `TaskList` survives compaction; nothing else does.

**Locate the scripts:** `<skill-dir>/scripts/`, where `<skill-dir>` is the path reported as
`Base directory for this skill:` when this skill loaded — that line is correct for every install. `$UP` below stands for
whichever resolves — substitute it inline as a literal path; never set a shell variable and never
`cd`, both prompt on every call. Run them from the project root: they write every snapshot and report
into `<project>/.spryker-upgrade/state/`, created self-gitignoring. The twenty-three scripts are listed in
`README.md` next to this skill; your job is to run the phases in order, do the semantic resolution
work, and stop at the gates that belong to the developer.

**No explanatory comments and no suppressions in code.** Use method and variable names that say what
the code does. Allowed: the license header, docblock tags, `{@inheritDoc}`, Spryker `Specification:`
blocks, and an `upgrade-debt:` docblock on a temporary shim. `check-added-comments.php` — which also
reports added `phpcs:ignore`, `@phpstan-ignore` and similar as `suppression` — must exit 0 at the end
of every lane and in Phase 7. Vendor comments copied into a project override count as added (Lane 2).

## Scope: an upgrade updates only what the project already has

**The deliverable is the project doing exactly what it did before, on newer versions.** Modernising
the project or moving it onto the vendor's recommended architecture is out of scope. **Exception:** the
Phase 6.2 performance guideline fixes are part of the deliverable: the developer asks for them with it.

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
Before the first `composer` command, run the shared environment preflight in
[../spryker-feature-install/references/environment-preflight.md](../spryker-feature-install/references/environment-preflight.md).

**Tool output.** `run-tools.php` and `compare-tool-runs.php` print a summary and write the full
output to files in the state directory. Read the summary. Open a log file only for the failing part
and search it for that part; never read the whole file. Do not read `tool-comparison-*.json`; to see
more new findings, run `compare-tool-runs.php` again with `--limit N <tool>`. When a run is stopped
before it ends, `tool-runs-<run>.json` holds the tools that finished: run the other tools again.
The detectors and
`run-detectors.php` print one verdict line per detector (`FOUND` = the detector's exit 1) and at
most 10 findings (`--limit N`), each with the field to act on; the full list is in the report file
the line names. The printed findings are a sample. Process each report entry where `inBaseline` is
not true; an entry with `inBaseline` true is still processed where the lane says so. Do not read the
report JSON: run the detector again with `--category <name>` (the first word of a finding line;
comma list or repeated) and `--limit N` to print the entries of one category. The reports of
`check-platform-alignment.php`, `check-vendor-class-replacement.php` (Phase 0 step 5) and
`check-constraint-style.php` (Phase 1.5) belong to no lane: process them in those steps.

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
   php $UP/run-detectors.php phase0             # exit 1 = findings: the Phase 0 baseline, not a stop
   php $YI/yves-integration-scan.php snapshot  # storefront widgets the project already does not render (Phase 6.1)
   cp composer.lock .spryker-upgrade/state/composer.lock.before
   cp $UP/storage-search-counts.php .spryker-upgrade/state/   # publish baseline: drain the queues first
   script -q /dev/null docker/sdk cli php .spryker-upgrade/state/storage-search-counts.php --snapshot before
   ```
   `run-detectors.php phase0` runs, in this order: `check-platform-alignment.php` (is this host even
   valid to resolve on?), `check-dead-overrides.php snapshot`, `twig-shadow-map.php snapshot`,
   `check-plugin-usage.php --baseline` (MISSING here = pre-existing damage),
   `check-config-constants.php --baseline` (problems here = pre-existing damage),
   `check-typed-members.php --baseline` (should be clean before upgrading),
   `check-constraint-style.php` (patch-locked and merged constraints),
   `check-vendor-class-replacement.php` (classes declared in vendor namespaces) and
   `check-constant-overrides.php --snapshot` (core values under project constants). Phase 0.5 runs
   `check-test-coverage.php`. `--baseline` keeps the Phase 0 result of the detectors without a
   snapshot of their own. Exit 2 names a detector that did not run: run it alone, fix the cause, run
   it again. When `composer.lock` differs from `composer.lock.before`, `--baseline` does not replace
   an existing Phase 0 result (exit 2); `--force` records it again.
5. MISSING plugins, config problems or unloadable classes in these baselines are pre-existing breaks,
   usually leftovers of a removed feature. Surface them, offer to fix first, at minimum record counts.
   Platform problems (`check-platform-alignment.php`) are fixed before the first composer update;
   each VENDOR_CLASS_REPLACED entry (`check-vendor-class-replacement.php`) gets a decision here.
6. **Static baselines** (record, don't fix) — PHPStan, the sniffer and the evaluator, into
   `phpstan-baseline-run.txt`, `sniff-baseline.txt` and `evaluator-baseline.txt`:
   ```bash
   php $UP/run-tools.php baseline phpstan sniffer evaluator
   ```
   The runner detects the project's sniffer entry point (`vendor/bin/console code:sniff:style`, or
   its phpcs setup) and records which, for Phase 5. When it names the wrong one, run it again with
   `--sniffer "<command>"`.
7. **Baseline test run** — projects routinely start with red tests, often because of their own
   customisation, so the post-upgrade result means nothing without it:
   ```bash
   php $UP/run-tools.php baseline codecept-build tests --tests-args=--no-exit
   ```
   Add `--env NAME=VALUE` for each environment variable the developer sets for the tests, and
   `--codecept-options "<options>"` once for each test configuration the project runs (for example
   `--codecept-options "-c codeception.api.yml"`); the runner runs them in that order into one log.
   The baseline run pins the exact invocation, environment variables included, in
   `tool-invocation.json` (matrix #66); every later run uses it. It records pass/fail/skip per suite
   and writes the names of the failing tests, by suite, to `failing-tests-baseline.txt`. Take the
   report's "Tests already red before the upgrade" list from that file; a suite that cannot run is
   named with the reason, never counted as passing.
8. **Back Office crawl baseline** — every navigation page and every table data endpoint; `--baseline`
   writes `backoffice-smoke-baseline.json`. Exit 1 lists pages already failing (record them); exit 2
   is a failed login or an unreachable or wrong URL, and no baseline was written:
   ```bash
   SPRYKER_BACKOFFICE_USER=<user> SPRYKER_BACKOFFICE_PASSWORD=<password> php $UP/backoffice-smoke.php --url <zed base url> --baseline
   ```
   The credentials come from the environment (exported by the developer, or the repository-seeded
   demo account `spryker-runtime` documents); never write them into a file.
9. **Gate the baselines:** `php $UP/check-baselines.php` exits 0 before Phase 1 starts. It names each
   baseline file above that is missing or holds no result of its tool (the runner writes the file
   also when the tool did not run): re-run that command. `--no-backoffice` (`--no-data`) only when
   the environment cannot boot (with data); the report then says the crawl (publish check) never ran.
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
test — and ask the user a structured question (use your harness's question tool if it has one, otherwise ask in chat and wait):

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

Commit the tests on their own branch, green against the current version — that branch becomes **the
tests pull request**, and the upgrade branch starts from it. With no new tests there is no tests pull
request; record why (the developer proceeded without them, or the suite already covered the gaps) for
the handover report.

**Log every defect that already exists on the current release** in `.spryker-upgrade/state/findings.md`
([references/handover-report.md](references/handover-report.md) § The findings log). A test pins current
behaviour, wrong or not; the log is how the wrong behaviour reaches the project team. Keep logging in
every later phase — Phase 0's red tests, the Back Office crawl baseline, the verifier, lane reading.

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
   a structured question — never pick silently: **next release group (recommended)** — the smallest
   reviewable step, repeating the skill per release — or **latest release**, one big jump.
3. **Read the security release notes** that Spryker published after the starting release
   (https://docs.spryker.com/docs/about/all/releases/security-releases/). Per issue, add one row to
   `.spryker-upgrade/state/security-fixes.md`: `| <issue> | <affected modules: fixed version> |
   <extra steps> | <status> |`. Extra steps are all actions in the note's fix section other than the
   module update. Offline: say so, and the reports say that the security fixes are not checked.

## Phase 1.2 — Tooling, Docker SDK and deploy files

Start the release-steps log `.spryker-upgrade/state/release-steps.md` here, and add to it in every
later phase ([references/release-steps.md](references/release-steps.md) § 1).

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
   deploy diff. Then rebuild with `script -q /dev/null docker/sdk up -t --build`: steps 3–4 run in the
   container, which runs the old image until that rebuild. Composer always runs in the container,
   never with `--ignore-platform-req` (matrix #57).
3. **Tooling set** — `phpstan/phpstan` and the PHPStan extensions the project has,
   `spryker-sdk/evaluator`, `spryker/code-sniffer`, `codeception/*`, `phpunit/phpunit` — to the
   reference's constraints, updated alone, in the container, as its own commit.
4. **Re-take the PHPStan, sniffer and evaluator baselines** right after that commit with
   `php $UP/run-tools.php post-tooling phpstan sniffer evaluator` (add `codecept-build tests
   --tests-args=--no-exit` when codeception or phpunit moved). It writes `phpstan-`, `sniff-`,
   `evaluator-` and `codecept-post-tooling.txt` and keeps the Phase 0 files; then re-run
   `check-baselines.php`. From here on a tool's baseline is its `*-post-tooling.txt` file, so new tool
   rules are not upgrade damage.

If the new tools cannot resolve against the pre-upgrade modules, the tooling step and the image tag
defer until right after Phase 2 (the reference's § When the new tools cannot resolve against the old code).

## Phase 1.5 — Constraint style (before the release-group update)

Bumping the `spryker-feature/*` meta-packages alone cannot resolve while individual modules are pinned
`~x.y.z`, exactly, or `^0.x`; read [references/constraints.md](references/constraints.md) on entry. Set every
`spryker-feature/*` constraint in `composer.json` to the target release first; then:

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
5. **Check `security-fixes.md` against `composer.lock`.** Do each extra step that changes project
   code or configuration, in its own commit. Put each step for the environments (a variable, a
   command) into the release-steps log. A module below its fixed version: set its constraint to
   `^<fixed version>` and run `script -q /dev/null docker/sdk cli composer update <module>
   --with-dependencies`, as its own commit. When that does not resolve, it goes to the developer with
   the `composer why-not` output. Set the status: `fixed`, `fixed, release step`, `not fixed: <why>`, or `not affected:
   <why>` (for example, the project does not install the module).

## Phase 3 — Detection (run all, collect before resolving)

```bash
php $UP/run-detectors.php phase3               # exit 1 = findings; each verdict line names them
php $UP/run-tools.php detect phpstan
php $UP/compare-tool-runs.php detect phpstan   # exit 1 = new PHPStan errors against the baseline
```

`run-detectors.php phase3` runs, in this order: `check-typed-members.php src/Pyz` (exit 1 = PHP 8.3
typed-member fatals), `list-major-bumps.php` (majors/new/removed classification),
`check-dead-overrides.php verify` and `twig-shadow-map.php diff` (exit 1 = conflicts),
`check-plugin-usage.php` (exit 1 = missing plugins), `check-config-constants.php` (exit 1 = broken
config refs), `check-constant-overrides.php` (exit 1 = core value changed under a project constant)
and `check-performance.php` (exit 1 = ProductGroupWidget rendering lost, Lane 2).
`check-typed-members.php` runs first: typed-member fatals abort `vendor/bin/console` itself (with
composer-merge-plugin, add `--typed-members-dir vendor/<vendor>/<pkg>/Bundles`). The verdict lines
count the findings Phase 0 already had and list only the new ones. Present a summary: packages moved
(majors highlighted), conflicts per lane, NEW packages, deprecated items.
PHPStan errors only a database, a search backend or the bootstrap explain are environment gaps (matrix #50).

**Security fixes in project overrides.** For each `fixed` row in `security-fixes.md`, find the
vendor files that the fix changed (the module's tag diff between the old and the new version). Find
the project files that replace or extend them: classes in `src/Pyz` and the Twig overrides that
`twig-shadow-map.php` lists. A vendor update does not reach these files. Do not change them: the fix
can change the project's behavior. Set the status to `not fixed: project override <file>` and write
the recommended fix next to it. Lanes 1 and 2 do not port these changes either.

## Lane 0 — Migration guides (mandatory for every major bump)

For each package in the MAJOR list of `.spryker-upgrade/state/lock-diff-report.json`:
1. Locate the guide — the `spryker-docs-research` skill, or a web search for
   `site:docs.spryker.com upgrade the <Module> module` ("Upgrade the <Module> module" pages under
   `docs.spryker.com/docs/pbc/all/<pbc>/<version>/…/upgrade-modules/`).
2. Fetch it with your harness's web fetch tool and extract the sections for the crossed major boundary only (e.g. 10.x → 11.0).
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
   `[BC]`/breaking notes for the crossed majors. A guide contradicting the tag diff loses (matrix #37).
5. If neither yields clarity, stop for that module and ask the developer — never invent steps.
6. Record per module: guide URL (or "none published"), steps applied, steps deliberately not applied
   as new capabilities (with what they would have added), steps skipped for other reasons + why. The
   table goes into the final report verbatim.

## Lanes 1–4 — Conflict resolution

Work lane by lane; commit each lane separately. **Closing a lane** means: the detectors of that lane
re-run and green against the baseline (Lane 1 `check-typed-members.php` and
`check-dead-overrides.php verify`; Lane 2 `twig-shadow-map.php diff` and `check-performance.php`;
Lane 3 `check-plugin-usage.php`; Lane 4 `check-config-constants.php`; `check-constant-overrides.php`
for the lanes its entries name), `php $UP/run-detectors.php phase3 --limit 0` for the counts of the
other detectors, no PHPStan regression, `php $UP/check-added-comments.php` exits 0. Read the lane's section of [references/lanes.md](references/lanes.md) when you enter it.
`constant-overrides-report.json` entries name their lane: 3 DependencyProvider, 4 Config/Constants, 1 other.

- **Lane 1 — Dead overrides and broken classes** (`dead-overrides-report.json`, `typed-members-report.json`,
  PHPStan). Order: typed members (the console will not start until they are gone), constructor arity,
  signature changes, dead overrides. Every touched behaviour gets a test through the Facade/Client.
- **Lane 2 — Shadowed frontend/presentation files** (`twig-conflicts-report.json`, `PRODUCT_GROUP_WIDGET_REMOVED`).
  `merge-shadowed-files.php --dry-run`, then `--apply`; resolve the CONFLICTED rest semantically
  (vendor structure wins, project business content wins); never commit `*.merge-conflict` files;
  drop comments copied verbatim from the vendor counterpart (an `upgrade-debt:` marker stays);
  finish with a clean `npm run yves` (and `npm run zed` / `npm run mp:build` for Zed/MP entries) in the
  container, then render the merged pages — the Yves build does no type-checking.
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
parents and instantiated classes). Per item, analyse the old item and its replacement; the difference
(inputs, outputs, when it runs); the possible consequences (logic change, other extension point, new
config or data needed, position in the stack); and a recommendation (swap, swap plus config, port, or
keep for now).

Present the table ([references/lanes.md](references/lanes.md) has the format) with a structured question
(multiSelect: apply now / defer / keep) and apply only what the developer chose, one item at a time,
linting every touched file and running the covering test — a chosen replacement is not a Scope
violation. Deferred and kept items go into the report with their analysis.

### Lane 5 — A dependency with no compatible release (developer decision)

When `resolve-constraints.php` reports UNRESOLVED for a third-party/eco package, establish why, then
present drop / fork / wait with the feature's footprint and let the developer choose — never drop
unilaterally. The analysis and the drop procedure are in [references/lanes.md](references/lanes.md).

## Phase 4.9 — Boot the upgraded environment (always attempted)

Boot the upgraded code as in [references/environment.md](references/environment.md)
(`docker/sdk boot deploy.dev.yml`, then `docker/sdk up -t --build --assets --data`), with the Docker SDK
on the Phase 1.2 version and the same topology as the Phase 0 baseline. As soon as the stack is up,
run the Back Office crawl (Phase 6.5 command): a vendor-to-vendor conflict breaks many pages at once,
and this is the cheapest point to find it. "Could not boot" is only acceptable
after `git submodule status` and `docker info` have been shown; then record which checks it costs.
**Publish check:** once the same data is imported and the queues are drained, re-run the Phase 0 counts
command with `--snapshot after`, then with `--compare before after`. Exit 1 is upgrade damage (a lost
publisher, storage/search plugin or event subscriber): trace each table per
[references/verification.md](references/verification.md), and re-check after Phase 5.5.

## Phase 5 — Regenerate artifacts & verify

```bash
script -q /dev/null docker/sdk cli vendor/bin/console transfer:generate
script -q /dev/null docker/sdk cli vendor/bin/console propel:migration:diff   # inspect; NEVER auto-migrate
script -q /dev/null docker/sdk cli vendor/bin/console search:setup:index-map
script -q /dev/null docker/sdk cli vendor/bin/console navigation:build-cache
php $UP/run-tools.php verify evaluator phpstan sniffer
php $UP/run-tools.php verify codecept-build tests --tests-args="-x Acceptance"
php $UP/compare-tool-runs.php verify   # exit 1 = new findings or failing tests against the baselines
script -q /dev/null docker/sdk cli npm run yves
php $UP/check-tooling-alignment.php --reference .spryker-upgrade/state/reference
```

Read [references/verification.md](references/verification.md) before the first of these. The rules:

- **Check `git status` after every command** — regeneration deletes tracked migration files and writes
  generated config into unignored paths (matrix #61/#62). Command names differ; read `console list`.
- A non-empty migration diff goes to the developer before anything touches a database. Record each
  migration for the handover report: what it changes, its data-loss risk, and what its down migration
  drops or narrows.
- **Sniffer:** auto-fix the PHP files the upgrade changed first (`-f`), then compare with the sniffer
  baseline. A new violation blocks, like a PHPStan or evaluator regression.
- Tests: compare per suite with the test baseline, with the pinned invocation.
- A tool's baseline is its Phase 1.2 re-take (`*-post-tooling.txt`), else its Phase 0 file;
  `compare-tool-runs.php` takes them in that order. After a deferred tooling step the baseline is the
  Phase 0 findings plus the findings from rules new in the tool version: compare with
  `--baseline phase0` and take those tool findings out of its list.

## Phase 5.5 — The full suite against the final code, in the real environment

Until the suite has run in the booted environment against the final code, it has not verified the
upgrade. Run it after Phase 5's regeneration, never before.

```bash
php $UP/run-tools.php final codecept-build tests
php $UP/compare-tool-runs.php final tests
```

Compare per suite with the test baseline (as in Phase 5) and classify every failure before fixing as
app damage, harness damage or already red ([references/verification.md](references/verification.md)
§ Phase 5.5 has the signatures and the response to each).

**A Phase 0.5 test that fails after the upgrade has detected a behaviour change.** Explain the change, get the developer to
confirm it is intended, then move the expectation in its own commit with the reason in the message.
Never relax an assertion or delete a test to get green.

## Phase 6 — New features gate (developer gate #4)

Two kinds of "new" need this gate, and only the first is visible in the lock diff:

1. **New packages** — the NEW list in `.spryker-upgrade/state/lock-diff-report.json`.
2. **New capabilities inside modules the project already had** — Lane 0's second pile, a
   `class_exists` branch (matrix #64), a new extension point (performance fixes: Phase 6.2, not here).

Fetch each description (`composer show <pkg>` + release notes) and ask the user a structured question (use your harness's question tool if it has one, otherwise ask in chat and wait)
(multiSelect): integrate now / defer / never. Nothing new is wired in the upgrade branch: each accepted
feature goes to `spryker-feature-install` after the upgrade pull request, one feature per run, on its
own branch (it includes the optional storefront placement step). REMOVED packages: explain each.

## Phase 6.1 — Storefront placement (optional, suggested)

Lane 2 merges vendor changes into templates the project shadows, but only into files whose vendor
version changed. Integration guides never place widgets in Twig. So after the lanes and Phase 6, the
storefront can still miss widgets the upgrade added: a vendor template gained a widget call in a block
the project replaced, or a newly registered widget is called only in vendor templates the project
overrides. Skip this phase when the project has no Yves storefront.

1. `php $YI/yves-integration-scan.php diff` (against the Phase 0 snapshot). It lists only the gaps that
   are new since Phase 0; the omissions the project lived with before stay out.
2. Present its suggestion table to the developer: place now / defer / never, per item. This is a
   suggestion, not damage: a project may leave a new widget out on purpose.
3. For the picked items, follow the `yves-twig-integration` skill (vendor reference for what and where,
   the project's design for how), in its own commit; render each changed page.
4. Unpicked and deferred items go to the handover report (3.5), with their vendor reference.

`$YI` is `<skill-dir>/../yves-twig-integration/scripts/` (the sibling skill shipped in the same plugin),
substituted inline like `$UP`; it writes to `<project>/.ai-dev/yves-integration/`.

For the handover report, also list what Spryker released after the starting release, up to the target,
that the project could adopt — from the release notes (`spryker-docs-research`), not only the lock
diff. Per feature: what it does, what it requires, its third-party costs, and whether it installs with
little change to project code, needs an outside service, or changes project code.

## Phase 6.2 — Performance fixes

Per [references/performance.md](references/performance.md): fetch Spryker's performance guidelines as
published today into `.spryker-upgrade/state/spryker-docs` (offline: `--unreachable`); `--init` lists every
page; read-only sub-agents per page group record its fixes, never from memory, each sized (`S` config value
or flag, `M` package update or a few files, `L` refactor, schema change or many files) with its files and
expected gain, or the page's `--no-fix` reason. `S` fixes apply without asking; the `M`/`L` fixes
(`--decisions`) take one structured question — apply now / defer / skip each, recorded with `--decide`. Keep
visible behaviour (else `not-applied`); one fix at a time, own commit, checks and a before/after comparison
per customer type, reverted on failure. `php $UP/check-performance.php --verify` exits 0 before Phase 6.5.

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
   parameters, so an endpoint failing only in the crawl is confirmed in the browser (step 2) first.
2. **The visual and manual checks go to the `spryker-verifier` agent**, not the main agent. Spawn it
   with acceptance criteria built from what the upgrade touched (merged templates, table overrides, CSS
   framework majors, layout and login templates, the asset build, the storefront if `yves` exists) —
   the criteria table is in [references/verification.md](references/verification.md). It returns
   PASS / FAIL / BLOCKED per page with a screenshot and the browser console. One at a time.

Console errors, 500s and missing styling are upgrade damage; an unvisited page counts as unverified.

## Phase 6.8 — Release steps verification

Prove the commands that staging and production must run after the normal deployment. Do
[references/release-steps.md](references/release-steps.md) § 2 exactly, in its order.

## Phase 7 — Report & handoff

`php $UP/check-added-comments.php` and `php $UP/check-performance.php --verify` exit 0 first. Then
write two reports:

- **The developer report** — the sections [references/verification.md](references/verification.md)
  lists (shape: [EXAMPLE-UPGRADE-REPORT.md](EXAMPLE-UPGRADE-REPORT.md)), including **Tests already red
  before the upgrade** under its own heading, the gate #3 deprecation table and **Performance fixes**.
  Separate what is proven from what merely has not failed yet, and name the damage class every
  skipped check would have caught.
- **The handover report** for the development team — Markdown or HTML for a paste into Confluence;
  ask the person which one. [references/handover-report.md](references/handover-report.md) (shape:
  [EXAMPLE-HANDOVER-REPORT.md](EXAMPLE-HANDOVER-REPORT.md)).

Write both reports by the writing rules in
[references/handover-report.md](references/handover-report.md) § Writing rules.

Deliver two pull requests: the tests pull request first, the upgrade pull request built on it — or the
upgrade pull request alone when there were no new tests. One upgrade pull request per release group.
Ask before committing or opening a pull request.

## Hard rules

- Offline / lookup failure → say so and stop for that module; never invent migration steps.
- All state lives in `.spryker-upgrade/state/` (gitignored); never commit baselines, reports, the
  reference demo shop clone or `*.merge-conflict` artifacts. Prefer explicit `git add <path>` over
  `git add -A` while a Lane 2 pass is in flight.
- Never claim "verified" for what could not run: a suite already red, unrunnable, or not touching the
  customised modules proves nothing; without a booted Back Office the visual and data-migration
  results are unverified; declined tests at gate #1 mean the report names the static-only lanes.
