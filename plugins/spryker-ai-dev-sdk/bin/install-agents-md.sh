#!/usr/bin/env bash
#
# Install AGENTS.example.md as CLAUDE.md at the project root.
#
# Source resolution order:
#   1. Remote — fetched from spryker-sdk/ai-dev on GitHub (master branch).
#   2. Local — the package's locally vendored copy at
#      vendor/spryker-sdk/ai-dev/data/agents/AGENTS.example.md, used when the
#      network fetch fails (offline, GitHub outage, 4xx/5xx).
#
# Usage (run from the project root):
#   "${CLAUDE_PLUGIN_ROOT}/bin/install-agents-md.sh"             # safe: refuses to overwrite an existing CLAUDE.md
#   "${CLAUDE_PLUGIN_ROOT}/bin/install-agents-md.sh" --overwrite # replaces an existing CLAUDE.md
#
# Outside an active Claude Code plugin context (where CLAUDE_PLUGIN_ROOT is
# unset), invoke the script directly from its on-disk location, e.g.:
#   vendor/spryker-sdk/ai-dev/plugins/spryker-ai-dev/bin/install-agents-md.sh
#
# Exit codes:
#   0  installed
#   1  CLAUDE.md exists and --overwrite was not passed
#   2  neither remote nor local source available (broken installation + no network)
#   3  failed to write target (permissions, full disk, etc.)

set -euo pipefail

REMOTE_URL="https://raw.githubusercontent.com/spryker-sdk/ai-dev/master/data/agents/AGENTS.example.md"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PACKAGE_DIR="$(cd "${PLUGIN_DIR}/../.." && pwd)"
LOCAL_SOURCE="${PACKAGE_DIR}/data/agents/AGENTS.example.md"
TARGET_FILE="${PWD}/CLAUDE.md"

overwrite=0
for arg in "$@"; do
    case "${arg}" in
        --overwrite) overwrite=1 ;;
        -h|--help)
            sed -n '2,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "install-agents-md: unknown argument: ${arg}" >&2
            echo "install-agents-md: see --help" >&2
            exit 64
            ;;
    esac
done

if [[ -e "${TARGET_FILE}" && "${overwrite}" -ne 1 ]]; then
    echo "install-agents-md: ${TARGET_FILE} already exists" >&2
    echo "install-agents-md: rerun with --overwrite to replace it" >&2
    exit 1
fi

tmp_file="$(mktemp)"
trap 'rm -f "${tmp_file}"' EXIT

source_used="remote"
if ! curl -fsSL --max-time 15 -o "${tmp_file}" "${REMOTE_URL}" 2>/dev/null; then
    echo "install-agents-md: remote fetch failed (${REMOTE_URL}); falling back to locally vendored copy" >&2
    if [[ ! -f "${LOCAL_SOURCE}" ]]; then
        echo "install-agents-md: local fallback source missing: ${LOCAL_SOURCE}" >&2
        echo "install-agents-md: cannot install — network unavailable and package's data/ directory is missing" >&2
        exit 2
    fi
    cp "${LOCAL_SOURCE}" "${tmp_file}"
    source_used="local"
fi

if [[ ! -s "${tmp_file}" ]]; then
    echo "install-agents-md: source file is empty (source: ${source_used})" >&2
    exit 2
fi

if ! mv "${tmp_file}" "${TARGET_FILE}"; then
    echo "install-agents-md: failed to write ${TARGET_FILE}" >&2
    exit 3
fi

# mv moved tmp_file; clear the trap target so the EXIT handler doesn't error.
trap - EXIT

if [[ "${overwrite}" -eq 1 ]]; then
    echo "install-agents-md: overwrote ${TARGET_FILE} (source: ${source_used})"
else
    echo "install-agents-md: installed ${TARGET_FILE} (source: ${source_used})"
fi
