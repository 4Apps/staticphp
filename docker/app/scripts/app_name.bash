#!/bin/bash
#
# Works out which application the container should act on.
#
# Applications are not tracked in this repository - presets/ is the source and
# `composer setup` or `./staticphp app add` lays one down - so a freshly started develop
# container legitimately has none yet. Everything that needs an application name sources
# this and checks the result rather than assuming src/Application exists.
#
#   APP=Shop     pick one explicitly, needed once there is more than one
#   (unset)      the only application there is, or empty when there are none
#
# Prints the name on stdout, or nothing.

resolve_app_name() {
    local base="${1:-.}"

    if [ -n "${APP:-}" ]; then
        echo "$APP"
        return 0
    fi

    local found=()
    local dir
    for dir in "$base"/src/*/; do
        [ -f "${dir}Public/index.php" ] || continue
        found+=("$(basename "$dir")")
    done

    # More than one and no APP set is ambiguous - say nothing rather than guess, and let
    # the caller print something useful
    if [ "${#found[@]}" -eq 1 ]; then
        echo "${found[0]}"
    fi
}
