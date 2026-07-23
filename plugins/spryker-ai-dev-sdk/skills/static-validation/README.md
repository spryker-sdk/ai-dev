# static-check-diff

Spryker static analysis over **only the code that changed** versus a base branch — PHP **and**
frontend.

Runs the project's standard tools inside the container via `docker/sdk cli`, scoped to your diff:

- **PHP** — `phpcbf`, `phpcs`, `phpmd` (architecture-sniffer), `phpstan` (level 6).
- **Frontend** — `eslint` (js/ts), `stylelint` (scss/css/less), `prettier` (all of the above +
  json/html) — the same linters as `package.json`, but on **changed files only** instead of the
  all-files globs (`eslint … './src/Pyz/Yves/**/*.{js,ts}'`, `prettier --check '**/*.…'`).

It is the flexible successor to the fixed `.claude/bash-local/validation.sh` (`master-demo`) and
`validation-master.sh` (`master`) scripts.

## Why

Validating the whole codebase on every change is slow and noisy, and the `package.json` FE scripts
lint **everything**. This skill validates just what you touched — and, for PHP, optionally the
whole Spryker module you touched — against whatever base branch your work forked from.

## Features

- **Flexible base branch.** `--base <ref>` for any branch (`master`, `main`, `master-demo`,
  `develop`, a release branch, …). Auto-detects when omitted (`master-demo` → `master` → `main` →
  the remote's default branch). Also honours the `STATIC_CHECK_BASE` env var.
- **Worktree-aware.** Resolves the current worktree root with `git rev-parse --show-toplevel` for
  file/diff detection, and finds `docker/sdk` in the **main** working tree via
  `git rev-parse --git-common-dir` — a linked worktree doesn't contain the untracked,
  SDK-provided `docker/sdk`. Works from the main checkout, any linked worktree, or a subdirectory.
  From a linked worktree the container mounts the **main** checkout, so the run prints a notice and
  only analyses files present there — commit/sync worktree-only changes into the main tree first.
- **PHP file **or** module scope.**
  - `--scope files` (default): validate each changed `.php` file.
  - `--scope module`: detect the changed Spryker **modules** (`src/{Org}/{Layer}/{Module}`) and
    validate each whole module directory — catches breakage in files you didn't edit but that live
    in the same module.
- **Frontend, changed-only.** Changed files are partitioned by extension and each linter runs on
  just those files: `.js/.ts` → eslint + prettier, `.scss/.css/.less` → stylelint + prettier,
  `.json/.html` → prettier. Configs auto-load (`eslint.config.mjs`, `.stylelintrc.js`,
  `.prettierrc.json`; `.prettierignore` honoured). `--scope` does not apply to FE files.
- **Covers committed, uncommitted *and* brand-new files.** Diffs `base...HEAD` (merge-base — only
  what your branch adds, not what base moved forward with), plus working-tree edits, plus untracked
  (non-ignored) files.
- **Skips generated code** (`src/Generated`, `src/Orm`) and, for `phpmd`/`phpstan`, test and
  config files.
- **Tool subset + autofix.** `--tools` to pick a subset; `--fix` to autofix (phpcbf always fixes;
  eslint/stylelint `--fix`, prettier `--write`).

## Usage

```bash
# Preview what would be checked (no tools run):
bash ${CLAUDE_PLUGIN_ROOT}/skills/static-validation/scripts/static-check-diff.sh --dry-run

# Validate changed files (PHP + FE) against auto-detected base:
bash ${CLAUDE_PLUGIN_ROOT}/skills/static-validation/scripts/static-check-diff.sh

# Validate every changed PHP MODULE (+ all changed FE files) against master:
bash ${CLAUDE_PLUGIN_ROOT}/skills/static-validation/scripts/static-check-diff.sh --base master --scope module

# Only the frontend linters, autofix:
bash ${CLAUDE_PLUGIN_ROOT}/skills/static-validation/scripts/static-check-diff.sh --tools eslint,stylelint,prettier --fix

# Fully read-only check (no fixers), against main:
bash ${CLAUDE_PLUGIN_ROOT}/skills/static-validation/scripts/static-check-diff.sh --base main --tools phpcs,phpmd,phpstan,eslint,stylelint,prettier
```

### Options

| Option | Default | Description |
|---|---|---|
| `-b, --base <ref>` | auto-detect | Base branch/ref to diff against. |
| `-s, --scope <mode>` | `files` | `files` or `module` — PHP grouping only (FE always individual). |
| `--tools <list>` | all | Subset of `phpcbf,phpcs,phpmd,phpstan,eslint,stylelint,prettier`. |
| `--fix` | off | Autofix: phpcbf (always), eslint/stylelint `--fix`, prettier `--write`. |
| `--include-tests` | off | Include `/tests/` files in phpcs/phpcbf. |
| `--dry-run` | off | Print plan + per-tool paths only, run nothing. |
| `-h, --help` | — | Show usage. |

### Environment overrides

Defaults live in the script; each is overridable via an env var:

| Env var | Default | Overrides |
|---|---|---|
| `STATIC_CHECK_BASE` | auto-detect | Base branch/ref (same as `--base`). |
| `STATIC_CHECK_PHPCS_STANDARD` | `phpcs.xml` | phpcs/phpcbf ruleset. |
| `STATIC_CHECK_PHPMD_RULESET` | `vendor/spryker/architecture-sniffer/src/ruleset.xml` | phpmd ruleset. |
| `STATIC_CHECK_PHPSTAN_CONFIG` | `phpstan.neon` | phpstan config file. |
| `STATIC_CHECK_PHPSTAN_LEVEL` | `6` | phpstan level (matches `phpstan.neon`). |
| `STATIC_CHECK_FIX` | `0` | `1` = autofix mode (same as `--fix`). |

```bash
# Run phpstan at level 8 against a custom config:
STATIC_CHECK_PHPSTAN_LEVEL=8 STATIC_CHECK_PHPSTAN_CONFIG=phpstan-strict.neon \
  bash ${CLAUDE_PLUGIN_ROOT}/skills/static-validation/scripts/static-check-diff.sh --tools phpstan
```

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Clean, or dry-run, or nothing changed. |
| `1` | Violations reported by at least one tool. |
| `2` | Usage / environment error (bad option, unresolvable base, not a git repo). |

## Requirements

- A **running** Spryker environment (`docker/sdk cli` must work). Start it with the
  `spryker-docker-sdk` skill if the containers are down. Frontend tools run via `npx` in-container.
- Project configs present at repo root: PHP — `phpcs.xml`, `phpstan.neon`, architecture-sniffer
  ruleset under `vendor/spryker/architecture-sniffer/`. Frontend — `eslint.config.mjs`,
  `.stylelintrc.js`, `.prettierrc.json`, and installed `node_modules` (via `docker/sdk cli npm install`).

## Caveats

- **Autofixers mutate files:** `phpcbf` always (whenever included); `eslint`/`stylelint`/`prettier`
  only with `--fix`. For a non-mutating review, drop `phpcbf` from `--tools` and omit `--fix`.
- On Docker-for-Mac the file sync back to the host is slightly async — after an autofix run, the
  host copy updates a moment later. Re-run in check mode to confirm.
- Levels and rulesets mirror the project QA / package.json baseline, so results align with CI.

## Layout

```
static-validation/
├── SKILL.md                        # trigger + agent workflow
├── README.md                       # this file
└── scripts/
    └── static-check-diff.sh        # the engine (bash 3.2 compatible)
```
