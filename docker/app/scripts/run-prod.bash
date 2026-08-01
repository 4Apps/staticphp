#!/bin/bash

##################
### Preprocess ###
##################

THIS_DIR="$(dirname "$(realpath "$0")")"
source "$THIS_DIR/console.bash"
source "$THIS_DIR/app_name.bash"

APP_NAME="$(resolve_app_name .)"
if [ -z "$APP_NAME" ]; then
    echo_error "No application found under ./src - set APP=<Name> if there are several"
    exit 1
fi

echo_info "Sync static files"
rsync -a --progress "./src/${APP_NAME}/Public/" ./static/

echo_info "Sync local files from upload forlder"
rsync -a --progress "./src/${APP_NAME}/Public/uploads/" /srv/media/uploads/


########################
### Run main process ###
########################

# Define a function to handle the SIGTERM signal
function handle_sigterm {
  echo "Received SIGTERM signal. Stopping long running process..."
  echo $$
  # Stop the long running process here
  exit 0
}

echo_info "Set the trap to catch the SIGTERM signal"
trap 'handle_sigterm' SIGTERM

echo_info "Start php-fpm process"
php-fpm -F -R --force-stderr &
pid=$!

echo_info "Wait for the process $pid to finish"
wait $pid
