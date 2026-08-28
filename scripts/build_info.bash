#!/usr/bin/env bash
#
# Generate .build_info.json from .version plus the current git state.
#
# Replaces bump2version. The version identity lives in two places only:
#   .version   - major.minor, hand edited, the one thing a human decides
#   git        - everything else, derived at build time
#
# The patch number is the commit count, so it never needs a commit of its own and
# cannot conflict on a merge. The commit hash and date are read here rather than in a
# pre-commit hook, so they describe the build instead of being one commit stale.
#
# Output is gitignored. When it is absent the application falls back to a ".dev" label,
# so a working copy needs no build step.
#
# BUILD_INFO_OUTPUT overrides where the file is written, which is what the docker build
# stage needs: the version has to come from this checkout's history, but the stamp belongs
# in the tree being packaged.

set -Eeuo pipefail

THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_PATH="$(cd "${THIS_DIR}/.." && pwd)"

VERSION_FILE="${BASE_PATH}/.version"
OUTPUT_FILE="${BUILD_INFO_OUTPUT:-${BASE_PATH}/.build_info.json}"

if [ ! -f "$VERSION_FILE" ]; then
    echo "error: ${VERSION_FILE} not found" >&2
    exit 1
fi

MAJOR_MINOR="$(tr -d '[:space:]' < "$VERSION_FILE")"
if [ -z "$MAJOR_MINOR" ]; then
    echo "error: ${VERSION_FILE} is empty" >&2
    exit 1
fi

# A shallow clone has no history to count, and would silently produce version .1
if [ "$(git -C "$BASE_PATH" rev-parse --is-shallow-repository 2>/dev/null || echo false)" = "true" ]; then
    echo "error: shallow clone - fetch full history (actions/checkout needs fetch-depth: 0)" >&2
    exit 1
fi

COMMIT_COUNT="$(git -C "$BASE_PATH" rev-list --count HEAD)"
COMMIT_HASH="$(git -C "$BASE_PATH" rev-parse HEAD)"
COMMIT_DATE="$(git -C "$BASE_PATH" log -1 --format=%cd --date=format:'%d.%m.%Y %H:%M')"
SHORT_SHA="$(printf '%s' "$COMMIT_HASH" | cut -c1-7)"

cat > "$OUTPUT_FILE" <<JSON
{
    "version": "v${MAJOR_MINOR}.${COMMIT_COUNT}",
    "git_commit_hash": "${COMMIT_HASH}",
    "git_commit_date": "${COMMIT_DATE}",
    "asset_version": "${SHORT_SHA}"
}
JSON

echo "Wrote ${OUTPUT_FILE}: v${MAJOR_MINOR}.${COMMIT_COUNT} (${SHORT_SHA})"
