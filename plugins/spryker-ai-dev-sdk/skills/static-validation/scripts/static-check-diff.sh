#!/usr/bin/env bash
#
# static-check-diff.sh — Spryker static analysis of code changed vs a base branch.
#
# Runs PHP static tools (phpcbf, phpcs, phpmd, phpstan) AND frontend linters
# (eslint, stylelint, prettier) over only the PHP/JS/TS/CSS/SCSS that differs
# between the current branch/worktree and a base branch — added or changed only.
#
# Highlights:
#   * Flexible base branch  — master, main, or any ref (auto-detected).
#   * Worktree aware        — resolves the real repo root, works from any worktree.
#   * Module-level scope     — with --scope=module it detects changed Spryker MODULES
#                              (src/{Org}/{Layer}/{Module}) and validates each whole module,
#                              not only the individually-touched files.
#   * Frontend, changed-only — eslint/stylelint/prettier run on just the changed FE files,
#                              not the all-files globs in package.json.
#
# Usage:
#   static-check-diff.sh [options]
#
# Options:
#   -b, --base <ref>       Base branch/ref to diff against (default: auto-detect).
#   -s, --scope <mode>     files | module   (default: files) — PHP module grouping only.
#                            files  — validate only the changed PHP files.
#                            module — validate every module that has a changed file.
#       --include-tests    Include /tests/ files in phpcs/phpcbf (default: excluded from phpmd/phpstan only).
#       --tools <list>     Comma subset of: phpcbf,phpcs,phpmd,phpstan,eslint,stylelint,prettier (default: all).
#       --fix              Autofix where supported (phpcbf always; eslint/stylelint/prettier --fix/--write).
#       --dry-run          Print what would be validated, run nothing.
#   -h, --help             Show this help.
#
# Exit codes:
#   0  all selected tools passed (or dry-run / nothing to validate)
#   1  at least one tool reported violations
#   2  usage / environment error
#
set -uo pipefail

# ----------------------------------------------------------------------------
# Defaults
# ----------------------------------------------------------------------------
BASE_REF=""
SCOPE="files"
INCLUDE_TESTS=0
DRY_RUN=0
FIX="${STATIC_CHECK_FIX:-0}"
# PHP: phpcbf,phpcs,phpmd,phpstan   Frontend: eslint,stylelint,prettier
TOOLS="phpcbf,phpcs,phpmd,phpstan,eslint,stylelint,prettier"

# Tool config — overridable via environment, with project defaults below.
#   STATIC_CHECK_PHPCS_STANDARD   phpcs/phpcbf ruleset       (default: phpcs.xml)
#   STATIC_CHECK_PHPMD_RULESET    phpmd ruleset               (default: architecture-sniffer)
#   STATIC_CHECK_PHPSTAN_CONFIG   phpstan config file         (default: phpstan.neon)
#   STATIC_CHECK_PHPSTAN_LEVEL    phpstan level               (default: 6, matches phpstan.neon)
#   STATIC_CHECK_FIX              1 = autofix (phpcbf always fixes; eslint/stylelint/prettier
#                                 switch to --fix/--write)   (default: 0 = check only for FE)
PHPCS_STANDARD="${STATIC_CHECK_PHPCS_STANDARD:-phpcs.xml}"
PHPMD_RULESET="${STATIC_CHECK_PHPMD_RULESET:-vendor/spryker/architecture-sniffer/src/ruleset.xml}"
PHPSTAN_CONFIG="${STATIC_CHECK_PHPSTAN_CONFIG:-phpstan.neon}"
PHPSTAN_LEVEL="${STATIC_CHECK_PHPSTAN_LEVEL:-6}"

# ----------------------------------------------------------------------------
# Helpers
# ----------------------------------------------------------------------------
log()  { printf '%s\n' "$*"; }
info() { printf '\033[0;36m%s\033[0m\n' "$*"; }
warn() { printf '\033[0;33m%s\033[0m\n' "$*" >&2; }
err()  { printf '\033[0;31m%s\033[0m\n' "$*" >&2; }

usage() {
    # Print the leading comment banner (lines starting with '#') up to the first
    # blank/non-comment line after the options block.
    sed -n '3,35p' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

# ----------------------------------------------------------------------------
# Parse arguments
# ----------------------------------------------------------------------------
while [ $# -gt 0 ]; do
    case "$1" in
        -b|--base)          BASE_REF="${2:-}"; shift 2 ;;
        --base=*)           BASE_REF="${1#*=}"; shift ;;
        -s|--scope)         SCOPE="${2:-}"; shift 2 ;;
        --scope=*)          SCOPE="${1#*=}"; shift ;;
        --include-tests)    INCLUDE_TESTS=1; shift ;;
        --tools)            TOOLS="${2:-}"; shift 2 ;;
        --tools=*)          TOOLS="${1#*=}"; shift ;;
        --fix)              FIX=1; shift ;;
        --dry-run)          DRY_RUN=1; shift ;;
        -h|--help)          usage 0 ;;
        *)                  err "Unknown option: $1"; usage 2 ;;
    esac
done

case "$SCOPE" in
    files|module) ;;
    *) err "Invalid --scope '$SCOPE' (expected: files | module)"; exit 2 ;;
esac

# ----------------------------------------------------------------------------
# Resolve repo root (worktree-aware) and cd into it.
# `git rev-parse --show-toplevel` returns the CURRENT worktree's root — correct
# for diffs and file paths, which are relative to where the user is working.
# ----------------------------------------------------------------------------
if ! REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)"; then
    err "Not inside a git repository."
    exit 2
fi
cd "$REPO_ROOT" || { err "Cannot cd to repo root: $REPO_ROOT"; exit 2; }

# ----------------------------------------------------------------------------
# Resolve the MAIN working tree — the one that holds `docker/sdk`.
# `docker/sdk` is SDK-provided and NOT tracked in git, so a linked worktree does
# not contain it. `git rev-parse --git-common-dir` points at the shared .git of
# the main checkout; its parent is that main working tree. We run docker/sdk from
# there while still validating the current worktree's file paths (paths are the
# same repo-relative strings, and the container mounts the main checkout).
# In the main working tree, this resolves back to REPO_ROOT.
# ----------------------------------------------------------------------------
_common_git_dir="$(git rev-parse --git-common-dir 2>/dev/null)"
case "$_common_git_dir" in
    /*) ;;                                   # already absolute
    *)  _common_git_dir="$REPO_ROOT/$_common_git_dir" ;;
esac
MAIN_ROOT="$(cd "$_common_git_dir/.." 2>/dev/null && pwd)"
[ -z "$MAIN_ROOT" ] && MAIN_ROOT="$REPO_ROOT"

# Locate the docker/sdk launcher in the main working tree.
DOCKER_SDK="$MAIN_ROOT/docker/sdk"
if [ ! -x "$DOCKER_SDK" ]; then
    # Fall back to the current root (covers non-standard layouts), then PATH.
    if [ -x "$REPO_ROOT/docker/sdk" ]; then
        DOCKER_SDK="$REPO_ROOT/docker/sdk"
    elif command -v docker/sdk >/dev/null 2>&1; then
        DOCKER_SDK="docker/sdk"
    else
        err "Cannot find an executable 'docker/sdk' launcher."
        err "Looked in main working tree: $MAIN_ROOT/docker/sdk"
        [ "$MAIN_ROOT" != "$REPO_ROOT" ] && err "and current worktree: $REPO_ROOT/docker/sdk"
        err "Start/rebuild the Spryker environment so docker/sdk is available (see spryker-docker-sdk skill)."
        exit 2
    fi
fi

# In a linked worktree the container mounts the MAIN checkout, not this worktree.
# Files changed only here that are not present in the main tree/container can't be
# analysed. Surface this so results are never silently based on the wrong copy.
if [ "$MAIN_ROOT" != "$REPO_ROOT" ]; then
    warn "Linked worktree detected."
    warn "  worktree (files/diff): $REPO_ROOT"
    warn "  docker/sdk + container mount: $MAIN_ROOT"
    warn "  Tools run against the main checkout's mount; commit/sync your worktree"
    warn "  changes into it if analysis of only-in-worktree files looks off."
fi
info "Repo root (worktree): $REPO_ROOT"

# ----------------------------------------------------------------------------
# Determine the base ref.
# Priority: --base flag > env STATIC_CHECK_BASE > auto-detect.
# Auto-detect order: master, main, origin/HEAD default branch.
# We only pick a candidate that actually resolves in this repo.
# ----------------------------------------------------------------------------
ref_exists() { git rev-parse --verify --quiet "$1^{commit}" >/dev/null 2>&1; }

if [ -z "$BASE_REF" ] && [ -n "${STATIC_CHECK_BASE:-}" ]; then
    BASE_REF="$STATIC_CHECK_BASE"
fi

if [ -z "$BASE_REF" ]; then
    for candidate in master main; do
        if ref_exists "$candidate"; then BASE_REF="$candidate"; break; fi
    done
fi

# Fall back to the remote's default branch (e.g. origin/HEAD -> origin/main).
if [ -z "$BASE_REF" ]; then
    default_remote_branch="$(git symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null)"
    if [ -n "$default_remote_branch" ] && ref_exists "$default_remote_branch"; then
        BASE_REF="$default_remote_branch"
    fi
fi

if [ -z "$BASE_REF" ]; then
    err "Could not auto-detect a base branch. Pass one with --base <ref>."
    exit 2
fi

if ! ref_exists "$BASE_REF"; then
    err "Base ref '$BASE_REF' does not resolve in this repository."
    err "Available local branches:"; git branch --format='  %(refname:short)' >&2
    exit 2
fi

# Guard: comparing a branch against itself yields no diff.
current_ref="$(git rev-parse --abbrev-ref HEAD 2>/dev/null)"
info "Comparing: ${current_ref:-<detached>}  vs  base '$BASE_REF'"

# ----------------------------------------------------------------------------
# Collect changed files (existing on disk, added/modified — not deleted).
# Uses the merge-base (three-dot) so we only see what THIS branch changed,
# not commits that landed on the base after we branched. Also picks up
# uncommitted working-tree edits and brand-new untracked (non-ignored) files.
# ----------------------------------------------------------------------------
# Collected as a newline-delimited string then split — keeps us bash-3.2 safe
# (no mapfile, no associative arrays: macOS ships bash 3.2).
_raw_changes="$(
    git diff --name-only --diff-filter=d "${BASE_REF}...HEAD" 2>/dev/null
    # Also include uncommitted working-tree changes so in-progress edits are covered.
    git diff --name-only --diff-filter=d HEAD 2>/dev/null
    # And brand-new untracked files (not git-ignored) — freshly created code during
    # development is invisible to `git diff`, but still needs validating.
    git ls-files --others --exclude-standard 2>/dev/null
)"

# Partition changed files by kind:
#   changed_php   — *.php                     (phpcbf/phpcs/phpmd/phpstan)
#   changed_jsts  — *.js *.ts                 (eslint + prettier)
#   changed_style — *.scss *.css *.less       (stylelint + prettier)
#   changed_fmt   — *.json *.html + js/ts/style (prettier)
changed_php=()
changed_jsts=()
changed_style=()
changed_fmt=()
_seen_files=""
while IFS= read -r f; do
    [ -z "$f" ] && continue
    [ -f "$f" ] || continue
    case "$_seen_files" in *"|$f|"*) continue ;; esac
    _seen_files="${_seen_files}|$f|"
    case "$f" in
        *.php)                      changed_php+=("$f") ;;
        *.js|*.ts)                  changed_jsts+=("$f"); changed_fmt+=("$f") ;;
        *.scss|*.css|*.less)        changed_style+=("$f"); changed_fmt+=("$f") ;;
        *.json|*.html)              changed_fmt+=("$f") ;;
    esac
done <<EOF
$_raw_changes
EOF

if [ "${#changed_php[@]}" -eq 0 ] && [ "${#changed_jsts[@]}" -eq 0 ] \
   && [ "${#changed_style[@]}" -eq 0 ] && [ "${#changed_fmt[@]}" -eq 0 ]; then
    warn "No changed PHP/JS/TS/CSS/SCSS files found between '$BASE_REF' and HEAD (incl. working tree)."
    exit 0
fi

# ----------------------------------------------------------------------------
# Module detection.
# A Spryker module path looks like: src/{Org}/{Layer}/{Module}/...
#   Org    e.g. Pyz, Demo, CustomNamespace
#   Layer  e.g. Zed, Yves, Glue, Client, Service, Shared
# For any changed file under such a path we take the module root and validate
# the whole module directory. Files outside that shape (config/, tests/ root,
# etc.) are kept as individual paths.
# Generated/Orm code is skipped — it is auto-generated, not hand-written.
# ----------------------------------------------------------------------------
module_root_of() {
    # echoes the module root dir if $1 matches src/Org/Layer/Module/..., else nothing
    local p="$1"
    if [[ "$p" =~ ^(src/[^/]+/(Zed|Yves|Glue|Client|Service|Shared)/[^/]+)/ ]]; then
        printf '%s' "${BASH_REMATCH[1]}"
    fi
}

scope_paths=()
_seen_scope=""
_add_scope() {
    # append $1 to scope_paths if not already present
    local v="$1"
    [ -z "$v" ] && return
    case "$_seen_scope" in *"|$v|"*) return ;; esac
    _seen_scope="${_seen_scope}|$v|"
    scope_paths+=("$v")
}

if [ "${#changed_php[@]}" -gt 0 ]; then
    for f in "${changed_php[@]}"; do
        # Skip generated code entirely — it is not hand-written.
        case "$f" in src/Generated/*|src/Orm/*) continue ;; esac
        if [ "$SCOPE" = "module" ]; then
            root="$(module_root_of "$f")"
            if [ -n "$root" ] && [ -d "$root" ]; then
                _add_scope "$root"
            else
                _add_scope "$f"
            fi
        else
            _add_scope "$f"
        fi
    done
fi

# ----------------------------------------------------------------------------
# Report the plan (PHP scope + frontend files).
# ----------------------------------------------------------------------------
info ""
if [ "${#scope_paths[@]}" -gt 0 ]; then
    info "PHP scope: $SCOPE — ${#scope_paths[@]} target(s):"
    for p in "${scope_paths[@]}"; do log "  - $p"; done
    if [ "$SCOPE" = "module" ]; then
        info "(module scope validates every file under each module directory above)"
    fi
else
    info "PHP: no changed PHP targets."
fi

_report_fe() {
    local label="$1"; shift
    if [ "$#" -gt 0 ]; then
        info "$label — $# file(s):"
        local x; for x in "$@"; do log "  - $x"; done
    fi
}
[ "${#changed_jsts[@]}"  -gt 0 ] && _report_fe "JS/TS (eslint)"       "${changed_jsts[@]}"
[ "${#changed_style[@]}" -gt 0 ] && _report_fe "CSS/SCSS (stylelint)" "${changed_style[@]}"
[ "${#changed_fmt[@]}"   -gt 0 ] && _report_fe "Formatting (prettier)" "${changed_fmt[@]}"
:  # keep exit status clean for `set -e`-free flow

# Path sets:
#   phpcs/phpcbf — all scope paths (optionally excluding tests)
#   phpmd/phpstan — exclude tests and config always
cs_paths=()
strict_paths=()   # for phpmd + phpstan
if [ "${#scope_paths[@]}" -gt 0 ]; then
    for p in "${scope_paths[@]}"; do
        if [ "$INCLUDE_TESTS" -eq 0 ] && [[ "$p" =~ /tests/ || "$p" =~ /Test/ ]]; then
            :  # excluded from cs when tests excluded
        else
            cs_paths+=("$p")
        fi
        # strict: never tests, never config
        if [[ "$p" =~ /tests/ || "$p" =~ /Test/ || "$p" =~ ^config/ ]]; then
            continue
        fi
        strict_paths+=("$p")
    done
    # If tests were excluded but that emptied cs_paths, fall back to scope_paths for cs.
    [ "${#cs_paths[@]}" -eq 0 ] && cs_paths=("${scope_paths[@]}")
fi

if [ "$DRY_RUN" -eq 1 ]; then
    info ""
    info "[dry-run] Tools: $TOOLS"
    info "[dry-run] phpcs/phpcbf paths:  ${cs_paths[*]:-<none>}"
    info "[dry-run] phpmd/phpstan paths: ${strict_paths[*]:-<none>}"
    info "[dry-run] eslint paths:        ${changed_jsts[*]:-<none>}"
    info "[dry-run] stylelint paths:     ${changed_style[*]:-<none>}"
    info "[dry-run] prettier paths:      ${changed_fmt[*]:-<none>}"
    exit 0
fi

# ----------------------------------------------------------------------------
# Runner. All analysis runs inside the container via the main working tree's
# `docker/sdk cli`. It is invoked from MAIN_ROOT (where docker/sdk lives and
# what the container mounts as /data); the repo-relative file paths we pass are
# the same strings in either tree.
# ----------------------------------------------------------------------------
has_tool() { case ",$TOOLS," in *",$1,"*) return 0 ;; *) return 1 ;; esac; }

run() {
    info ""
    info "\$ docker/sdk cli $*"
    ( cd "$MAIN_ROOT" && "$DOCKER_SDK" cli "$@" )
}

overall_rc=0

# --- PHP -------------------------------------------------------------------
if has_tool phpcbf && [ "${#cs_paths[@]}" -gt 0 ]; then
    run vendor/bin/phpcbf --standard="$PHPCS_STANDARD" -p -s --extensions=php "${cs_paths[@]}"
fi

if has_tool phpcs && [ "${#cs_paths[@]}" -gt 0 ]; then
    run vendor/bin/phpcs --standard="$PHPCS_STANDARD" -p -s --extensions=php "${cs_paths[@]}"
    [ $? -ne 0 ] && overall_rc=1
fi

if has_tool phpmd; then
    if [ "${#strict_paths[@]}" -gt 0 ]; then
        # phpmd takes a comma-separated list as a single arg.
        strict_csv="$(IFS=,; printf '%s' "${strict_paths[*]}")"
        run vendor/bin/phpmd "$strict_csv" text "$PHPMD_RULESET" --minimumpriority 2
        [ $? -ne 0 ] && overall_rc=1
    elif [ "${#changed_php[@]}" -gt 0 ]; then
        warn "phpmd: no non-test/non-config paths to analyse — skipped."
    fi
fi

if has_tool phpstan; then
    if [ "${#strict_paths[@]}" -gt 0 ]; then
        run vendor/bin/phpstan analyse "${strict_paths[@]}" -l "$PHPSTAN_LEVEL" -c "$PHPSTAN_CONFIG"
        [ $? -ne 0 ] && overall_rc=1
    elif [ "${#changed_php[@]}" -gt 0 ]; then
        warn "phpstan: no non-test/non-config paths to analyse — skipped."
    fi
fi

# --- Frontend (JS/TS/CSS/SCSS) --------------------------------------------
# Runs the same linters as package.json, but scoped to changed files only.
# eslint auto-loads eslint.config.mjs; stylelint auto-loads .stylelintrc.js;
# prettier auto-loads .prettierrc.json and honours .prettierignore.
# FIX=1 (via --fix or STATIC_CHECK_FIX=1) turns on autofix (eslint --fix,
# stylelint --fix, prettier --write); otherwise all three run in check mode.

if has_tool eslint && [ "${#changed_jsts[@]}" -gt 0 ]; then
    if [ "$FIX" -eq 1 ]; then
        run npx eslint --no-error-on-unmatched-pattern --fix "${changed_jsts[@]}"
    else
        run npx eslint --no-error-on-unmatched-pattern "${changed_jsts[@]}"
    fi
    [ $? -ne 0 ] && overall_rc=1
fi

if has_tool stylelint && [ "${#changed_style[@]}" -gt 0 ]; then
    if [ "$FIX" -eq 1 ]; then
        run npx stylelint --allow-empty-input --fix "${changed_style[@]}"
    else
        run npx stylelint --allow-empty-input "${changed_style[@]}"
    fi
    [ $? -ne 0 ] && overall_rc=1
fi

if has_tool prettier && [ "${#changed_fmt[@]}" -gt 0 ]; then
    if [ "$FIX" -eq 1 ]; then
        run npx prettier --ignore-unknown --write "${changed_fmt[@]}"
    else
        run npx prettier --ignore-unknown --check "${changed_fmt[@]}"
    fi
    [ $? -ne 0 ] && overall_rc=1
fi

info ""
if [ "$overall_rc" -eq 0 ]; then
    info "✓ Static analysis passed."
else
    err "✗ Static analysis reported violations (see output above)."
fi
exit "$overall_rc"
