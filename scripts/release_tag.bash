#!/usr/bin/env bash
#
# Create the release tag for the current commit: v<.version>.<commit count>.
#
# Packagist publishes tags, so the tag is the release. `composer create-project 4apps/staticphp`
# resolves to the newest stable tag, which means an untagged skeleton keeps handing out
# whatever was tagged last - tagging is how new work reaches anyone starting a project.
#
# This only creates the tag locally and prints the push command. Pushing is what makes it
# public, and that stays a deliberate act rather than a side effect of running a script.
#
# Usage: scripts/release_tag.bash [--yes]
#
#   --yes   Skip the confirmation prompt. For a release job that has already decided.

set -Eeuo pipefail

THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_PATH="$(cd "${THIS_DIR}/.." && pwd)"

ASSUME_YES=false
for argument in "$@"; do
    case "$argument" in
        --yes)
            ASSUME_YES=true
            ;;
        *)
            echo "error: unknown option ${argument}" >&2
            exit 2
            ;;
    esac
done

VERSION="$("${THIS_DIR}/version.bash")"
TAG="v${VERSION}"
BRANCH="$(git -C "$BASE_PATH" rev-parse --abbrev-ref HEAD)"

# A tag names a commit, so the tree it describes has to be the tree that was committed
if [ -n "$(git -C "$BASE_PATH" status --porcelain)" ]; then
    echo "error: working tree is not clean; commit or stash before tagging" >&2
    exit 1
fi

if git -C "$BASE_PATH" rev-parse -q --verify "refs/tags/${TAG}" >/dev/null; then
    echo "error: ${TAG} already exists." >&2
    echo "       The commit count has not moved since the last release, so there is" >&2
    echo "       nothing new to tag. Commit first." >&2
    exit 1
fi

# The patch number is the commit count, which only ever grows on a branch that is added
# to - but a rebase, a squash or a reset can shorten history and hand back a version below
# one already published. Packagist would keep serving the older, higher tag as latest.
LATEST_TAG="$(git -C "$BASE_PATH" tag -l 'v*' | sort -V | tail -1)"
if [ -n "$LATEST_TAG" ]; then
    HIGHEST="$(printf '%s\n%s\n' "$LATEST_TAG" "$TAG" | sort -V | tail -1)"
    if [ "$HIGHEST" != "$TAG" ]; then
        echo "error: ${TAG} sorts below the existing ${LATEST_TAG}." >&2
        echo "       History was probably rewritten, so the commit count went backwards." >&2
        echo "       Bump the minor in .version rather than publishing a version that" >&2
        echo "       composer will never resolve as the newest." >&2
        exit 1
    fi
fi

# master is what has been released; develop is where work happens
if [ "$BRANCH" != "master" ]; then
    echo "warning: on \"${BRANCH}\", not master. Releases are normally tagged on master."
fi

if grep -qi '^## .*unreleased' "${BASE_PATH}/CHANGELOG.md" 2>/dev/null; then
    echo "warning: CHANGELOG.md still says \"unreleased\"; stamp it with ${VERSION} and today's date."
fi

echo
echo "  tag:     ${TAG}"
echo "  branch:  ${BRANCH}"
echo "  commit:  $(git -C "$BASE_PATH" log -1 --format='%h %s')"
echo

if [ "$ASSUME_YES" = false ]; then
    read -r -p "Create this tag? [y/N] " answer
    if [ "$answer" != "y" ] && [ "$answer" != "Y" ]; then
        echo "Nothing was tagged."
        exit 1
    fi
fi

git -C "$BASE_PATH" tag -a "$TAG" -m "Release ${TAG}"

echo
echo "Created ${TAG}. It is local until you push it:"
echo
echo "    git push origin ${TAG}"
echo
echo "Packagist publishes on the push, so that is the point of no return."
