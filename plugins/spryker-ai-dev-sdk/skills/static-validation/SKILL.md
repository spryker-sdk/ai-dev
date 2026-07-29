---
name: static-validation
description: >
  Run Spryker static analysis over only the code that changed versus a base branch — PHP
  (phpcbf, phpcs, phpmd/architecture-sniffer, phpstan) AND frontend (eslint, stylelint, prettier
  for js/ts/scss/css) — after implementing or editing code, during project development, before
  commit, or as an interim check while iterating. Trigger on "validate", "lint", "check code",
  "run static analysis", "fix phpcs/phpstan", "run QA on changes", "static check the diff",
  "validate my changes", "run static analysis on what I changed", "lint the changed modules",
  "check js/css/scss I changed", "check code vs master/main", "static-check the branch". Works
  from any git worktree, auto-detects or takes an explicit base branch (master, main,
  or any ref), validates only added/changed files (not the all-files globs in
  package.json), and can group PHP either by individually changed files (files scope) or by every
  full Spryker MODULE that has a changed file (module scope — detects changed modules, not just
  files, and validates the whole module).
---

# static-validation

Static analysis of **only what changed** against a base branch. Wraps the same tools the
project's Spryker QA / `package.json` use — **PHP**: `phpcbf`, `phpcs`, `phpmd`
(architecture-sniffer), `phpstan`; **frontend**: `eslint`, `stylelint`, `prettier` — all
executed inside the container via `docker/sdk cli`.

It replaces fixed, single-base validation scripts with a single flexible engine:

- **Any base branch** — `--base <ref>`, or auto-detected (`master` → `main` →
  remote default). Handles the diff via merge-base (`base...HEAD`) plus uncommitted working-tree
  edits and brand-new untracked files, so in-progress work is fully covered.
- **Worktree-aware** — resolves the current worktree's root with `git rev-parse --show-toplevel`
  for file/diff detection, and locates `docker/sdk` in the **main** working tree via
  `git rev-parse --git-common-dir` (a linked worktree doesn't contain the SDK-provided,
  untracked `docker/sdk`). Tools therefore run correctly from any worktree or subdirectory.
  In a linked worktree the container mounts the main checkout, so the run prints a notice: files
  changed only in the worktree must be committed/synced into the main tree to be analysed.
- **Module-level scope (PHP)** — `--scope module` detects the changed Spryker **modules**
  (`src/{Org}/{Layer}/{Module}`) and validates each whole module directory, not only the touched
  files. Files outside a module shape (e.g. `config/…`) stay as individual paths.
  Generated code (`src/Generated`, `src/Orm`) is always skipped.
- **Frontend, changed-only** — changed files are partitioned by extension and each linter runs on
  just those files (not the `**/*` globs in `package.json`): `.js/.ts` → eslint + prettier,
  `.scss/.css/.less` → stylelint + prettier, `.json/.html` → prettier. Configs are auto-loaded
  (`eslint.config.mjs`, `.stylelintrc.js`, `.prettierrc.json`; `.prettierignore` is honoured).
  `--scope` does not apply to frontend files (they are always validated individually).

## The command

The engine is `scripts/static-check-diff.sh` inside this skill directory. Run it from the skill
directory, or reference it by its full path from the skill's install location.

```bash
# From the skill directory:
bash scripts/static-check-diff.sh [options]
```

| Option | Meaning |
|---|---|
| `-b, --base <ref>` | Base branch/ref to diff against. Omit to auto-detect. Also reads `$STATIC_CHECK_BASE`. |
| `-s, --scope <mode>` | `files` (default) or `module`. PHP grouping only — frontend files are always individual. |
| `--tools <list>` | Comma subset of `phpcbf,phpcs,phpmd,phpstan,eslint,stylelint,prettier` (default: all). |
| `--fix` | Autofix where supported: `phpcbf` (always fixes), and `eslint --fix` / `stylelint --fix` / `prettier --write`. Also via `STATIC_CHECK_FIX=1`. |
| `--include-tests` | Also run phpcs/phpcbf on `/tests/` files (phpmd/phpstan always skip tests + config). |
| `--dry-run` | Print the resolved base, scope and per-tool paths; run no tools. |
| `-h, --help` | Usage. |

Exit code: `0` = clean (or dry-run / nothing changed), `1` = violations found, `2` = usage/env error.

### Environment overrides

Tool config and the base branch can be overridden via env vars (project defaults apply otherwise):

| Env var | Default | Overrides |
|---|---|---|
| `STATIC_CHECK_BASE` | auto-detect | Base branch/ref (same as `--base`). |
| `STATIC_CHECK_PHPCS_STANDARD` | `phpcs.xml` | phpcs/phpcbf ruleset. |
| `STATIC_CHECK_PHPMD_RULESET` | `phpmd.xml` | phpmd ruleset (project-level; falls back to `vendor/spryker/architecture-sniffer/src/Project/ruleset.xml` when absent). |
| `STATIC_CHECK_PHPMD_PRIORITY` | `4` | phpmd `--minimumpriority` (project-level baseline). |
| `STATIC_CHECK_PHPSTAN_CONFIG` | `phpstan.neon` | phpstan config file. |
| `STATIC_CHECK_PHPSTAN_LEVEL` | `6` | phpstan level (matches `phpstan.neon`). |
| `STATIC_CHECK_FIX` | `0` | `1` = autofix mode (same as `--fix`). |

```bash
STATIC_CHECK_PHPSTAN_LEVEL=6 bash scripts/static-check-diff.sh --tools phpstan
```

## How to use it (agent workflow)

1. **Preview first** with `--dry-run` to confirm the base branch and the exact targets:
   ```bash
   bash scripts/static-check-diff.sh --scope module --dry-run
   ```
   Report the detected base branch and the module/file list back to the user.

2. **Run the check.** All tools run by default and validate PHP + frontend changes together.
   - **Check-only (default for FE)**: `phpcbf` still auto-fixes PHP, but eslint/stylelint/prettier
     only *report*. Good as a read-only-ish diff check.
   - **Autofix everything**: add `--fix` — eslint/stylelint fix, prettier writes.
   - **Fully read-only** (touch nothing, e.g. reviewing someone else's diff): exclude the fixers,
     e.g. `--tools phpcs,phpmd,phpstan,eslint,stylelint,prettier` and do **not** pass `--fix`
     (note phpcbf mutates, so drop it: `--tools phpcs,phpmd,phpstan,eslint,stylelint,prettier`).
   ```bash
   # Validate every changed PHP module + all changed FE files:
   bash scripts/static-check-diff.sh --scope module

   # Autofix the diff (PHP + FE):
   bash scripts/static-check-diff.sh --fix
   ```

3. **Interpret results.** Report `phpcs`/`phpstan`/`phpmd`/`eslint`/`stylelint`/`prettier` findings
   using the project's absolute clickable-path format. If any autofixer ran, tell the user which
   files were modified (they appear in `git status`).

4. **Base branch choice.** If the user names a base ("vs main", "against develop"), pass it with
   `--base`. Otherwise let auto-detect run and state which base it picked.

## Notes & caveats

- Runs against a **running** Spryker environment (`docker/sdk cli`). If containers are down,
  start them first (see the `spryker-docker-sdk` skill). Frontend tools run via `npx` in-container
  (host node may be too old / lack `node_modules`).
- Autofixers **modify files**: `phpcbf` always (whenever included); `eslint`/`stylelint`/`prettier`
  only with `--fix`. For a non-mutating check, drop `phpcbf` from `--tools` and omit `--fix`.
- Uses the project configs verbatim — `phpcs.xml`, the project architecture ruleset `phpmd.xml`
  (priority 4), `phpstan.neon` (level 6), plus `eslint.config.mjs`, `.stylelintrc.js`,
  `.prettierrc.json`/`.prettierignore` — so results match what CI enforces.
- **phpmd runs the project ruleset, not the core one.** Spryker ships two: `phpmd.xml` (project
  development, priority 4) and `vendor/spryker/architecture-sniffer/src/ruleset.xml`
  (core/framework development, priority 2). Since this skill validates project code, it defaults to
  the project ruleset. To check against the core framework rules instead, override both:
  `STATIC_CHECK_PHPMD_RULESET=vendor/spryker/architecture-sniffer/src/ruleset.xml STATIC_CHECK_PHPMD_PRIORITY=2`.
- Frontend changed-file detection covers committed, uncommitted, and brand-new untracked files.
  `--scope module` groups **PHP only**; FE files are always validated individually.
- The script is `bash 3.2` compatible (macOS default) — no `mapfile`/associative arrays.
