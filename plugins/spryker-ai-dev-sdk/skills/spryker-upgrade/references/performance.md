# Performance fixes — method

Read this on entry to Phase 6.2, and before writing the "Performance fixes" section of the Phase 7
report. The fixes come from Spryker's
[performance guidelines](https://docs.spryker.com/docs/dg/dev/guidelines/performance-guidelines/performance-guidelines.html)
as published when the upgrade runs: Phase 6.2 fetches the pages, lists them from the guideline index,
and derives the fixes from each page and the project. This file holds the method; the content is the
pages themselves.

## Contents

- [The rules](#the-rules)
- [Phase 6.2 step by step](#phase-62-step-by-step)
- [Fetching the guidelines](#fetching-the-guidelines)
- [The page list](#the-page-list)
- [From a page to fixes](#from-a-page-to-fixes)
- [Size and the developer's decision](#size-and-the-developers-decision)
- [Customer types and page sets](#customer-types-and-page-sets)
- [Comparing the rendered result](#comparing-the-rendered-result)
- [Packages](#packages)
- [Schema changes](#schema-changes)
- [The status file](#the-status-file)
- [The report section](#the-report-section)

## The rules

1. **The performance fixes are part of the upgrade's deliverable.** The developer asks for them with
   the upgrade, so the Scope rule ("versions, not architecture") does not block them. Every guideline
   page is accounted for — with its fixes, or with the reason it has none — and every fix gets a
   final status.
2. **Visible behaviour stays the same.** A fix is applied only when the shop shows and does the same
   for every customer type afterwards. A fix that changes what users see or can do (a smaller page
   size, a removed feature, data that appears later, other facet counts) is not applied: it is
   recorded `not-applied` with the proposed change, for the developer to decide.
3. **The size decides who decides.** Every applicable fix is sized at detection (§ Size and the
   developer's decision). `S` fixes are applied without a question; every `M` and `L` fix is applied,
   deferred or skipped as the developer decides, in one question for all of them.
4. **One fix at a time.** Apply one fix, run the checks it names, compare the rendered pages it
   touches before and after for every customer type the project has. A failed check or a difference
   is a failure: revert that fix and record it `reverted` with the reason. Then the next fix.
5. **Each applied fix is its own commit** (`perf: <fix ID> <title>`), and its status entry lists the
   files it touched.
6. **Operator fixes are recorded, not attempted.** A fix only the hosting, the CDN, the scheduler or an
   APM account can deliver is `not-applicable` with the reason `operator:` and what the developer
   passes on.
7. **Damage stays damage.** The `PRODUCT_GROUP_WIDGET_REMOVED` finding of `check-performance.php` is
   existing behaviour lost by the upgrade; Lane 2 restores it ([lanes.md](lanes.md)). A guideline fix
   that raises `spryker-shop/shop-ui` past 1.103.0 names that Lane 2 change in its evidence.
8. **Nothing from memory.** Fixes come from the fetched pages. When the pages cannot be fetched, no
   fix is derived (§ Fetching the guidelines).

## Phase 6.2 step by step

1. **Fetch** the guideline sources into `.spryker-upgrade/state/spryker-docs` (§ Fetching the
   guidelines).
2. **List the pages:** `php $UP/check-performance.php --init --docs .spryker-upgrade/state/spryker-docs`
   writes `.spryker-upgrade/state/performance-fixes.json`, one entry per page, `pending`. Follow what
   it prints for linked pages missing from the copy, and run it again (§ The page list).
3. **Code candidates:** `php $UP/check-performance.php --scan-code` writes
   `performance-code-scan.json` — loop queries, storage and HTTP calls in loops, key scans, joins,
   SQL functions in `where`, `createTemplate`, storage writes, HTTP clients in Yves, Glue and Client.
   Candidates, not findings; the sub-agents read them against the pages.
4. **Derive the fixes.** Group the pages by topic, about five per group, and spawn one read-only
   sub-agent (`Explore`) per group, together, each with: its page paths in the docs copy, the project
   root, `performance-code-scan.json`, [§ From a page to fixes](#from-a-page-to-fixes),
   [§ Size and the developer's decision](#size-and-the-developers-decision), and the task "read each
   page in full; for every recommendation a project can act on, derive the fix, run its Detect step
   against the project, size it and record it with the files it would touch and the expected gain;
   record a page without such a recommendation with `--no-fix`; change nothing in the project". Each
   records with `--set` and `--no-fix`; the script locks the status file, so the sub-agents record in
   parallel. The main agent keeps only their summaries.
5. **Check the derivation:** `php $UP/check-performance.php --verify` lists every page still
   `pending`. Derive those before going on.
6. **The developer's decision:** `php $UP/check-performance.php --decisions` lists the `M` and `L`
   fixes. Ask once with a structured question, every fix with its size, files and expected gain, and per fix
   apply now / defer / skip; record each answer with `--decide` (§ Size and the developer's decision).
7. **Apply the `pending` fixes, one at a time** — the `S` fixes and the `M` and `L` fixes the developer
   chose to apply — in this order: packages, then configuration and wiring, then code, then schema
   changes. For each fix:
   1. capture the pages it touches for every customer type ([§ Comparing](#comparing-the-rendered-result));
   2. apply its Apply step, nothing else;
   3. run its Verify step and the checks it implies: the build (`npm run yves` for templates and
      assets, `docker/sdk up -t` for config and recipes, `composer install` and `transfer:generate` for
      packages), the covering tests (the Phase 0.5 tests and the suites of the touched modules),
      PHPStan and the sniffer on touched PHP files, the Back Office crawl when a Zed or Merchant Portal
      page is involved, the publish check when publishing is involved;
   4. capture the same pages again and compare;
   5. pass: commit, then `--set <ID> --status applied --files <a,b> --evidence "<checks and comparison>"`;
      fail: restore the touched files (`git checkout -- <files>`, delete the files the fix created),
      then `--set <ID> --status reverted --reason "<what failed>" --evidence "<output>"`.
8. **Gate:** `php $UP/check-performance.php --verify` exits 0. Phase 6.5 then verifies the result, and
   Phase 7 does not start before it exits 0.

## Fetching the guidelines

```bash
DOCS=.spryker-upgrade/state/spryker-docs
rm -rf "$DOCS"
git clone --depth 1 --filter=blob:none --sparse https://github.com/spryker/spryker-docs.git "$DOCS"
git -C "$DOCS" sparse-checkout set docs/dg/dev/guidelines/performance-guidelines
```

Without git, or when the clone fails, fetch through the GitHub contents API into the same paths:
`curl -fsSL "https://api.github.com/repos/spryker/spryker-docs/contents/<folder>?ref=master"` returns
the folder's entries; download each `"type": "file"` entry's `download_url` to `$DOCS/<path>`
(`curl -fsSL <download_url> --create-dirs -o "$DOCS/<path>"`) and list each `"type": "dir"` entry
the same way. Start with `docs/dg/dev/guidelines/performance-guidelines`. A single page:
`curl -fsSL https://raw.githubusercontent.com/spryker/spryker-docs/master/<path> --create-dirs -o "$DOCS/<path>"`.

**Offline.** When both fail, record it with the failed commands' output:

```bash
php $UP/check-performance.php --unreachable --evidence "<git clone and curl output>"
```

Every page without fixes becomes `not-checked` with the reason "docs unreachable"; `--verify` exits 0
with `NOT RUN`, and the report's Performance fixes section states that Phase 6.2 did not run, why,
and that the developer runs it once the docs are reachable. With the docs reachable again,
`--init` sets the `not-checked` pages back to `pending`.

## The page list

`--init` reads the index page `docs/dg/dev/guidelines/performance-guidelines/performance-guidelines.md`
of the copy and lists, in this order: every page the index links (in the guideline folder or
elsewhere in the docs), then every page of the guideline folder and its subfolders the index does
not link. The index itself is not a page. Each entry has the page's slug (its file name without
`.md`), path, title (from the front matter), URL, `source` (`index` or `folder`), whether the copy has
it (`inDocs`) and, for a git copy, whether the repository has it (`inRepository`).

- **A linked page the repository has outside the checked-out folder:** `--init` prints the
  `git -C … sparse-checkout add <folders>` command. Run it, then `--init` again. With a curl copy,
  fetch the page by its raw URL, then `--init` again.
- **A linked page the repository does not have** (`inRepository: false`, or 404 on the raw URL):
  `--no-fix <slug> --reason "linked page not published" --evidence "<ls-tree or curl output>"`.
- **Re-running** `--init` keeps every recorded page and fix and adds pages that appeared since.

## From a page to fixes

A **fix** is one change a page recommends that the project can make or check: a package minimum, a
config key, a plugin or class wiring, a code pattern, a template or asset change, an index, a deploy
file or recipe setting, a console command at deploy time. One fix per independent change: a package
set the page installs together is one fix; a config key and the wiring it needs are one fix; a code
pattern is one fix listing every occurrence. The ID is `<page slug>-<n>`, numbered in page order.

For each fix, derive from the page:

| Part | What it holds |
|---|---|
| Kind | packages, config, wiring, code, assets, schema, deploy, decision (alters visible behaviour), operator (outside the code) |
| Size | `S`, `M` or `L` (§ Size and the developer's decision) |
| Touches | the files the Apply step would change |
| Gain | the expected gain as the page states it (a measured figure, or what the page says improves) |
| Pages | the page sets it touches (§ Customer types and page sets) |
| Detect | what shows the project's state, with the command: installed version in `composer.lock`, the key in `config/Shared/config_*.php`, the plugin in a DependencyProvider, a project override class, a `grep` or `--scan-code` candidate (file:line), a deploy file key |
| Apply | the change the page names, in the project, never in `vendor/` |
| Verify | the checks of step 7 plus what the page names (a console command, a header, a log line) |
| Does not apply | already in place; the precondition is absent (package not in the lock — never added — or the feature not used); operator-only |

The Detect result decides the recorded status:

| Detect result | Record |
|---|---|
| applicable | `pending`, with `--title` (the page's recommendation), `--detected` (what the project has, file:line or version), `--size`, `--touches` and `--gain` |
| already in place, or precondition absent | `not-applicable`, reason and evidence |
| operator-only | `not-applicable`, reason `operator: <what the developer passes on>` |
| applying it changes what users see or can do | `not-applied`, sized, reason `proposal: <the change and its visible effect>` |
| a minimum in a higher major than installed | `not-applied`, sized, reason naming the major (Lane 0 in its own upgrade) |

A page **without a fix** — it describes a profiling or troubleshooting procedure, explains a concept,
or every recommendation needs a tool or account the project does not have — is recorded
`--no-fix <slug> --reason "<why>" --evidence "<what the page asks for>"`.

## Size and the developer's decision

| Size | The fix is | Who decides |
|---|---|---|
| `S` | a config value or a flag | applied without a question, under the rules above |
| `M` | a package update, or a change in a few files | the developer |
| `L` | a code refactor, a schema change, or many files | the developer |

A fix that fits two sizes takes the larger. Size, touches and gain are recorded with the fix when it
is detected; `not-applicable` fixes need none of them.

After the derivation, `--decisions` lists every `pending` `M` and `L` fix with its size, files and
expected gain. One a structured question covers them all (multiSelect, or one choice per fix: apply now /
defer / skip). Each answer is recorded:

```bash
php $UP/check-performance.php --decide <ID> --decision apply
php $UP/check-performance.php --decide <ID> --decision defer --reason "<the developer's reason>"
php $UP/check-performance.php --decide <ID> --decision skip --reason "<the developer's reason>"
```

`apply` keeps the fix `pending` for step 7; `defer` records `deferred` (the fix goes to the
Performance backlog of the report); `skip` records `skipped`. `deferred` and `skipped` come only from
`--decide`, so they always carry the developer's decision and reason. A decision can change while the
fix is `pending`, `deferred` or `skipped`; `--decide` rejects an `S` fix and a fix already applied.
An `M` or `L` fix is not applied without the `apply` decision.

## Customer types and page sets

The customer types are the ones the project has: **guest** always; **customer** when the storefront
has a login (`spryker-shop/customer-page`); **business user** when the project has company accounts
(`spryker/company-user` in the lock). The accounts come from the project's demo data or the
environment, as the Back Office crawl's credentials do; never write them into a file.

| Page set | Pages |
|---|---|
| storefront-catalog | home, a category page, a search result, a product detail page (with a product group, options, reviews and offers where the project has them) |
| storefront-cart | the cart with several items, checkout up to the summary, the order placement and its confirmation mail |
| storefront-account | customer overview, order history, an order detail |
| storefront-b2b | company pages, quick order, shopping lists (business user) |
| back-office | `backoffice-smoke.php` against its baseline, plus the Back Office pages the fix names |
| merchant-portal | dashboard, offers, orders, profile (when the project has a Merchant Portal) |
| publish | re-import or `publish:trigger-events` for the entity, queues drained, `storage-search-counts.php` `--snapshot` before and after the fix, `--compare` shows no dropped, emptied or disappeared table |
| cli | the console commands the fix names exit 0 |

## Comparing the rendered result

Before applying a fix, have the `spryker-verifier` agent capture every page of the fix's storefront,
Back Office and Merchant Portal sets, for every customer type: a full-page screenshot, the visible text
and the browser console. Store them under `.spryker-upgrade/state/perf/<ID>/before/`; after the fix,
the same under `after/`. The fix passes when the visible text is identical and the screenshots show no
difference, apart from values that change on every request (CSRF tokens, timestamps, randomised
recommendations — name them in the evidence), and the console has no new error. Any other difference
is a failure. A fix without pages (deploy files, operator) is verified by its own checks only.

## Packages

For a fix whose page names package minimums (`M`, applied after the developer's `apply` decision):

1. Compare each package with `composer.lock`. A package absent from the lock is never added.
2. A minimum in a higher major than the installed one: not raised — that major goes through Lane 0 in
   its own upgrade. The fix is `not-applied` for that package.
3. `script -q /dev/null docker/sdk cli composer update <package>:"^<minimum>" … --with-dependencies`
   for all remaining packages of the fix in one command, in the container. `composer.json` is not
   edited for it. A failure to resolve: `composer why-not <package> <minimum>`, revert the lock, record
   the fix `not-applied` with the blocking constraint.
4. Checks: `composer install`, `transfer:generate`, `propel:diff` (a non-empty diff goes through
   § Schema changes), `check-plugin-usage.php`, the covering tests, the Back Office crawl, and the
   fix's page sets compared for every customer type.

## Schema changes

A fix that changes the database schema (an index, a table) is `L` and creates a migration. After the
`apply` decision, show the developer the `propel:diff` result with a structured question before it touches a
database; apply it only on their yes, otherwise restore the schema files and record
`--decide <ID> --decision skip --reason "<the developer's answer>"`, with the diff in the reason. The
migration file is part of the fix's commit.

## The status file

`.spryker-upgrade/state/performance-fixes.json`: the docs copy (`docs.dir`, `docs.commit`) and
`pages`, keyed by slug, each with `path`, `title`, `url`, `source`, `inDocs`, `inRepository`,
`status`, `reason`, `evidence` and `fixes` — a list of `{id, title, detected, size, touches, gain,
decision, status, files, reason, evidence}`.

| Page status | Required | Meaning |
|---|---|---|
| `pending` | — | not derived yet; `--verify` fails |
| `derived` | at least one fix | set by the script when a fix is recorded |
| `no-fix` | `reason`, `evidence` | the page has no change the project can make |
| `not-checked` | `reason`, `evidence` | the docs were unreachable (`--unreachable`) |

| Fix status | Required | Meaning |
|---|---|---|
| `pending` | `size`, `touches`, `gain` | applicable, not applied yet; `--verify` fails |
| `applied` | `files`, `evidence`; `decision: apply` when `M` or `L` | changed, verified, committed |
| `not-applicable` | `reason`, `evidence` | already in place, the precondition is absent, or `operator:` |
| `not-applied` | `reason`, `evidence` | applicable, not applied without a question: visible behaviour would change, or a constraint or a higher major blocks it; the reason holds the proposal or the blocker |
| `deferred` | `decision: defer`, `reason` | the developer chose to apply it later; listed in the Performance backlog |
| `skipped` | `decision: skip`, `reason` | the developer chose not to apply it |
| `reverted` | `reason`, `evidence`; `decision: apply` when `M` or `L` | applied, failed its checks or the comparison, reverted |

Every fix keeps its `title` and `detected`; every status except `not-applicable` also its `size`,
`touches` and `gain`. An `S` fix takes no decision; an `M` or `L` fix in any status except
`not-applicable` and `not-applied` needs the developer's decision. Evidence is short and checkable:
the command and its result, file:line, or the comparison verdict per customer type.

Recording:

```bash
php $UP/check-performance.php --set <slug>-1 --status pending --title "<recommendation>" --detected "<file:line or version>" --size S --touches config/Shared/config_default.php --gain "<what the page names>"
php $UP/check-performance.php --set <slug>-1 --status applied --files config/Shared/config_default.php --evidence "<checks>; pages identical for guest, customer"
php $UP/check-performance.php --set <slug>-2 --status not-applicable --title "<recommendation>" --detected "<what the project has>" --reason "operator: <what to pass on>" --evidence "<no setting in the repository>"
php $UP/check-performance.php --no-fix <slug> --reason "<why the page has no fix>" --evidence "<what the page asks for>"
php $UP/check-performance.php --import .spryker-upgrade/state/perf-<group>.json
```

The import file is one JSON object keyed by fix ID (`{"status", "title", "detected", "size",
"touches", "gain", "files", "reason", "evidence"}`) or by page slug (`{"status": "no-fix", "reason",
"evidence"}`). `--set` replaces a fix's status, files, reason and evidence, and keeps its title,
detected, size, touches, gain and decision when they are not given. `--set`, `--no-fix` and
`--import` reject an entry that misses what its status requires, a fix of a page not in the copy,
`no-fix` on a page with fixes, a decision, or the status `deferred` or `skipped`, and write nothing
then (exit 2). `--verify` exits 1 while a page is `pending` or lacks its reason, or a fix is
`pending`, incomplete, unsized, or an `M` or `L` fix without the developer's decision, and lists
them.

## The report section

Phase 7's **Performance fixes** section starts with the docs copy (`docs.commit`) and the
`--verify` counts. Then one table per page, in the order of `performance-fixes.json`, headed by the
page title and URL: one row per fix — ID, title, size, status, files (applied) or reason (otherwise;
for `deferred` and `skipped` marked as the developer's choice), and the evidence. A `no-fix` or
`not-checked` page is one line with its reason. Below the tables:

- **Performance backlog** — the `deferred` fixes, one row each with ID, fix, size, files, expected
  gain and the developer's reason: the table `php $UP/check-performance.php --backlog` prints. With
  no deferred fix, one line saying so.
- the proposals of the `not-applied` fixes for the developer to decide, and the `operator:` items to
  pass on.

When the docs were unreachable, the section is one paragraph saying Phase 6.2 did not run and why.
The `--verify` output closes the section.
