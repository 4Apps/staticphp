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
    if find src -name '*.php' -type f -print0 | xargs -0 -n1 -P4 php -l > /dev/null; then
        ok
    else
        fail "php syntax errors"
    fi

    step "phpcs (code style)"
    if ./vendor/bin/phpcs --standard=phpcs.xml src; then ok; else fail "phpcs"; fi

    step "phpunit (System)"
    if ./vendor/bin/phpunit -c src/System/phpunit.xml; then ok; else fail "System tests"; fi

    step "phpunit (Application)"
    if ./vendor/bin/phpunit -c src/Application/phpunit.xml; then ok; else fail "Application tests"; fi
}

run_js() {
    if [ ! -d node_modules ]; then
        step "npm ci"
        if npm ci --no-audit --no-fund; then ok; else fail "npm ci"; fi
    fi

    step "tsc (types)"
    if npx tsc --noEmit; then ok; else fail "typecheck"; fi

    step "eslint"
    if npx eslint src/Application/Public/assets/src; then ok; else fail "eslint"; fi

    step "prettier (format check)"
    if npx prettier --check "src/Application/Public/assets/src/**/*.{ts,scss}"; then
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
