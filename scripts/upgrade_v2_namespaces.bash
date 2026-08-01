#!/usr/bin/env bash
#
# Rewrite framework namespace references for the 1.x -> 2.0 upgrade.
#
#   Core\...            ->  StaticPHP\Core\...
#   System\Modules\...  ->  StaticPHP\...
#
# 1.x shipped the framework half migrated: the Core module still used a bare Core\ root
# while Presentation and Utils had already moved under System\Modules\. Both land on the
# same root in 2.0, and the two prefixes are disjoint, so one pass handles a tree that
# mixes them.
#
# Usage: upgrade_v2_namespaces.bash [path ...]     (default: Application)
#
# Point it at application code only. Run it on a clean working tree and read the diff -
# it is a text substitution, not a parser.

set -Eeuo pipefail

TARGETS=("$@")
if [ ${#TARGETS[@]} -eq 0 ]; then
    TARGETS=("Application")
fi

for target in "${TARGETS[@]}"; do
    if [ ! -e "$target" ]; then
        echo "error: ${target} not found" >&2
        exit 1
    fi
done

# Views reference classes too - twig templates name them in constant() and filter calls
mapfile -t FILES < <(
    find "${TARGETS[@]}" \
        -type f \( -name '*.php' -o -name '*.html' -o -name '*.twig' \) \
        -not -path '*/vendor/*' \
        -not -path '*/node_modules/*' \
        -print | sort
)

if [ ${#FILES[@]} -eq 0 ]; then
    echo "nothing to do"
    exit 0
fi

# The escaped form comes first: a php string literal spells the separator "\\", and
# rewriting the single-backslash form first would leave the two spellings inconsistent.
# Each rule refuses to fire on text it has already produced, so the script is idempotent.
perl -pi -e '
    s/(?<!StaticPHP\\\\)\bSystem\\\\Modules\\\\/StaticPHP\\\\/g;
    s/(?<!StaticPHP\\\\)\bCore\\\\/StaticPHP\\\\Core\\\\/g;
    s/\bSystem\\Modules\\/StaticPHP\\/g;
    s/(?<!StaticPHP\\)\bCore\\/StaticPHP\\Core\\/g;
' "${FILES[@]}"

echo "rewrote ${#FILES[@]} files under: ${TARGETS[*]}"
echo
echo "Not handled here, see UPGRADE.md:"
echo "  - SYS_PATH and SYS_MODULES_PATH are gone; SP_PATH is the framework's own directory"
echo "  - Tests/autoload.php and Public/index.php need the new bootstrap"
echo "  - \$project in Load:: and in autoload_configs/autoload_helpers now names a"
echo "    \$config['module_paths'] entry; the framework's own is 'staticphp'"
