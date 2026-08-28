#!/bin/bash

MOUNT_PATH="/srv/app_mounted"
DIST_PATH="${MOUNT_PATH}/dist"

THIS_DIR="$(dirname "$(realpath "$0")")"
source "$THIS_DIR/console.bash"
source "$THIS_DIR/app_name.bash"

# Applications are generated from presets/ rather than tracked, so the name is not known
# up front. Set APP when the project holds more than one.
APP_NAME="$(resolve_app_name .)"
if [ -z "$APP_NAME" ]; then
    echo_error "No application found under ./src - set APP=<Name> if there are several"
fi

echo_process "Make sure .env.prod file exists .. "
if [ ! -f "./src/${APP_NAME}/.env.prod" ]; then
    echo_error ".env file not found in \"./src/${APP_NAME}/.env.prod\""
fi
echo_nl "OK"

echo_process "Figure out current version .. "
if [ ! -f "${MOUNT_PATH}/scripts/build_info.bash" ]; then
    echo_error "${MOUNT_PATH} is not mounted - run this through \"docker compose run --rm build\""
fi

# The version comes from the commit count, and .git is dockerignored - so the copy inside
# the image cannot derive it. The same checkout is mounted with its history intact: stamp
# from there, write the result into the tree being packaged.
# * safe.directory because the mount is owned by the host user, whose uid need not match
BUILD_INFO="$(pwd)/.build_info.json"
GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0="*" \
BUILD_INFO_OUTPUT="$BUILD_INFO" bash "${MOUNT_PATH}/scripts/build_info.bash" > /dev/null \
    || echo_error "Could not generate ${BUILD_INFO}"

VERSION=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"] ?? "";' "$BUILD_INFO")
VERSION="${VERSION#v}"
if [ -z "$VERSION" ]; then
    echo_error "No version in ${BUILD_INFO}"
fi
echo_nl "${VERSION}"

# Basic php file check
echo_info "Basic php file check"
for file in $(find ./src/ -iname "*.php"); do
    php -l $file > /dev/null

    ret=$?
    if [ $ret -ne 0 ]; then
        echo_error "Error in $file" $ret
    fi
done

echo_info "Create dist directory"
mkdir -p $DIST_PATH && chmod 777 $DIST_PATH

# Lower cased - the application name is a namespace ("Shop"), the artifact is a file
FILENAME="${APP_NAME,,}-${VERSION}.tgz"
echo_info "Compress archive to $DIST_PATH/$FILENAME"
# .build_info.json is listed before the excludes on purpose: GNU tar applies --exclude only
# to the operands that follow it, and the dotfile exclusion is what keeps .env* out
tar -czvhf $DIST_PATH/$FILENAME ./.build_info.json \
--exclude='.[^/]*' \
--exclude=*__pycache__* \
--exclude=./src/${APP_NAME}/Cache/* \
--exclude=./src/${APP_NAME}/Public/uploads/* \
--exclude=./src/${APP_NAME}/Public/php-metrics* \
--exclude=./src/${APP_NAME}/Public/docs* \
--exclude=./src/${APP_NAME}/Public/assets/vendor* \
./LICENSE ./README.md ./scripts/ ./src/ ./vendor/
ret=$?
if [ $ret -ne 0 ]; then
    echo_fail
    exit $ret
fi

echo_info "Fix permissions"
chmod 777 $DIST_PATH/$FILENAME
ret=$?
if [ $ret -ne 0 ]; then
    echo_fail
    exit $ret
fi

# Success
echo_success
