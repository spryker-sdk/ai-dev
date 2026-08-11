---
name: project-ci-generator
description: >-
  Use when turning a repo's inherited product/vendor CI into a single, lean project CI
  pipeline — "clean up CI for the project", "make a project CI", "recreate one CI file",
  "remove product-only CI jobs", "clear the CI folder and rebuild", or porting CI to
  another host (GitLab, Bitbucket). A pre-boot wizard step of project start, and standalone
  on any repo carrying product-style CI.
---

# Project CI Generator

Product/vendor repositories ship CI that must protect every customer, every database, every
language version, and the upstream release process. A **project** inherits all that machinery
but needs almost none of it — it targets one environment and only needs to gate its own
commits. This skill turns the heavy inherited CI into a single, honest project pipeline.

The guiding principle: **a project CI should contain only jobs whose failure a project
developer must act on.** Anything that exists to protect the product's release, its
cross-version or cross-database compatibility, or its full QA matrix belongs to product
delivery, not here.

## Investigate, never assume

CI contents change over time — jobs get renamed, added, removed; support files come and go.
Do not rely on any remembered job list, filename, or classification. Every run starts by
reading the CI that is actually present, derives the plan from what you find, and proposes it
to the user for approval before changing anything.

## Workflow

### 1. Discover

The base to start from is the repo's main CI workflow — for a Spryker project this is
`.github/workflows/ci.yml`. Read it first, but do not assume its contents: confirm the file is
there and investigate what it actually contains (it changes over time), and also scan for
other CI files that may exist alongside it.

Build an accurate picture of: the jobs and how they depend on each other, the support files
each job references (anything a job boots, calls, or uses), and cross-cutting config
(triggers, concurrency, secrets).

The base CI often already carries inline recommendations — comment markers on jobs and steps
such as "project applicable", "remove for project", or "optional for project". Read these:
they encode the upstream project's own guidance on what a project keeps vs drops, so use them as the
starting classification. Still confirm each one against the questionnaire answers rather than
applying it blindly — a marker is a strong hint, not a final decision.

On an un-annotated CI (no markers), classify by these marker-free heuristics instead of vibes:
- **drop-shaped:** matrix `strategy` over PHP/database versions; release-branch or tag triggers;
  upmerge/sync automation; API/doc publishing for the product; full product QA / E2E suites that
  need the product's own infrastructure; anything gated on upstream-repo secrets.
- **keep-shaped:** fast static gates (lint, CS, PHPStan), unit/functional suites that run on a
  plain checkout, security/credential scans.
- **genuinely ambiguous** (an acceptance suite the project might adopt): put it in the plan as a
  question, not a silent verdict.

### 2. Ask

**Under the project-starter wizard, skip this step:** all five answers were collected in the interview's CI section and live in the state file's `ci:` block (platform, keep_suites, matrix, notifications, wipe_unreferenced) — read them and go straight to Propose. Standalone, run one short questionnaire. The answers, combined with the discovery, determine the output:

- **Target platform** — keep the current host or port to another (decides file path/syntax).
- **Which suites to keep** — offer only the suites you actually found; recommend keeping
  lightweight gating suites and dropping heavy product QA suites.
- **Single version vs. matrix** — a project runs one version; recommend dropping the matrix.
- **Notifications** — keep or drop chat/ticket steps and their secrets (default: drop).
- **Clear scope** — wipe and regenerate only what's needed, or leave unreferenced files.

Carry forward anything the user already answered earlier instead of re-asking.

### 3. Propose

Derive the plan from the discovery plus the answers — do not recite a canned one. For each
discovered job decide keep vs drop; for each support file decide keep (referenced by a
surviving job) vs drop. Present the keep/drop lists and the output path, and get an explicit
go-ahead. Deleting CI is outward-facing and hard to reverse, so confirm before acting. If the
user hesitates at the wipe, offer to annotate the existing CI in place instead.

### 4. Rebuild

Prune the files the plan drops. Write the single project CI from the surviving jobs, reusing
their real commands verbatim — those are already correct for this repo's tooling, which is the
whole reason to transform rather than template. Apply the agreed trims (single version,
product-only steps removed, dependencies wired only among surviving jobs, notifications on or
off). Keep exactly the support files surviving jobs reference. Never invent a job or step: if
the questionnaire selected something the source CI doesn't contain, say so.

**A dropped suite's job usually pulls support files out with it — this skill owns removing them, not a later step.** When a removed job references a `docker/sdk boot <deploy>.yml` or an install recipe, the `.github/deploy/*.yml` and `config/install/*.yml` files (and any data-import fixture dirs they alone reference) are now orphaned — remove them too. A deploy file names its recipe in its `pipeline:` key (e.g. `pipeline: docker.ci.acceptance` → `config/install/docker.ci.acceptance.yml`), so that key is how you map a dropped job to the install recipe it used. **Recipes are many-to-one:** several deploy files routinely share one `pipeline:` value, so before deleting a recipe, `grep -l "^pipeline: <name>$" .github/deploy/*.yml` and keep it if ANY surviving deploy file still names it. Three traps: (1) a KEPT job and a DROPPED job can have near-identical filenames (`…cypress-boilerplate.yml` kept vs `…cypress.yml` dropped) — delete by which surviving job references it, not by the name; (2) a fixture/import config named for a suite (`*_ROBOT.yml`) may ALSO be referenced by the regular demodata pipeline — grep its literal filename repo-wide and read the actual `source:`/`command:` lines before deleting, or an unrelated import breaks; (3) a recipe whose name merely *resembles* a dropped suite may be the one a KEPT deploy file still points at — always decide by reference, never by name. When in doubt, leave the recipe: an orphaned `config/install/` file is harmless, a deleted-but-referenced one breaks the boot. Under the project-starter wizard this makes ci-generator the **single owner** of removing an old test-suite's CI jobs + its deploy/install/fixture configs (the robot/acceptance-fixture lane decision comes from the interview `keep_suites`); the suite's Composer-package removal and the new suite's vendoring stay with `cypress-migration`.

For a different host, reproduce the same jobs, commands, and ordering in that host's structure
— only the wrapper changes.

### 5. Validate

Confirm every dependency points at a job that still exists, and sanity-check ordering. Report
the final tree of what remains and the job list of the new pipeline.

**Parsing the emitted YAML — mind when you can.** Pre-boot there is often no YAML parser on the
host (no `vendor/`; foreign interpreters like python/ruby aren't allowlisted), so pre-boot
validation is **structural via grep** — job keys present, `needs:` targets resolve — and GitHub
itself validates syntax on push. A true parse becomes available **post-boot** via the clone's own
vendor (`vendor/symfony/yaml` is present). Pass the filename as an argument — the snippet is meant to
be run once per file, so it takes `$argv[1]` rather than hardcoding a name, and it prints an explicit
success line so a silent exit is never mistaken for a pass:

```bash
php -r 'require "vendor/autoload.php"; Symfony\Component\Yaml\Yaml::parseFile($argv[1]); echo "OK ", $argv[1], PHP_EOL;' .github/workflows/ci.yml
```

Cheap — run it on every generated/edited YAML (workflow, deploy files, import manifests,
configuration ymls) before the next boot attempt. A parse error names the file, line and column; an
error mentioning an undefined variable or a `null` argument means the command was pasted without its
filename argument, **not** that the YAML is invalid.

**Two Spryker-project handoffs this step owns:**
- **Store/region tokens vs. the kept deploy files.** This skill runs FIRST (before `define-stores`
  and `brand-project`) and retargets the workflow's `APPLICATION_STORE`/`SPRYKER_CURRENT_REGION` from
  the state file — fine, the values are known. But the KEPT `.github/deploy/*.yml` files it does not
  own still carry `region:` and `*.<region>.spryker.local` hostnames for the old region+domain. **Write
  the kept-deploy-file list into `.ai-dev/project-setup.md` under a `## Required follow-ups` section**
  (a durable artifact that survives a resume — conversation-only would be lost) so `define-stores`
  (region token) and `brand-project` (base domain) read it there and include `.github/deploy/` in their
  literal sweeps — otherwise the workflow gets retargeted and the deploy files silently don't.
- **The `PROJECT` env token.** A kept job may carry an upstream `PROJECT: <value>` env whose consumer
  isn't greppable pre-boot. List it as a **post-boot verification item** (`grep -rn "PROJECT"
  vendor/spryker/` answers it in seconds once `vendor/` exists), rather than leaving it silently
  unexamined.

## Quality gate

Do not consider the work done until every item below holds. Walk the list explicitly and
report it — this is what proves the CI folder was actually cleaned, not just added to.

- [ ] **Single GATING pipeline.** Exactly one project CI file gates commits/PRs; no leftover
      secondary workflows from the old setup remain. Additional non-gating workflows are
      legitimate ONLY when user-approved and listed in the report (a scheduled credential
      scan, a separate deploy workflow) — they don't belong squeezed into the PR gate, and
      they don't survive by default either.
- [ ] **All unneeded files removed.** Every CI file the approved plan marked as dropped is
      gone from the CI folder — product-only pipelines, unreferenced support/deploy files, and
      composite actions or scripts no surviving job uses. Re-list the CI folder and confirm
      nothing dropped is still present.
- [ ] **No orphans kept.** Every file that remains is either the project CI itself or a
      support file that a surviving job references. Nothing survives "just in case."
- [ ] **No dangling references.** No surviving job points at a file, action, or script that
      was deleted; and no deleted job is still named as a dependency.
- [ ] **Only approved jobs.** The pipeline contains exactly the jobs the user approved — none
      dropped, none invented.
- [ ] **No product-only jobs survive.** Nothing that exists purely to protect the upstream
      product remains — cross-version/cross-database compatibility matrices, full product QA
      and end-to-end suites, release-branch or upmerge automation, and API/doc publishing for
      the product. A project must not carry these; confirm each such job is gone.
- [ ] **Trims applied.** Version matrix reduced as agreed, product-only steps removed,
      notifications on/off per the answer, dependencies wired only among surviving jobs.
- [ ] **Commands preserved.** Surviving jobs use the original discovered commands verbatim,
      not paraphrased.
- [ ] **Parses cleanly.** The output file parses and its dependency/ordering graph resolves.

If any item fails, fix it before reporting completion.
