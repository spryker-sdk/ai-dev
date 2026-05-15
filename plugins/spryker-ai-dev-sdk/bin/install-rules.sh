#!/usr/bin/env bash
#
# Install bundled Spryker rules into .claude/rules/ at the project root.
#
# Source resolution order:
#   1. Remote — fetched from spryker-sdk/ai-dev on GitHub (master branch).
#      Uses the GitHub Contents API to list available rule files, then
#      downloads each over raw.githubusercontent.com.
#   2. Local — the package's locally vendored copy at
#      vendor/spryker-sdk/ai-dev/data/rules/, used when the remote listing or
#      any download fails (offline, GitHub outage, 4xx/5xx, API rate limit).
#
# Usage (run from the project root):
#   "${CLAUDE_PLUGIN_ROOT}/bin/install-rules.sh"             # merge: copy only rules whose target file does not yet exist
#   "${CLAUDE_PLUGIN_ROOT}/bin/install-rules.sh" --overwrite # overwrite: replace every same-named file in .claude/rules/
#
# Outside an active Claude Code plugin context (where CLAUDE_PLUGIN_ROOT is
# unset), invoke the script directly from its on-disk location, e.g.:
#   vendor/spryker-sdk/ai-dev/plugins/spryker-ai-dev/bin/install-rules.sh
#
# Exit codes:
#   0  installed (see summary line for counts and source used)
#   2  neither remote nor local source available (broken installation + no network)
#   3  failed to write target (permissions, full disk, etc.)

set -euo pipefail

REMOTE_LIST_URL="https://api.github.com/repos/spryker-sdk/ai-dev/contents/data/rules?ref=master"
REMOTE_RAW_BASE="https://raw.githubusercontent.com/spryker-sdk/ai-dev/master/data/rules"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PACKAGE_DIR="$(cd "${PLUGIN_DIR}/../.." && pwd)"
LOCAL_SOURCE_DIR="${PACKAGE_DIR}/data/rules"
TARGET_DIR="${PWD}/.claude/rules"

overwrite=0
for arg in "$@"; do
    case "${arg}" in
        --overwrite) overwrite=1 ;;
        -h|--help)
            sed -n '2,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "install-rules: unknown argument: ${arg}" >&2
            echo "install-rules: see --help" >&2
            exit 64
            ;;
    esac
done

list_remote_md_filenames() {
    local listing
    if ! listing="$(curl -fsSL --max-time 15 -H 'Accept: application/vnd.github+json' "${REMOTE_LIST_URL}" 2>/dev/null)"; then
        return 1
    fi
    if command -v jq >/dev/null 2>&1; then
        echo "${listing}" | jq -r '.[] | select(.type == "file") | .name' | grep '\.md$' || true
    else
        # Fallback: extract "name" fields ending in .md. Brittle but adequate for the
        # well-structured Contents API output, which lists "name": "<file>" per object.
        echo "${listing}" | grep -oE '"name"[[:space:]]*:[[:space:]]*"[^"]+\.md"' | sed -E 's/.*"([^"]+)"$/\1/'
    fi
}

stage_dir="$(mktemp -d)"
trap 'rm -rf "${stage_dir}"' EXIT

source_used="remote"

remote_files="$(list_remote_md_filenames || true)"

if [[ -n "${remote_files}" ]]; then
    while IFS= read -r filename; do
        [[ -z "${filename}" ]] && continue
        if ! curl -fsSL --max-time 15 -o "${stage_dir}/${filename}" "${REMOTE_RAW_BASE}/${filename}" 2>/dev/null; then
            echo "install-rules: remote download failed for ${filename}; falling back to locally vendored copy" >&2
            source_used="fallback"
            break
        fi
    done <<< "${remote_files}"
fi

if [[ -z "${remote_files}" || "${source_used}" == "fallback" ]]; then
    [[ -z "${remote_files}" ]] && echo "install-rules: remote listing unavailable; falling back to locally vendored copy" >&2
    rm -rf "${stage_dir}"
    stage_dir="$(mktemp -d)"
    trap 'rm -rf "${stage_dir}"' EXIT

    if [[ ! -d "${LOCAL_SOURCE_DIR}" ]]; then
        echo "install-rules: local fallback source missing: ${LOCAL_SOURCE_DIR}" >&2
        echo "install-rules: cannot install — network unavailable and package's data/ directory is missing" >&2
        exit 2
    fi

    shopt -s nullglob
    local_files=("${LOCAL_SOURCE_DIR}"/*.md)
    shopt -u nullglob

    if [[ ${#local_files[@]} -eq 0 ]]; then
        echo "install-rules: local fallback directory contains no .md files: ${LOCAL_SOURCE_DIR}" >&2
        exit 2
    fi

    for source_file in "${local_files[@]}"; do
        cp "${source_file}" "${stage_dir}/$(basename "${source_file}")"
    done
    source_used="local"
fi

shopt -s nullglob
staged_files=("${stage_dir}"/*.md)
shopt -u nullglob

if [[ ${#staged_files[@]} -eq 0 ]]; then
    echo "install-rules: no rule files were staged (source: ${source_used})" >&2
    exit 2
fi

if ! mkdir -p "${TARGET_DIR}"; then
    echo "install-rules: failed to create ${TARGET_DIR}" >&2
    exit 3
fi

added=0
overwritten=0
kept=0

for staged_file in "${staged_files[@]}"; do
    base="$(basename "${staged_file}")"
    target_file="${TARGET_DIR}/${base}"
    if [[ -e "${target_file}" ]]; then
        if [[ "${overwrite}" -eq 1 ]]; then
            if ! cp "${staged_file}" "${target_file}"; then
                echo "install-rules: failed to overwrite ${target_file}" >&2
                exit 3
            fi
            overwritten=$((overwritten + 1))
        else
            kept=$((kept + 1))
        fi
    else
        if ! cp "${staged_file}" "${target_file}"; then
            echo "install-rules: failed to write ${target_file}" >&2
            exit 3
        fi
        added=$((added + 1))
    fi
done

if [[ "${overwrite}" -eq 1 ]]; then
    echo "install-rules: ${added} added, ${overwritten} overwritten (source: ${source_used}, target: ${TARGET_DIR})"
else
    echo "install-rules: ${added} added, ${kept} kept (source: ${source_used}, rerun with --overwrite to replace kept files)"
fi
