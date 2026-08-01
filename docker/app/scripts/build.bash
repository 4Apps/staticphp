#!/bin/bash

DIST_PATH="/srv/app_mounted/dist"

THIS_DIR="$(dirname "$(realpath "$0")")"
source "$THIS_DIR/console.bash"

echo_process "Make sure .env.prod file exists .. "
if [ ! -f "./src/Application/.env.prod" ]; then
    echo_error ".env file not found in \"./src/Application/.env.prod\""
fi
echo_nl "OK"

echo_process "Figure out current version .. "
VERSION=$(cat .bumpversion.cfg | grep current_version | sed -r s,"^.*= ",,)
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

FILENAME=pm-$VERSION.tgz
echo_info "Compress archive to $DIST_PATH/$FILENAME"
tar --exclude='.[^/]*' \
--exclude=*__pycache__* \
--exclude=./src/Application/Cache/* \
--exclude=./src/Application/Public/uploads/* \
--exclude=./src/Application/Public/php-metrics* \
--exclude=./src/Application/Public/docs* \
--exclude=./src/Application/Public/assets/vendor* \
-czvhf $DIST_PATH/$FILENAME ./LICENSE ./README.md ./scripts/ ./src/ ./vendor/
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
