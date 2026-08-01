#!/bin/bash

THIS_DIR="$(dirname "$(realpath "$0")")"
source "$THIS_DIR/console.bash"

# TODO: Consider moving into container
# echo_info "Fix ssh key permissions"
# mkdir /root/.ssh && \
#     cp /run/secrets/user_ssh_key /root/.ssh/id_rsa && \
#     chmod 600 /root/.ssh/id_rsa
#
# TEST_FILE=./composer.json
# if [ ! -f "$TEST_FILE" ]; then
#     echo_info "Sync files to volume"
#     rsync -a --progress --delete --no-owner -O --exclude 'node_modules/*' --exclude 'vendor/*' /srv/app/web_mounted/ /srv/app/web/
# fi

echo_info "Install composer dependecies"
composer install

echo_info "Install npm dependecies"
npm install

echo_info "Update browserslist database"
npx update-browserslist-db@latest

# TODO: Do we need cache anymore?
# echo_info "Link dependecies from cache"
# ln -sfn /srv/app/cache/node_modules ./node_modules
# ln -sfn /srv/app/cache/vendor ./vendor

# Setup precommit link
ln -sf /srv/app/scripts/git_pre_commit.bash /srv/app/.git/hooks/pre-commit

########################
### Run main process ###
########################

# Define a function to handle the SIGTERM signal
function handle_sigterm {
  echo "Received SIGTERM signal. Stopping long running process..."

  # Gracefully close the main process
  /usr/bin/supervisorctl shutdown

  # Exit with the SIGTERM received code
  exit 143; # 128 + 15 -- SIGTERM
}

echo_info "Set the trap to catch the SIGTERM signal"
trap 'handle_sigterm' SIGTERM

echo_info "Start supervisord process"
/usr/bin/supervisord -c /etc/supervisor/supervisord.conf -n &
pid=$!

echo_info "Wait for the process $pid to finish"
wait $pid
