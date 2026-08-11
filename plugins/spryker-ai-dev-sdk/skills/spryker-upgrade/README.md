# Spryker Upgrade Tooling

Deterministic detectors for the *silent* failure modes of a Spryker module upgrade on a project
with heavy `src/Pyz` customization. Orchestrated by the `/spryker-upgrade` Claude Code skill, which
also holds the full **58-use-case coverage matrix** — including the process-level modes these
scripts cannot cover (Propel schema merges, infra requirements, glossary keys, behavioural changes):
`.claude/skills/spryker-upgrade/SKILL.md`. Every script is a standalone CLI, usable in CI.

All scripts run on host PHP (reflection or static parsing only, no Spryker bootstrap) and keep their
snapshots and reports in `.spryker-upgrade/state/` inside the project, created self-gitignoring on
first use.

**Where they live and how to call them.** `$UP` in every example below is the scripts directory:
`.claude/skills/spryker-upgrade/scripts` (setup install) or
`${CLAUDE_PLUGIN_ROOT}/skills/spryker-upgrade/scripts` (plugin install). Run them **from the project
root** — the project is discovered by walking up from the working directory, so nothing is derived
from where the scripts themselves sit. Two overrides exist for CI and for calling from elsewhere:

```bash
SPRYKER_PROJECT_ROOT=/path/to/project        # skip discovery
SPRYKER_UPGRADE_STATE_DIR=/path/to/state     # move baselines/reports (e.g. a CI cache directory)
```

## Validated end-to-end

Not a paper design: this tooling drove a real **202410.0 → 202606.0** upgrade of
`spryker-projects/sc-b2b-mp-industry-demo` (2 838 `src/Pyz` classes, 423 dependency providers,
5 release groups) to a resolving, `transfer:generate`-clean state. That run is written up in
[EXAMPLE-UPGRADE-REPORT.md](EXAMPLE-UPGRADE-REPORT.md) and is worth reading before planning an
upgrade — the shape of the work was not what the docs implied.

What it found, in rough order of how much it would have hurt:

| Finding | Why it mattered |
|---|---|
| An **external repo** owned 216 of the project's root constraints via composer-merge-plugin | Nothing in the project could fix it; the upgrade was gated on another repository, and composer blamed "your root composer.json" for constraints that were not in it |
| Core adopted **PHP 8.3 typed constants/properties** | Every untyped override became a fatal on class load — one of them aborted `vendor/bin/console`, so no console command ran at all |
| **130 tilde-pinned** module constraints | Bumping the feature meta-packages alone could never resolve; first attempt produced 49 conflicts |
| A **cohort deadlock** (Angular 20) | ~20 modules had to move together; per-package bumping oscillated forever |
| **Constructor arity** changes in classes Pyz factories instantiate | Invisible to reflection — only PHPStan caught these 4 |
| A whole module (**CustomerReorderWidget**) removed in favour of a feature | 5 wired plugins vanished, and the extension points it wired register nothing by default in core |
| Most breaking churn lives in `x.1.0` **minors**, not at major boundaries | e.g. availability-gui 7.0.0 changes 0 files, 7.1.0 changes 28 — scoping review to majors misses ~90% |
| **163 shadowed frontend files** changed upstream | Only 15 merged cleanly; the rest need design decisions because the overrides restructured components |

Several published migration guides were also stale or absent, and one actively described a change
that was not in the release — so the tag diff, not the guide, is the source of truth.

## Order to run them in

The dependency between the scripts is real — running them out of order wastes time on phantom
findings:

```bash
# BEFORE composer: can this upgrade be verified at all?
php $UP/check-test-coverage.php               # override surface vs. tests
php $UP/check-vendor-class-replacement.php     # classes declared in vendor namespaces
php $UP/check-legacy-css-classes.php           # legacy CSS classes vs what vendor still emits
php $UP/check-platform-alignment.php           # is this host a valid place to resolve at all?

# BEFORE composer: baselines + constraint preflight
php $UP/check-constraint-style.php            # patch-locked / merged constraints
php $UP/check-typed-members.php               # must be clean before upgrading
php $UP/check-dead-overrides.php snapshot
php $UP/twig-shadow-map.php snapshot
php $UP/check-plugin-usage.php || true        # record pre-existing damage
php $UP/check-config-constants.php || true
mkdir -p .spryker-upgrade/state && cp composer.lock .spryker-upgrade/state/composer.lock.before

# resolve
php $UP/check-constraint-style.php --relax
php $UP/resolve-constraints.php --max-rounds=8
php $UP/unpin-feature-driven-modules.php --match=...   # only on a cohort deadlock

# AFTER composer: detection, then resolution
php $UP/list-major-bumps.php                  # -> migration guide worklist
php $UP/check-typed-members.php               # FIRST: fatals block the console
php $UP/check-dead-overrides.php verify
php $UP/twig-shadow-map.php diff
php $UP/merge-shadowed-files.php --dry-run
php $UP/check-plugin-usage.php
php $UP/check-config-constants.php
```

## bin/check-test-coverage.php — is the upgrade verifiable? (run first of all)

Everything a Spryker project overrides fails *quietly* when core moves: a dead override still loads,
a replaced plugin stack still boots, a stale template still renders. So the first question is not
which modules changed, it is which customisations would notice if they stopped working.

```bash
php $UP/check-test-coverage.php            # gaps, highest risk first — exit 1 on HIGH+uncovered
php $UP/check-test-coverage.php --all      # every customised module
php $UP/check-test-coverage.php --top=50
```

Per `<Layer>/<Module>` it measures the risk surface — methods overriding a vendor parent (split into
**logic** overrides and dependency-provider/Config **wiring** overrides), vendor plugins registered in
dependency providers, and shadowed templates — then attributes coverage from `tests/*Test/<Layer>/<Module>/`
directories containing real `*Cest.php`/`*Test.php` files, plus any test file referencing a
`Pyz\<Layer>\<Module>\` class. Module test directories holding only `_support/` helpers are reported as
`supportOnlyTestDirs` and counted as **uncovered** — they look like coverage and assert nothing.

Logic overrides dominate the score; wiring and template counts are capped, because a dependency
provider registering 190 plugins is one file to assert, not 190 units of risk. Each gap comes with the
test type that would actually catch it (characterization test on the model output, assertion on the
plugin stack contents, acceptance path for a template). Report: `.spryker-upgrade/state/test-coverage-report.json`.

Two real projects for calibration: a demo shop reported 63 business-logic overrides with **none** in
a tested module, and a large customer project reported 1 056 with **483** untested plus 32 module
test directories holding only helpers. Both suites covered API endpoints rather than the override
surface — that is the normal starting point, and it is why Phase 0.5 offers to write characterization
tests *before* the upgrade: written afterwards they pin the upgraded behaviour and can no longer
detect that it changed.

Split the resulting worklist by what a host can prove: `Business/`, `Service/` and `Client/`
overrides are usually pure logic and provable green with mocked constructor dependencies, while
`Communication/` (forms, tables, controllers) and `Persistence/` need a container and a database.

## bin/check-vendor-class-replacement.php — classes declared in vendor namespaces

The one override style no other detector can see, and the most dangerous. A file that simply *is*
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
  Works today; collides the day upstream adds that class. This also catches a file under `src/Pyz/`
  declaring a non-Pyz namespace, which PSR-4 cannot load at all.
- `GLOBAL_NAMESPACE_COPY` — a copy that lost its `namespace` line, so it overrides nothing while
  still being parsed on every request.

Run it in Phase 0. The diff is the whole point: in the reference run a 1848-line copy of core's
`AbstractTable` differed by **26 lines**, and several of those were vendor features the copy was
simply missing — so upstream improvements were already being discarded before any upgrade started.
Report: `.spryker-upgrade/state/vendor-class-replacement-report.json`.

## bin/check-constraint-style.php — patch-locked constraints (upgrade blocker)

Run FIRST, before any composer update. A project pins hundreds of individual modules next to
the `spryker-feature/*` meta-packages; any module pinned `~x.y.z` or exact produces
"conflicts with your root composer.json require" instead of upgrading, so bumping the feature
packages alone cannot resolve.

```bash
php $UP/check-constraint-style.php            # report — exit 1 if patch-locked
php $UP/check-constraint-style.php --relax    # rewrite ~/exact -> ^, review the diff
```

Branch/wildcard constraints (`dev-main`, `*`, `@dev`) are reported as *floating* and left alone.
Exactly-pinned **third-party** packages are reported separately and never auto-relaxed — they
block Spryker modules needing a newer version (this is how an exact `"twig/twig": "3.20"` pin
blocks a security-driven bump), but third-party majors carry their own breaking changes, so each
is a manual decision. Report: `.spryker-upgrade/state/constraint-style-report.json`.

## bin/resolve-constraints.php — iterative constraint resolution

Conflicts arrive in waves: each root bump reveals the next transitive layer. This script runs
composer, parses the root-conflicts, raises those constraints to what the tree demands, and
repeats.

```bash
php $UP/resolve-constraints.php --max-rounds=8 [--dry-run]
```

Every bump is logged to `.spryker-upgrade/state/constraint-resolution-log.json`, and bumps crossing a **major**
boundary are flagged — that flagged list is the migration-guide worklist. Note it treats
`^0.19 -> ^0.20` as major, because caret on a `0.x` package is major-locked.

`UNRESOLVED` entries are deliberately left for a human: a third-party/eco package pinning an old
core major, or a transitive dependency blocked by a security advisory with no safe version. The
script never disables composer's advisory blocking to force a resolution.

## bin/unpin-feature-driven-modules.php — break a cohort deadlock

Some majors are cohort migrations, not module migrations: the Angular 20 move bumps ~20
`*-merchant-portal-gui` modules at once because they share `spryker/zed-ui`. With each pinned
individually, composer sees half the cohort demanding `zed-ui ^3` and half `^4`, and no per-package
bump breaks the tie (`resolve-constraints.php` reports OSCILLATION).

```bash
php $UP/unpin-feature-driven-modules.php --match=zed-ui,gui-table,merchant-portal-gui --dry-run
php $UP/unpin-feature-driven-modules.php --all      # every feature-governed module
```

Removes root pins for modules a `spryker-feature/*` package already governs, so the meta-packages
drive those versions — which is how Spryker's own 202606 demoshop is laid out. It reads the feature
requirements from **composer.lock**, because feature packages are metapackages and install no files.
Report: `.spryker-upgrade/state/unpinned-modules.json`.

## bin/check-typed-members.php — PHP 8.3 typed constants/properties (fatal on load)

The most common damage when moving onto a release whose core adopted PHP 8.3 typing: an untyped
override of a now-typed constant or property is a **compile-time fatal**, so it aborts
`vendor/bin/console` itself and every command with it.

```bash
php $UP/check-typed-members.php                                   # src/Pyz
php $UP/check-typed-members.php src/Pyz vendor/<v>/<pkg>/Bundles  # + merged tree
```

Scans **statically** rather than loading classes, for two reasons: PHP reports only the first
offending member per class (hiding the rest until you fix one and re-run), and a class that fatals
cannot be reflected at all. Reports `CONSTANT` (add the parent's type) and `PROPERTY` (usually the
redeclaration only narrowed a docblock and should be deleted — core may promote it as a typed
constructor property, which cannot be redeclared narrower). Report: `.spryker-upgrade/state/typed-members-report.json`.

## bin/merge-shadowed-files.php — batch three-way merge for Lane 2

```bash
php $UP/merge-shadowed-files.php --dry-run   # classify only
php $UP/merge-shadowed-files.php --apply     # write the clean merges
```

Runs `git merge-file` for every entry in `twig-conflicts-report.json` and sorts the outcomes:
`CLEAN` (applied), `IDENTICAL` (the override matched the old vendor file exactly — it carried no
customisation, so the vendor version is adopted and the override becomes a deletion candidate),
`CONFLICTED` (project file untouched; the conflicted merge is written to `<file>.merge-conflict`
for review) and `REMOVED` (vendor template gone — a semantic decision, never merged).

Expect a low clean rate where overrides restructured components rather than tweaking them — in the
reference run 15 of 163. `*.merge-conflict` files are gitignored review artifacts; never commit them.
Report: `.spryker-upgrade/state/merge-results.json`.

## bin/check-dead-overrides.php — removed vendor methods (UC1)

PHP happily lets `src/Pyz` override a method that a new module version deleted — the project
business logic silently stops being called.

```bash
php $UP/check-dead-overrides.php snapshot   # before composer update
php $UP/check-dead-overrides.php verify     # after — exit 1 on conflicts
```

`snapshot` records every Pyz method overriding a `Spryker*` ancestor method (~1350 in this
repo). `verify` reports `OVERRIDE_ORPHANED` (vendor method gone) and `CLASS_BROKEN` (vendor
parent class gone). Report: `.spryker-upgrade/state/dead-overrides-report.json`.

## bin/twig-shadow-map.php — shadowed frontend/presentation changes (UC2)

Project files fully shadow vendor files via template resolution, so vendor changes never reach
the page. Two shadowing surfaces are mapped:
- Yves themes: `src/Pyz/Yves/<M>/Theme/<theme>/` → `vendor/spryker-shop/<m>/.../Theme/default/`
- Zed presentation (Backoffice twig, OMS mail templates):
  `src/Pyz/Zed/<M>/Presentation/` → `vendor/spryker/<m>/.../Presentation/`

```bash
php $UP/twig-shadow-map.php snapshot   # before composer update
php $UP/twig-shadow-map.php diff       # after — exit 1 on conflicts
```

`snapshot` maps every shadowing project file (twig/scss/ts/js/css, ~691 in this repo across
117 module scopes) to its vendor counterpart, copies the pre-upgrade vendor files into
`.spryker-upgrade/state/vendor-baseline/` as merge bases, and records the vendor file listing per scope.
`diff` reports `VENDOR_FILE_CHANGED` with a ready-to-run
`git merge-file -p <project> <baseline> <new-vendor>` three-way merge command,
`VENDOR_FILE_REMOVED` for renamed/deleted vendor files, and informational `NEW_VENDOR_FILE`
notes for files that appeared in an overridden module scope (template splits, new
sub-components an override may need to reference).

Known POC limits: assumes vendor theme `default`; Glue has no twig in this repo.

## bin/check-plugin-usage.php — replaced plugin stacks (UC3)

```bash
php $UP/check-plugin-usage.php   # any time — exit 1 on MISSING
```

Scans all Pyz dependency providers for imported vendor plugin classes and reports:
- `MISSING` — plugin class gone after upgrade (stack replaced/removed); must be rewired.
- `DEPRECATED` — the docblock note usually names the replacement stack; rewire proactively.
- `PROJECT PLUGINS NEEDING PORTING` — Pyz plugins implementing a deprecated/removed vendor
  interface; these need code porting, not just rewiring.

Report: `.spryker-upgrade/state/plugin-usage-report.json`.

## bin/check-config-constants.php — broken config references (configuration strategy)

Project configuration (`config/**/*.php`, 384 vendor type imports + 816 constant references in
this repo) keys settings by vendor `*Constants` interfaces. An upgrade that removes/renames an
interface or constant breaks bootstrap of every application.

```bash
php $UP/check-config-constants.php   # any time — exit 1 on problems
```

Reports `TYPE_MISSING` (imported/inline vendor type gone) and `CONSTANT_MISSING` (constant gone
— config fatals, or a renamed setting silently stops applying). Report:
`.spryker-upgrade/state/config-constants-report.json`.

## bin/list-major-bumps.php — majors, migration guides, feature gate input

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

## CI wiring

Two of these are cheap enough to gate every PR, and both catch damage that is otherwise invisible
until runtime:

```bash
php $UP/check-typed-members.php     # fatals on class load
php $UP/check-plugin-usage.php      # missing (not deprecated) plugins
```

Neither needs a snapshot, a database or a search backend — just an installed `vendor/`. Failing the
build on exit code 1 turns "the Back Office 500s after deploy" into a red pipeline.

For dependency-bumping PRs (dependabot, the Spryker upgrader), also wrap the change in a
`snapshot` → `verify` pair for `check-dead-overrides.php` and `twig-shadow-map.php`, since those
compare against pre-upgrade state and cannot work from a single point in time.

Note `check-constraint-style.php` exits 1 merely for *reporting* exactly-pinned third-party packages,
which is informational — treat its output as a review item, not a gate.

`check-test-coverage.php` is also cheap enough for CI, but gate it on a *ratchet* rather than on zero:
record `totals.logicOverridesInUncoveredModules` from `.spryker-upgrade/state/test-coverage-report.json` and fail when a
PR increases it. That way new customisation arrives with a test instead of demanding a coverage project
before anyone can merge.

## Known limits

- **Yves theme fallback** assumes the vendor theme is `default`.
- **`check-typed-members.php`** resolves the parent from source text; a parent reached through an
  unusual alias or a trait may be missed. It also only inspects classes that declare untyped members,
  so it says nothing about classes that are already fully typed.
- **`check-test-coverage.php`** attributes coverage by module path and by class reference, so a test
  that exercises a customised module only indirectly (through an API endpoint, or via a differently
  named suite) counts as absent. It measures *presence* of tests over the risk surface, never their
  quality or assertion depth — a covered module with one weak assertion still reads as covered.
- **`resolve-constraints.php`** cannot decide cohort migrations — it detects the deadlock
  (OSCILLATION / "would LOWER") and hands over to `unpin-feature-driven-modules.php`.
- **No detector covers** Propel schema merges, glossary keys, ACL/navigation for new Backoffice
  routes, or pure behavioural change. Those are Phase 5 process gates and tests, and the skill's
  matrix marks them as such.

## bin/check-legacy-css-classes.php — legacy CSS classes vs what vendor actually emits

For releases that cross a CSS framework major (Bootstrap 3 → 5). The instinct is to take the
framework's changelog, grep the project for removed classes and rewrite them. On a real upgrade that
instinct was wrong about **six of seven** classes, and two of the "fixes" would have caused an
outage.

```bash
php $UP/check-legacy-css-classes.php                  # Bootstrap 3->5 default list, Zed
php $UP/check-legacy-css-classes.php --layer=Yves     # storefront instead
php $UP/check-legacy-css-classes.php --classes=a,b    # your own list
php $UP/check-legacy-css-classes.php --verbose        # show the vendor evidence lines
```

The question it asks is never "did the framework remove this class" but **"does vendor, at the
installed release, still emit or select it itself"** — answerable from source, with no browser, no
compiled CSS and no built assets. Verdicts:

- `MIGRATE` — absent from vendor templates *and* vendor JS. A genuine leftover; safe to convert.
- `KEEP` — vendor still emits it in its own templates, so core styles it deliberately.
- `KEEP (JS)` — **vendor JavaScript selects or toggles it.** Rewriting detaches project markup from
  vendor behaviour, causing the breakage the migration was meant to prevent. On the validation run
  this covered `has-error` (`gui` `tabs.js` marks invalid tabs), `hidden` (`init.js`/`tabs.js`
  toggle it), `form-group` (`sales-order-threshold-gui` does
  `.parents('.form-group').addClass('hidden')`), `btn-default` (`init.js` swaps it on hover) and
  `control-label` (`discount`'s query builder generates the markup).
- `PAIR` — vendor emits the legacy class *and* its modern equivalent on the same element
  (`nav-item pull-left float-start`). Mirror the pair; do not replace.

It scans **every** Spryker vendor package's `assets/<Layer>/js`, not just the module you assume owns
the behaviour — that breadth is the whole point. A hand pass over `gui` alone found 2 of the 5 JS
dependencies.

Exit 1 only when something is genuinely `MIGRATE`. It never rewrites anything: `KEEP (JS)` rows in
particular need a human to leave them alone. If vendor templates cannot be found at all it exits 2
rather than reporting everything as `MIGRATE`, since that is the dangerous direction to be wrong in.

## Scope rule these detectors serve

Every detector here answers one question: **did the project's existing behaviour survive the version
bump?** None of them asks whether the project should adopt something new.

That distinction is deliberate. An upgrade updates what the project already has; a version bump is not
consent to integrate the architecture, DI mechanism or feature that the new version enables. So a
capability the project never used and still does not use is **not** a finding, and "the new version
wants you to do X" is never a reason to do X inside an upgrade. When restoring existing behaviour
appears to require adopting something new, that is a vendor BC break to report — not a re-architecture
to perform. See the Scope section of `SKILL.md`.

## bin/check-platform-alignment.php — is this host a valid place to resolve at all?

Run this in **Phase 0, before the first `composer update`**. It answers one question: will a lock
resolved on this machine install on the machine the project actually runs on?

```bash
php $UP/check-platform-alignment.php                 # report
php $UP/check-platform-alignment.php --target=8.3.2  # override the detected deployment PHP
```

The failure it prevents is quiet and expensive. A project declares `require.php: ">=8.3"` and runs
`spryker/php:8.3`; a developer on PHP 8.5 satisfies `">=8.3"`, so composer objects to nothing and
resolves dev tooling (`doctrine/instantiator`, `symfony/type-info`, `lcobucci/clock`, `phpunit`) to
versions needing PHP 8.4+. The lock installs on that laptop and in no container:

```
Your lock file does not contain a compatible set of packages.
doctrine/instantiator 2.1.0 requires php ^8.4 -> your php version (8.3.32) does not satisfy that
```

On the validation run this surfaced only when `docker/sdk up` died at `composer install`, many phases
after the damage — and it meant the entire characterization suite had been running on the wrong PHP
minor, so "the tests pass" had not meant what it appeared to mean.

It reports:

- **host PHP vs every `deploy*.yml` image tag** — and flags deploy files that *disagree* with each
  other, since then there is no single platform to resolve for;
- **`config.platform.php` absent** while the host and deployment minors differ — the actual hazard;
- **`config.platform.php` pointing at the wrong minor**;
- **locked packages that cannot install on the target PHP** — the decisive check, and what
  `docker/sdk up` would otherwise discover the hard way;
- **extensions the lock requires that this host lacks** (`ext-redis`, `ext-pgsql`). This is a separate
  failure: a platform pin fixes the PHP *version*, not the *extension set*, so composer cannot resolve
  those packages here at all. The answer is to run composer in the container — never
  `--ignore-platform-req`, which fakes the requirement and re-creates the uninstallable lock.

Target precedence is `--target` → `config.platform.php` → the image tag's `.0` floor. The declared
platform wins because it is what composer actually resolves against; inferring `.0` from an `8.3` tag
otherwise false-positives on any package with a patch-level constraint such as `~8.3.2`.

Exit 1 on anything that can produce an uninstallable lock, 0 when aligned.
