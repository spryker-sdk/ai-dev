# Verification and report — Phase 5 to Phase 7 detail

Read the Phase 5 section before the first regeneration command, the publish check section before the
first `storage-search-counts.php` snapshot, the Phase 6.5 section before the Back Office crawl, and the
report section before writing Phase 7.

## Phase 5 — regeneration is not read-only

Check `git status` after every Phase 5 command. `propel:install` runs migration cleanup and deletes
tracked `src/Orm/Propel/*/Migration_*` files (gitignore does not protect files committed before the
ignore rule), `propel:config:convert` writes generated config into a path the project may not ignore,
and `npm`/asset builds rewrite lock files. Restore deletions with `git checkout --`, gitignore new
generated paths, and keep genuine lock updates in their own commit (matrix #60–#62).

Command names differ between projects and releases — read `console list` instead of trusting the list
in SKILL.md. Some projects register `propel:diff` and `search:setup:source-map` rather than
`propel:migration:diff` and `search:setup:index-map`.

- Non-empty migration diff → show the developer before anything touches a database.
- A clean `propel:diff` on a freshly-created database proves almost nothing. `up --data` builds the DB
  from the post-upgrade schema, so an XML↔DB comparison necessarily agrees. It does not tell you what
  migration an existing production database needs. To answer that, boot the pre-upgrade lock, import,
  then upgrade the code and diff against that database. Report which question you actually answered.
- Bump the project's own `php` constraint to match core. Count what the lock requires
  (`composer.lock` → most common `require.php` among spryker packages); if core is on `>=8.3` the
  project must be too, and the typed-member fixes need it anyway.

### The code sniffer

Use the entry point Phase 0 recorded: `vendor/bin/console code:sniff:style` in most Spryker projects,
or the project's own phpcs setup (`phpcs.xml`/`phpcs.xml.dist`, a composer `cs-check`/`cs-fix`
script). Read `console code:sniff:style --help` for how it takes a path or module.

1. List the PHP files the upgrade changed:
   `git diff --name-only --diff-filter=AM "$(cat .spryker-upgrade/state/base-ref)" -- '*.php'`.
2. Auto-fix those files first (`code:sniff:style -f <path>`, or `phpcbf <files>`), and review the
   fixer's diff — it is part of the upgrade diff.
3. Run the sniffer over the same scope Phase 0 used and compare with the sniffer baseline
   (`sniff-post-tooling.txt` when Phase 1.2 re-took it, else `sniff-baseline.txt`; after a deferred
   tooling step, the Phase 0 findings plus the new-rule findings). A violation that is not in the
   baseline blocks, like a PHPStan regression; fix it by hand. Never add an ignore rule or exclude a
   path.

### Comparing the test run with the baseline

Compare per suite against the test baseline — `codecept-post-tooling.txt` when Phase 1.2 re-took it,
else `codecept-baseline.txt` — with the exact invocation Phase 0 pinned. A test that was red in the
baseline and is still red is not upgrade damage — list it under "Tests already red before the
upgrade". A test that was green and is now red is classified in Phase 5.5 (app damage, harness
damage or environment noise).

## Publish check — storage and search row counts (Phase 0, Phase 4.9 / 5.5)

`storage-search-counts.php` counts the rows of every `*_storage` and `*_search` table. A publisher, a
storage or search plugin, or an event subscriber lost in the upgrade leaves its table with fewer rows,
or none, after the same import. The database is reachable only from the cli container, so the script
is copied into the state directory (inside the project) and run there:

```bash
cp $UP/storage-search-counts.php .spryker-upgrade/state/
script -q /dev/null docker/sdk cli php .spryker-upgrade/state/storage-search-counts.php --snapshot before
# upgraded environment, same data imported and published:
script -q /dev/null docker/sdk cli php .spryker-upgrade/state/storage-search-counts.php --snapshot after
php .spryker-upgrade/state/storage-search-counts.php --compare before after
```

It connects with the variables the Docker SDK sets in the cli container: `SPRYKER_DB_ENGINE` (`mysql`
for MySQL and MariaDB, `pgsql` for PostgreSQL), `SPRYKER_DB_HOST`, `SPRYKER_DB_PORT`,
`SPRYKER_DB_DATABASE`, `SPRYKER_DB_USERNAME`, `SPRYKER_DB_PASSWORD`. `--dsn`, `--user` and
`--password-env <VAR>` override them. A snapshot counts the database the cli container is configured
for; the JSON records its name, and `--compare` notes a different engine or database.

The comparison is valid only when both snapshots meet the same conditions; each snapshot records its
label, timestamp, engine, database name, table count and total rows:

- the same `data/import` set and the same stores;
- a full import (`docker/sdk up --data`, or the same `data:import` configuration);
- the publish queues drained before the snapshot: the worker idle and every queue at length 0
  (`script -q /dev/null docker/sdk cli vendor/bin/console queue:worker:start --stop-when-empty`, then
  the broker's management UI). A snapshot taken while events are still queued under-counts.

Reading `--compare` (`storage-search-counts-report.json`):

- `dropped`, `emptied` (rows before, 0 after) or `disappeared` → upgrade damage, exit 1. Trace the
  table to its entity's publisher wiring: the `*Storage`/`*Search` module's publisher plugins in
  `PublisherDependencyProvider`, its event subscriber in `EventDependencyProvider`, the synchronization
  plugins in `SynchronizationDependencyProvider`, and the queue processor in `QueueDependencyProvider`.
  `check-plugin-usage.php` names a plugin that vanished; a Lane 0 guide names a renamed one. Fix,
  re-publish (`publish:trigger-events` for the entity), drain the queues, snapshot `after` again.
- `appeared` → a table the new version adds; informational.
- `grew` → informational; more rows after the same import usually mean a new publisher. Confirm it is
  not a duplicate.

Without the `before` snapshot (`check-baselines.php --no-data`) the publish check is not done; the
report says so and names matrix #80 as unverified.

## Phase 6.5 — Back Office crawl, then the verifier

### The crawl

```bash
SPRYKER_BACKOFFICE_USER=<user> SPRYKER_BACKOFFICE_PASSWORD=<password> \
  php $UP/backoffice-smoke.php --url <zed base url> [--max 200] [--timeout 30] [--insecure]
```

It walks the whole Back Office navigation and requests every table's data endpoint. Phase 0 ran it
with `--baseline`, which writes `backoffice-smoke-baseline.json`; without the flag it writes
`backoffice-smoke-report.json` and marks each problem `inBaseline`. Exit 0 means no problem, 1 any
problem, 2 a usage error, an unreachable URL, a login page without a login form, or a failed login;
exit 2 writes no report and no baseline. `--insecure` is for the local self-signed certificate only.
The credentials come from the environment: the developer exports them, or they are the
repository-seeded demo account that `spryker-runtime` documents. Never write them into a file.

Reading the report:

- a problem not in the baseline → upgrade damage, however unrelated the page looks to what the upgrade
  touched. A whole family failing together (every table, every page of one module) usually means two
  vendor packages that must move together (matrix #72): read the failing endpoint's error in the
  container log, find the lagging package, fix the constraint, re-run the crawl.
- a table endpoint failing only here → the crawl requests table endpoints as a plain GET without
  DataTables parameters, which some tables reject. Confirm it in the browser through the verifier
  (below) before calling it damage.
- `inBaseline` → pre-existing; list it in the report, do not count it as damage.
- in the baseline but passing now → note it; the upgrade fixed something.

The crawl is deterministic and cheap — run it as soon as the upgraded stack is up in Phase 4.9, and
again in Phase 6.5 against the final code. It must show no new failure before the verifier runs.

### The visual checks — delegate them to `spryker-verifier`

Tests and the crawl see status codes and exceptions. They do not see a stylesheet that no longer
defines a class, a table that renders but paginates wrongly, a layout that double-wraps, or a JS bundle
that throws on load and silently disables a widget. Those checks go to the plugin's `spryker-verifier`
agent (shipped in the plugin's `agents/`; it drives the app through `spryker-qa-coverage` and
`spryker-runtime`, logs in with the seeded accounts, and never edits code). Spawn it with the Agent
tool rather than driving the browser yourself.

Give it acceptance criteria only — the pages and what must hold on each — not your own conclusions.
Build the criteria from what the upgrade touched:

| If the upgrade touched… | Criterion for the verifier |
|---|---|
| a `*-gui` / CSS framework major | every page whose template was merged in Lane 2, and any page using a legacy class the detector marked `KEEP (JS)`, renders styled with no console error |
| `AbstractTable` or any table override | one table with filter + sort + paging applied together shows the right rows, and the footer row count is right while a filter is active |
| a login / layout template | the login page and one authenticated page render, with the project's own delta (a font, a logo) intact |
| a shadowed template that was merged | that exact page matches the pre-upgrade screenshot where Phase 0 captured one |
| the asset toolchain (`oryx-*`, webpack, node) | the page loads the bundle the build just produced, not a stale one from `public/` |
| a storefront (only if `yves` exists) | home, a category, a product page, cart and checkout up to the summary step render with no console error |

Per page, the verifier captures a screenshot and reads the browser console — a clean-looking page with
`Uncaught TypeError` in the console is a failure. Run one browser-driving verifier at a time. Save its
verdicts to `.spryker-upgrade/state/verifier-report.md` and quote them in the report.

Console errors, 500s and missing styling are upgrade damage. An unvisited page counts as unverified:
record what could not be reached and why. If the environment cannot boot, say which of these checks
that costs rather than omitting the section.

## Phase 7 — what the report contains

[EXAMPLE-UPGRADE-REPORT.md](../EXAMPLE-UPGRADE-REPORT.md) shows the shape. Required sections:

1. **Versions moved** — release before/after, majors highlighted with the Lane 0 migration-guide table
   (guide URL or "none published", steps applied, steps deliberately not applied as new capabilities,
   steps skipped and why).
2. **Tooling, Docker SDK and deploy files** — tool versions before/after, Docker SDK before/after, the
   deploy changes, differences from the reference kept as project choices, and whether the tooling
   moved in Phase 1.2 or after Phase 2.
3. **Damage found and resolved, per lane**, with file links; remaining `upgrade-debt` markers with
   their removal condition; constant overrides whose core value changed, with the decision per
   constant.
4. **Deprecations** — the gate #3 table: applied, deferred, kept, with the consequences column.
5. **Static checks vs baseline** — detectors, PHPStan, sniffer, evaluator; only regressions count.
   `check-added-comments.php` result (must be clean).
6. **Tests** — suites run, suites skipped and why, pass/fail per suite against the baseline, failures
   split into app damage / harness damage / environment noise. Then, under its own heading, **Tests
   already red before the upgrade**, so nobody reads them as upgrade damage.
7. **Back Office crawl and verifier** — new failures (must be none), pre-existing failures, the
   verifier's verdicts, what was not reached.
8. **Publish check** — `storage-search-counts.php --compare before after`: tables that lost rows and
   how each was resolved, or why the check did not run.
9. **Pending DB migrations**, and which migration question was answered (fresh DB vs pre-upgrade DB).
10. **Features** — gate #4 decisions (accepted, deferred, never), and REMOVED packages with what
   replaced each.
11. **Performance recommendations** — `performance-report.json`: the SOL-477 packages below their
    minimum, the navigation cache state, the gate #4 decision on each, and the other guideline pages'
    findings ([performance.md](performance.md)).
12. **What is proven and what is not** — give each claim its scope ("the Zed functional suites pass in
    the container" and "the suite passes" are different statements). For every check that did not run,
    name it and the matrix row it would have caught.

Ask before committing; suggest one PR per release group.
