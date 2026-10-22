# Handover report — what the project team receives

Phase 7 writes two reports. The developer report ([verification.md](verification.md) § Phase 7) is
for whoever ran the upgrade. The **handover report** is for the project team that reviews, releases
and owns the result, and for its stakeholders. [EXAMPLE-HANDOVER-REPORT.md](../EXAMPLE-HANDOVER-REPORT.md)
shows the shape.

## Contents

- [Format](#format)
- [Wording](#wording)
- [The findings log](#the-findings-log)
- [Sections](#sections)

## Format

One file, `.spryker-upgrade/state/handover-report.html`, written to be pasted into Confluence: the
person opens it in a browser, selects all, copies, and pastes into the page. Follow the paste rules in
`<skill-dir>/../demo-run-sheet/references/output-format.md` — headings, tables with inline borders,
absolute links, inline styles only. Code blocks do not survive a paste, so a command goes on its own
line in a `<p>` with `style="font-family:monospace"`. Open the file in the browser when it is written
and say in one line what to do with it.

Title: `<Project>: Spryker upgrade <from> → <to>`, then a table of contents of the parts and
subsections actually present.

## Wording

- **Part 1 is for people who do not read code.** It names what buyers, merchants and employees see or
  can do — no class, module or file names, no versions beyond the two releases.
- **Parts 2 to 6 are technical and self-contained:** every class, file or command is named together
  with its effect, so a reader who was not involved can act on it.
- **No vocabulary of this skill.** No Phase, lane, gate, matrix row, detector or state-file names.
- The two pull requests are called **the tests pull request** and **the upgrade pull request**
  throughout.
- **Numbers are counts from this run.** Every number in Part 1 appears again, with its detail, in a
  later part.
- **Nothing is called verified that did not run** — the rule behind the developer report's "What is
  proven and what is not". A check that could not run is named as not done, with what it would have
  caught.
- **A section with nothing to say is left out**, not filled with "none". Number the parts and
  subsections as rendered, and keep every cross-reference ("Part 4") pointing at the rendered number.

## The findings log

`.spryker-upgrade/state/findings.md` collects every defect the work notices that **already exists on
the starting release**: while writing tests at gate #1, in the tests already red at Phase 0, in the
Back Office crawl baseline, in the verifier's observations, in reading code for any lane. Something
the upgrade caused is damage and is fixed, never a finding. Custom code that nothing reaches — a
removed route still served by a controller, a form only a removed step renders — is logged too, as
unused code.

One row per finding:

```
| <area> | <finding> | <where> | <status> |
```

- `<area>` — the business area a project team would plan the fix in: money, prices and tax; orders;
  carts; checkout; security and access; Merchant Portal; Back Office; imports; storefront texts; and
  so on. `unused code` for code nothing reaches.
- `<finding>` — what happens, with one concrete example value or request ("12,50 € exported as 12").
- `<where>` — the screen, endpoint or `file:line`.
- `<status>` — one of `current behaviour covered by a test`, `current behaviour partly covered by a
  test`, `current behaviour not covered by a test`, `fixed in the tests pull request`, `fixed in the
  upgrade pull request`. For unused code: why nothing reaches it.

A test pins current behaviour, wrong or not; the log is how wrong behaviour reaches the project team.

## Sections

### 1. Executive summary

- **Goal** — one sentence: the release moved to, and why that matters (supported, secure, new
  features available).
- **What was done** — one bullet each, in business terms: the tests (only when there is a tests pull
  request: which daily work they now check), the upgrade itself, any feature added on the way, the
  performance work.
- **Changes in behaviour** — one bullet per behaviour change from §2.4, as a buyer, merchant or
  employee would notice it.
- **Existing issues** — only with findings: the count, two examples, and that the upgrade does not
  change them.
- **What happens next** — the project team reviews, tests on staging, deploys; Part 3 has the steps.

### 2. Technical details

**2.1 Pull requests** — table: pull request, branch, content. Two by default: the tests pull request
first, the upgrade pull request built on it. Without a tests pull request, one row and one sentence
saying why: the existing tests already covered the customised areas, or new tests were declined —
then name the areas checked only by reading code.

**2.2 Tests pull request details** — only with a tests pull request: tests per suite type (counts),
which tests existed before and how many still passed on the project, and every code fix the tests
needed, each with its effect.

**2.3 Upgrade pull request details** — table: area, change. One row per area the upgrade touched:
Spryker modules (release, how many to a new major), modules held back and why, PHP and frontend
runtime, developer tools, servers and deploy files, Back Office, storefront, Merchant Portal, features
added on the way, deprecated plugins replaced, performance changes.

**2.4 Behaviour changes from replaced deprecated plugins** — table: replacement, effect. From gate #3:
the applied replacements whose consequence changes what someone sees or can do. Leave out the
replacements with no visible effect.

**2.5 Database migrations** — the folder they are committed in; table: migration, change, data-loss
risk (from the Phase 5 migration record). Then the rollback risks (what the down migrations drop or
narrow), that a schema change blocks writes to its table while it runs, whether anyone measured the
production table sizes, and the advice to back up the database before the production deploy.

**2.6 Known existing defects** — only with findings: the count, when they were found (on the starting
release), which were fixed and in which pull request, and a pointer to Part 4.

**2.7 Open performance gaps** — only when the performance check ran: a pointer to Part 5.

### 3. Action items for the project team

- **3.1 Known points in the pull requests** — changes a reviewer would not expect from an upgrade:
  configuration that changed outside the obvious scope, extensions no longer loaded, application
  plugins added, the order the pull requests merge in.
- **3.2 Local environment** — the new Docker image and runtime versions, the migrations to apply, and
  the commands that run the new tests (the pinned invocations), with any constraint on running them.
- **3.3 Merging into the release branch** — only when the project has a release branch ahead of the
  branch the upgrade started from. Before writing, run `git merge-tree` of each pull request against
  it: how many commits it is ahead, each conflicting file per pull request, and how to resolve it
  (lock files re-resolved in the container, both sides kept where both changed the same class).
- **3.4 Release steps** — the same on staging and production, staging first: environment variables or
  secrets the new configuration needs (names and where they are set), the deploy, one-time commands
  scoped to their resource (`publish:trigger-events -r <resource>`, never unscoped on a large data
  set), then checking the shop: the tests against the environment, and a hand check per surface.
- **3.5 Not done in this upgrade** — deprecations deferred or kept, features left for later, anything
  out of scope that a reader might assume was done.

### 4. Existing issues observed

Only with findings. One table per area from the findings log: #, finding, where, status, numbered
through the whole part. Unused code last, in its own table: #, code, why nothing reaches it.

### 5. Performance guideline check

When Phase 6.2 did not run, one line saying so and why. Otherwise an opening line: the guidelines
checked, the date they were read, and that the check read code and timed nothing. Then:

- **5.1 Applied** — table: area, change, guideline — the `applied` fixes in plain words.
- **Not applied, by where it lands** — one subsection each for storefront pages, back end and
  background jobs, and configuration, from the `deferred` and `skipped` fixes grouped by `touches`;
  table: where (`file:line`), problem, fix, guideline. A footnote on the first: they were not applied
  because they change custom code or need effort outside the upgrade.
- **Not applied: recommended, but changes how the shop behaves** — the `not-applied` fixes whose
  reason is `proposal:`; table: recommendation, performance gain, side effect, guideline.

### 6. New Spryker features

The features Spryker released after the starting release up to the target that the project could
adopt, from the Phase 6 list. Open with which releases they come from, and explain Spryker's "early
access" marking. Three groups, each a table: feature, what
it does, requires, third-party costs.

- **Install and integrate** — added with little or no change to project code. Say when the upgrade
  already installed its modules.
- **Install, integrate and set up an external service** — additionally needs a third-party account,
  such as an identity or AI provider.
- **Integrate and change existing code** — replaces or changes project code, or the architecture. Name
  what of the project's it would replace.
