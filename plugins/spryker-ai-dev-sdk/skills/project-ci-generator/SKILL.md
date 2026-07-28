---
name: project-ci-generator
description: >-
  Transform a product/vendor-style CI setup into a single, lean project CI pipeline.
  Use this whenever the user wants to "clean up CI for the project", "make a project CI",
  "recreate one CI file", "remove product-only CI jobs", "clear the CI folder and rebuild",
  or port CI to another host (GitLab, Bitbucket). It first reads and investigates whatever CI
  actually exists in the repo, runs a short questionnaire to learn what the project needs
  (which test suites, PHP version, notifications, target platform), then proposes a keep/drop
  plan to the user before wiping the old CI and emitting one project-tuned pipeline plus only
  the support files the surviving jobs reference.
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
they encode the maintainers' own guidance on what a project keeps vs drops, so use them as the
starting classification. Still confirm each one against the questionnaire answers rather than
applying it blindly — a marker is a strong hint, not a final decision.

### 2. Ask

Run one short questionnaire. The answers, combined with the discovery, determine the output:

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

For a different host, reproduce the same jobs, commands, and ordering in that host's structure
— only the wrapper changes.

### 5. Validate

Parse the emitted file, confirm every dependency points at a job that still exists, and
sanity-check ordering. Report the final tree of what remains and the job list of the new
pipeline.

## Quality gate

Do not consider the work done until every item below holds. Walk the list explicitly and
report it — this is what proves the CI folder was actually cleaned, not just added to.

- [ ] **Single pipeline.** Exactly one project CI file exists; no leftover secondary
      workflows from the old setup remain.
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
