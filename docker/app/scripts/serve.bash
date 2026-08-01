#!/bin/bash
#
# The develop container's php server.
#
# Wrapped rather than spelled out in supervisord.services.conf because the application is
# not part of this repository - you start the container first and run
# `./staticphp app add <Name>` afterwards. Until then there is nothing to serve, so this
# exits and lets supervisord retry; the server comes up on its own once the application
# exists, with no container restart.

THIS_DIR="$(dirname "$(realpath "$0")")"
source "$THIS_DIR/app_name.bash"

cd /srv/app || exit 1

APP_NAME="$(resolve_app_name /srv/app)"

if [ -z "$APP_NAME" ]; then
    echo "No application to serve yet."
    echo "  ./staticphp app add Application          # or --preset=react"
    echo "  APP=<Name> ...                           # if there is more than one"

    # Slow the retry down, so the log stays readable while somebody is still typing
    sleep 10
    exit 1
fi

echo "Serving src/${APP_NAME}/Public on 0.0.0.0:5000"

exec /usr/bin/php -S 0.0.0.0:5000 \
    -t "./src/${APP_NAME}/Public/" \
    "./src/${APP_NAME}/Public/dev-router.php"
