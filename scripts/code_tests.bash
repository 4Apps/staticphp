#!/usr/bin/env bash
#
# Everything that gates a merge, in one place.
#
# Run it locally, from the pre-commit hook, and from CI, so the three cannot drift.
# Every stage is fatal - the point is to fail the build, not to print advice.
#
#   ./scripts/code_tests.bash          # everything
#   ./scripts/code_tests.bash php      # php only (lint, phpcs, phpunit)
#   ./scripts/code_tests.bash js       # js only (typecheck, eslint, prettier)

set -Eeuo pipefail

THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_PATH="$(cd "${THIS_DIR}/.." && pwd)"
cd "${APP_DIR:-$BASE_PATH}"

WHAT="${1:-all}"

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
NC='\033[0m'

failed=0

step() {
    printf "${CYAN}==> %s${NC}\n" "$1"
}

ok() {
    printf "${GREEN}    ok${NC}\n"
}

fail() {
    printf "${RED}    FAILED: %s${NC}\n" "$1"
    failed=1
}

run_php() {
    step "php -l (syntax)"
    # -print0/-0 so paths with spaces survive
    # scripts/ too: the integration scripts are not covered by any suite, so a rename in
    # src/ that breaks one of them would otherwise go unnoticed until somebody ran it.
    # The cli entry point has no .php extension, hence the second check.
    if find lib src scripts tests -name '*.php' -type f -print0 | xargs -0 -n1 -P4 php -l > /dev/null \
        && php -l staticphp > /dev/null; then
        ok
    else
        fail "php syntax errors"
    fi

    step "phpcs (code style)"
    if ./vendor/bin/phpcs --standard=phpcs.xml lib src scripts tests; then ok; else fail "phpcs"; fi

    # No baseline file here on purpose: the skeleton is small enough to keep clean, and an
    # application generated from it inherits phpstan.neon as its own starting point.
    step "phpstan (static analysis)"
    if ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G; then ok; else fail "phpstan"; fi

    # The tooling - the scaffolder and the upgrader - before any application, because it
    # runs on a checkout that has none and is exactly what somebody would be leaning on at
    # that point.
    step "phpunit (tooling)"
    if ./vendor/bin/phpunit -c phpunit.xml; then ok; else fail "tooling tests"; fi

    # The framework has its own suite in the staticphp-core repository. This one covers the
    # skeleton: that the front controller, the config and the demo module still work
    # against whatever version of the package is installed.
    # One suite per application. Applications are generated from presets/ rather than
    # tracked, so a fresh checkout legitimately has none - say so instead of passing an
    # empty run off as green.
    local suites=(src/*/phpunit.xml)
    if [ ! -f "${suites[0]}" ]; then
        step "phpunit"
        fail "no application under src/ - run: composer setup"
        return
    fi

    local suite
    for suite in "${suites[@]}"; do
        step "phpunit ($(basename "$(dirname "$suite")"))"
        if ./vendor/bin/phpunit -c "$suite"; then ok; else fail "$(dirname "$suite") tests"; fi
    done
}

run_js() {
    if [ ! -d node_modules ]; then
        step "npm ci"
        if npm ci --no-audit --no-fund; then ok; else fail "npm ci"; fi
    fi

    local assets=(src/*/Public/assets/src)
    if [ ! -d "${assets[0]}" ]; then
        step "js"
        fail "no application under src/ - run: composer setup"
        return
    fi

    # Per application, because base/* has to resolve against the importing application's
    # own copy - see the note in tsconfig.base.json
    step "tsc (types)"
    if npm run --silent typecheck; then ok; else fail "typecheck"; fi

    step "eslint"
    if npx eslint src/*/Public/assets/src; then ok; else fail "eslint"; fi

    step "prettier (format check)"
    if npx prettier --check "src/*/Public/assets/src/**/*.{ts,tsx,scss}"; then
        ok
    else
        fail "formatting - run: npm run format"
    fi
}

case "$WHAT" in
    php) run_php ;;
    js) run_js ;;
    all)
        run_php
        run_js
        ;;
    *)
        echo "usage: $0 [all|php|js]" >&2
        exit 2
        ;;
esac

if [ "$failed" -ne 0 ]; then
    printf "\n${RED}Code tests failed.${NC}\n"
    exit 1
fi

printf "\n${GREEN}All code tests passed.${NC}\n"
