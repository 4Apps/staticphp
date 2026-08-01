#!/usr/bin/env bash
#
# Print the version the current commit would be released as.
#
# The version identity lives in two places only:
#   .version   - major.minor, hand edited, the one thing a human decides
#   git        - the patch number, derived from the commit count
#
# The patch is the commit count rather than a hand maintained number, so it never needs a
# commit of its own and cannot conflict on a merge. It keeps counting across a minor bump,
# so 2.1 follows 2.0 without the patch resetting and every version sorts after the last.
#
# Prints a bare "2.0.324". scripts/release_tag.bash adds the "v" for the tag name.
#
# This is the release side only. scripts/build_info.bash derives the same number for
# .build_info.json, which is what the running application reports about itself - written on
# every build rather than only on the commits that get tagged. Both read .version, so the
# two agree without either depending on the other.

set -Eeuo pipefail

THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_PATH="$(cd "${THIS_DIR}/.." && pwd)"

VERSION_FILE="${BASE_PATH}/.version"

if [ ! -f "$VERSION_FILE" ]; then
    echo "error: ${VERSION_FILE} not found" >&2
    exit 1
fi

MAJOR_MINOR="$(tr -d '[:space:]' < "$VERSION_FILE")"
if [ -z "$MAJOR_MINOR" ]; then
    echo "error: ${VERSION_FILE} is empty" >&2
    exit 1
fi

# Composer and Packagist both parse the tag as semver, so a typo here is a tag that either
# will not publish or publishes as something unexpected
if [[ ! "$MAJOR_MINOR" =~ ^[0-9]+\.[0-9]+$ ]]; then
    echo "error: ${VERSION_FILE} must be major.minor, e.g. 2.0 - found \"${MAJOR_MINOR}\"" >&2
    exit 1
fi

# A shallow clone has no history to count, and would silently produce version .1
if [ "$(git -C "$BASE_PATH" rev-parse --is-shallow-repository 2>/dev/null || echo false)" = "true" ]; then
    echo "error: shallow clone - fetch full history (actions/checkout needs fetch-depth: 0)" >&2
    exit 1
fi

COMMIT_COUNT="$(git -C "$BASE_PATH" rev-list --count HEAD)"

printf '%s.%s\n' "$MAJOR_MINOR" "$COMMIT_COUNT"
