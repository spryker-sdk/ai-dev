# Handover report — what the development team receives

Phase 7 writes two reports. The developer report ([verification.md](verification.md) § Phase 7) is
for the person who ran the upgrade. The **handover report** is for the development team that
reviews, releases and owns the result, and for its stakeholders.
[EXAMPLE-HANDOVER-REPORT.md](../EXAMPLE-HANDOVER-REPORT.md) shows the shape.

## Contents

- [Format](#format)
- [Writing rules](#writing-rules)
- [The findings log](#the-findings-log)
- [Sections](#sections)

## Format

Ask the person once, before you write: Markdown or HTML. The content is the same in both.

- **Markdown** — `.spryker-upgrade/state/handover-report.md`. Use pipe tables and fenced code blocks.
- **HTML for a paste into Confluence** — `.spryker-upgrade/state/handover-report.html`. The person
  opens it in a browser, selects all, copies and pastes into the page. Follow the paste rules in
  `<skill-dir>/../demo-run-sheet/references/output-format.md`: headings, tables with inline borders,
  absolute links, inline styles only. A code block does not survive a paste, so put each command on
  its own line in a `<p>` with `style="font-family:monospace"`. Open the file in the browser when it
  is written.

Title: `<Project>: Spryker upgrade <from> → <to>`. Then a table of contents of the parts and
subsections that are present.

## Writing rules

Write both reports in ASD-STE100 Simplified Technical English, at about 80%: follow the core rules,
but break one when the strict form becomes unclear or awkward.

Apply:

- Short sentences: procedures max 20 words, descriptions max 25 words.
- One instruction per sentence. Start the instruction with the verb in the imperative ("Run the
  test.", "Remove the constant.").
- Active voice. Say who or what does the action.
- One word, one meaning. Use the same term for the same thing every time. No synonyms for variety.
- Simple verb forms: present, simple past, future. No "-ing" verb forms as nouns where a simple verb
  works.
- Plain common words: "use", not "utilize"; "start", not "initiate"; "help", not "facilitate".
- No idioms, slang, metaphors or filler ("basically", "just", "actually", "it's worth noting").
- Use articles ("the", "a") — do not drop them for telegraphic style.
- Use lists for steps and for more than two items.

Relax (the remaining 20%):

- Technical names, code identifiers, Spryker terms (facade, plugin, transfer, P&S) and English
  developer terms (refactor, deploy, merge, mock) are allowed even when they are not in the STE
  dictionary.
- Use a passive or a longer sentence when the strict form loses precision.
- Do not rewrite quoted text, code, error messages or command output.

For the handover report, also:

- **American English** ("behavior", "color").
- **Write only what the run shows.** Do not say who uses a feature, how often, or what people need —
  no "daily work", no "employees need no training". Name workflows: ordering, checkout, approvals.
  Do not claim a benefit, for example "the shop is faster", without a measurement in the report.
- **Call the reader "the development team".** Do not write "your team" or "<Project> team".
- **Call the findings "issues".** The development team decides which issues matter to them.
- **Use "must" for a required action.** Do not write "can" or "may" for an action that is required.
- **Say why** for every item that was not done.
- **Leave out what the team cannot act on:** internal process details, and settings or commands that
  are already correct or already part of the normal deployment.
- **Part 1 is for people who do not read code.** No class, module or file names, and no versions
  except the two releases.
- **Parts 2 to 6 are technical.** Name each class, file or command together with its effect.
- **No vocabulary of this skill:** no Phase, lane, gate, matrix row, detector or state-file names.
- Call the two pull requests **the tests pull request** and **the upgrade pull request** every time.
- **Numbers are counts from this run.** Every number in Part 1 appears again, with its detail, in a
  later part.
- **Do not call a check verified when it did not run.** Name a check that did not run, and what it
  would have caught.
- **Leave out a section that has nothing to say.** Number the parts and subsections as rendered, and
  point each cross-reference ("Part 4") at the rendered number.

## The findings log

`.spryker-upgrade/state/findings.md` collects every issue that **already exists on the starting
release** and that the work finds: while it writes tests at gate #1, in the tests already red at
Phase 0, in the Back Office crawl baseline, in the verifier's observations, and in code that any lane
reads. Damage that the upgrade caused is fixed; it is never a finding. Custom code that nothing
reaches — for example a controller for a removed route, or a form that only a removed step renders —
goes into the log as unused code.

One row per finding:

```
| <area> | <finding> | <where> | <status> |
```

- `<area>` — the business area in which the development team plans the fix: money, prices and tax;
  orders; carts; checkout; security and access; Merchant Portal; Back Office; imports; storefront
  texts. `unused code` for code that nothing reaches.
- `<finding>` — what happens, with one concrete example value or request.
- `<where>` — the screen, the endpoint or `file:line`.
- `<status>` — one of `Current behavior covered by a test`, `Current behavior partly covered by a
  test`, `Current behavior not covered by a test`, `Fixed in the tests pull request`, `Fixed in the
  upgrade pull request`. For unused code: why nothing reaches it.

A test keeps the current behavior, correct or not. The log tells the development team which current
behavior is wrong.

## Sections

### 1. Executive summary

- **Goal** — one sentence: the target release, and why it matters (supported, secure, new features
  available).
- **What was done** — one bullet each: the tests (only with a tests pull request: which workflows
  they check), the upgrade, each feature added on the way, the performance work.
- **Changes in behavior** — one bullet per change from §2.4, in terms of what a buyer, a merchant or
  an employee sees.
- **Security fixes** — the count of security issues that the upgrade fixes, and the count that stays
  open, with why. Leave out when no security release note came after the starting release.
- **Existing issues** — only with findings: the count, two examples, and that the upgrade does not
  change them.
- **What happens next** — one sentence each: the development team reviews both pull requests, then
  releases them, staging first. Part 3 gives the steps.

### 2. Technical details

**2.1 Pull requests** — the branch the work starts from, then a table: pull request, branch, content.
Two by default: the tests pull request first, then the upgrade pull request, which starts from it.
Without a tests pull request: one row, and one sentence that says why — the existing tests covered the
customized areas, or new tests were declined. In the second case, name the areas that only a code
reading checked.

**2.2 Tests pull request details** — only with a tests pull request: the tests per suite type
(counts), which tests existed before and how many still passed, and each code fix that the tests
needed, with its effect.

**2.3 Upgrade pull request details** — a table: area, change. One row per area that the upgrade
changed: Spryker modules (the release, and how many moved to a new major), modules held back and why,
PHP and frontend runtime, developer tools, servers and deploy files, Back Office, storefront,
Merchant Portal, features added on the way, deprecated plugins replaced, performance changes.

**2.4 Behavior changes from replaced deprecated plugins** — a table: replacement, effect. From gate
#3: the applied replacements that change what a person sees or can do. Leave out the replacements
with no visible effect.

**2.5 Database migrations** — the folder of the migrations, then a table: migration, change,
data-loss risk (from the Phase 5 migration record). Then say that a schema change blocks writes to
its table while it runs, and whether the production table sizes were measured.

**2.6 Security fixes** — from `.spryker-upgrade/state/security-fixes.md`. First the security release
notes that were read. Then a table: issue, affected modules, extra steps, status. The status is
`Fixed in the upgrade pull request`, `Fixed in the upgrade pull request; release step in §3.4`, `Not
fixed` with the reason, or `Not affected` with the reason. A module update alone does not always fix
an issue, so name each extra step and where it was done. When the project overrides a file that the
fix changed, the status is `Not fixed: the project overrides <file>`, followed by the recommended fix
for that file; §3.5 lists it again. When the notes could not be read, say that the security fixes
are not checked.

**2.7 Known existing issues** — only with findings: the count, that they exist on the starting
release, which ones were fixed and in which pull request, and a pointer to Part 4.

**2.8 Open performance gaps** — only when the performance check ran: a pointer to Part 5.

### 3. Action items for the development team

- **3.1 Review** — the development team must review the tests pull request first, then the upgrade
  pull request: all code and all test cases, before the merge. A test that does not match the
  intended shop behavior must be changed or removed before the merge.
- **3.2 Known points in the pull requests** — only the changes that affect the review or the release,
  for example configuration that changed outside the upgrade scope, or an extension that is no longer
  loaded.
- **3.3 Local environment** — the new Docker image and runtime versions, the migrations to apply, and
  the commands that run the new tests (the pinned invocations), with each constraint on how to run
  them.
- **3.4 Release steps** — from the release-steps log, `verified` entries only, written as
  [release-steps.md](release-steps.md) § 3 says: staging first, then production; per
  step what to run, why and where; the environment variables; the tests and the regression check of
  the critical workflows after the deploy. When Phase 6.8 did not run, say that the release steps
  are not verified, and list the candidates.
- **3.5 Not done in this upgrade** — each item with the reason it was not done: deprecations deferred
  or kept, features left for later, security fixes that a project override blocks (with the
  recommended fix), and topics that a reader can expect but that were out of scope.

### 4. Existing issues observed

Only with findings. One table per area from the findings log: #, finding, where, status. Number the
rows through the whole part. Put unused code last, in its own table: #, code, why nothing reaches it.

### 5. Performance guideline check

When Phase 6.2 did not run, write one line that says so and why. Else, start with one line: the
guidelines that were checked, the date they were read, and that the check read code and measured
nothing. Then:

- **5.1 Applied** — a table: area, change, guideline. The `applied` fixes in plain words.
- **Not applied, by where it lands** — one subsection each for storefront pages, back end and
  background jobs, and configuration. From the `deferred` and `skipped` fixes, grouped by `touches`.
  A table: where (`file:line`), problem, fix, guideline. A footnote on the first subsection: these
  fixes change custom code or need work outside the upgrade.
- **Not applied: recommended, but changes the shop behavior** — the `not-applied` fixes whose reason
  starts with `proposal:`. A table: recommendation, performance gain, side effect, guideline.

### 6. New Spryker features

The features that Spryker released after the starting release, up to the target, that the project
can adopt. From the Phase 6 list. Start with the releases they come from, and explain Spryker's
"early access" label. Three groups, each a table: feature, what it does, requires, third-party
costs.

- **Install and integrate** — needs little or no change to project code. Say when the upgrade
  installed its modules already.
- **Install, integrate and set up an external service** — also needs a third-party account, for
  example an identity provider or an AI provider.
- **Integrate and change existing code** — replaces or changes project code, or the architecture.
  Name the part of the project that it replaces.
