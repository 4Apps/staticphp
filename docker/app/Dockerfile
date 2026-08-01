####################
### Install base ###
####################

# Declared before the first FROM so every stage can re-declare and see it. Stages that
# inherit via "FROM base" pick it up automatically, this only makes it explicit.
ARG PHP_VERSION=8.4

FROM debian:trixie-slim AS base

ARG PHP_VERSION

LABEL maintainer="Gints Murans gm@gm.lv"
LABEL description="Docker image for the staticphp framework"

# Avoid interactive cli blockers
ENV DEBIAN_FRONTEND=noninteractive
ENV MAKEFLAGS=-j6

# OS dependecies
# * Caches are dropped in the same layer, cleaning them later does not shrink the image
RUN apt-get update -y \
    && apt-get install -y --no-install-recommends \
        ca-certificates less nano wget curl zip unzip rsync gettext-base locales \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/*

# Setup locales
RUN printf "lv_LV.UTF-8 UTF-8\nen_US.UTF-8 UTF-8\n" > /etc/locale.gen \
    && locale-gen
ENV LANG=lv_LV.UTF-8

# PHP
RUN mkdir -p /etc/apt/keyrings \
    && wget -O /etc/apt/keyrings/php.gpg https://packages.sury.org/php/apt.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/php.gpg] https://packages.sury.org/php/ trixie main" > /etc/apt/sources.list.d/php.list \
    && apt-get update -y \
    && apt-get install -y --no-install-recommends php${PHP_VERSION}-cli \
        php${PHP_VERSION}-curl php${PHP_VERSION}-bcmath php${PHP_VERSION}-xml php${PHP_VERSION}-zip \
        php${PHP_VERSION}-mbstring php${PHP_VERSION}-gd php${PHP_VERSION}-intl \
        php${PHP_VERSION}-pgsql php${PHP_VERSION}-mysql php${PHP_VERSION}-sqlite3 \
        php${PHP_VERSION}-ldap php${PHP_VERSION}-sybase \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/*

# Php ini overrides, kept in one file so every SAPI links the same settings
RUN printf '%s\n' \
        'memory_limit = 512M' \
        'max_execution_time = 300' \
        'max_input_time = 300' \
        'post_max_size = 100M' \
        'upload_max_filesize = 100M' \
        'date.timezone = Europe/Riga' \
        > /etc/php/php-app.ini \
    && ln -s /etc/php/php-app.ini /etc/php/${PHP_VERSION}/cli/conf.d/99-app.ini

# Create non-root user
ARG LOCAL_USER_ID=1000
ARG LOCAL_GROUP_ID=1000
RUN groupadd -g ${LOCAL_GROUP_ID} appuser \
    && useradd -u ${LOCAL_USER_ID} -g appuser -m -s /bin/bash appuser

# Copy config and run scripts
# * /srv instead of /root, which is 0700 and unreadable to the non-root user
COPY docker/app/conf/ /srv/meta/conf/
COPY docker/app/data/ /srv/meta/data/
COPY docker/app/scripts/ /srv/meta/scripts/

# Survive login shells that re-source /etc/profile, which on Debian resets PATH and drops
# the sbin dirs for non-root users - php-fpm lives in /usr/sbin
RUN printf '%s\n' \
    'export PATH="/srv/app/vendor/bin:/usr/local/sbin:/usr/sbin:/sbin:$PATH"' \
    > /etc/profile.d/00-app.sh


###################
### Development ###
###################

FROM base AS development

ARG GIT_NAME
ARG GIT_EMAIL

# Go to web folder
WORKDIR /srv/app

# Add composer vendor binaries to PATH
ENV PATH="/srv/app/vendor/bin:$PATH"

# Development tools and notify tools for watching file system changes
RUN apt-get update -y \
    && apt-get install -y --no-install-recommends \
        gnupg openssh-client git supervisor inotify-tools \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/*

# Nodejs
ARG NODE_VERSION=22
RUN curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_VERSION}.x nodistro main" > /etc/apt/sources.list.d/nodesource.list \
    && apt-get update -y \
    && apt-get install -y --no-install-recommends nodejs \
    && npm install -g npm@11 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/*

# PHP composer
# Source: https://stackoverflow.com/a/42147748
RUN wget -O /tmp/composer-setup.php https://getcomposer.org/installer \
    && wget -O /tmp/composer-setup.sig https://composer.github.io/installer.sig \
    # Make sure we're installing what we think we're installing!
    && php -r "if (hash('SHA384', file_get_contents('/tmp/composer-setup.php')) !== trim(file_get_contents('/tmp/composer-setup.sig'))) { unlink('/tmp/composer-setup.php'); echo 'Invalid installer' . PHP_EOL; exit(1); }" \
    && php /tmp/composer-setup.php --no-ansi --install-dir=/usr/local/bin --filename=composer --stable \
    && rm -f /tmp/composer-setup.*

# Setup supervisor - move socket, pid and logs where the non-root user can write
RUN mkdir -p /var/run/supervisor /var/log/supervisor \
    && chown -R appuser:appuser /var/run/supervisor /var/log/supervisor /srv/app \
    && sed -i \
        -e 's|/var/run/supervisor.sock|/var/run/supervisor/supervisor.sock|' \
        -e 's|/var/run/supervisord.pid|/var/run/supervisor/supervisord.pid|' \
        /etc/supervisor/supervisord.conf \
    && envsubst < /srv/meta/conf/supervisord.services.conf > /etc/supervisor/conf.d/services.conf

# Switch to non-root user
USER appuser

# Setup git
RUN git config --global user.email "$GIT_EMAIL" \
    && git config --global user.name "$GIT_NAME" \
    && git config --global pull.rebase true \
    && git config --global core.fileMode true \
    && git config --global safe.directory /srv/app

# Set runtime stuff
CMD ["/srv/meta/scripts/run.bash"]

EXPOSE 5000


###############
### Testing ###
###############

# Everything CI runs, in the project's own image. Kept as its own stage so the build
# stage below stays free of dev dependencies and the two can run in parallel.
FROM development AS testing

WORKDIR /srv/app

COPY --chown=appuser:appuser . .

RUN composer install --prefer-dist --no-interaction --no-progress \
    && npm ci --no-audit --no-fund

CMD ["./scripts/code_tests.bash"]


#############
### Build ###
#############

FROM development AS build

WORKDIR /srv/app/build

# Copy all to build folder
COPY --chown=appuser:appuser . .

# Install composer dependencies
RUN composer install --prefer-dist --no-dev -o

# Copy and Build assets, then drop the node toolchain - nothing downstream needs it
# RUN cp -r `pwd`/modules/[private_php_module] vendor/[private_php_module]
RUN npm ci --no-audit --no-fund \
    && npx update-browserslist-db@latest \
    && npm run build \
    && rm -rf node_modules

# Set runtime stuff
CMD ["/srv/meta/scripts/build.bash"]


##########################
### Production EXAMPLE ###
##########################

FROM base AS production

ARG PHP_VERSION

WORKDIR /srv/app

# Install php runner (needs root). Done before copying the app so that app changes
# do not invalidate this layer
RUN apt-get update -y \
    && apt-get install -y --no-install-recommends php${PHP_VERSION}-fpm \
    && ln -s /usr/sbin/php-fpm${PHP_VERSION} /usr/sbin/php-fpm \
    && ln -s /etc/php/php-app.ini /etc/php/${PHP_VERSION}/fpm/conf.d/99-app.ini \
    # * Run the pool as the non-root user and listen on a tcp port
    && sed -i \
        -e 's/^user = .*/user = appuser/' \
        -e 's/^group = .*/group = appuser/' \
        -e 's|^listen = .*|listen = 9100|' \
        /etc/php/${PHP_VERSION}/fpm/pool.d/www.conf \
    # * Fix fpm log path for non-root
    && sed -i \
        -e 's|^error_log = .*|error_log = /var/log/php-fpm.log|' \
        /etc/php/${PHP_VERSION}/fpm/php-fpm.conf \
    && touch /var/log/php-fpm.log \
    # * Runtime dirs run-prod.bash writes into. /srv/app needs to be owned by appuser
    # * too - COPY --chown only covers the contents, not the directory itself
    && mkdir -p /srv/media/uploads /srv/app/static \
    && chown -R appuser:appuser /run/php /var/log/php-fpm.log /srv/media /srv/app \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/*

# Copy whole app into container
COPY --from=build --chown=appuser:appuser /srv/app/build /srv/app

# Clean up files that are not needed at runtime
RUN rm -f composer.json composer.lock phpcs.xml \
        package.json package-lock.json \
        eslint.config.mjs rspack.config.js tsconfig.base.json \
        README.md LICENSE AGENTS.md CLAUDE.md \
    && rm -rf docker resources \
    # * Asset sources, already compiled into assets/dist. Per application, because the
    # * name is whatever the project called it - see docker/app/scripts/app_name.bash
    && rm -rf src/*/Public/assets/src src/*/tsconfig.json \
    # * Upload target, created here rather than before the COPY: src/ does not exist yet
    # * at that point, so the name cannot be globbed
    && for d in src/*/Public; do mkdir -p "$d/uploads"; done \
    && chown -R appuser:appuser src

# Switch to non-root user
USER appuser

# Set runtime stuff
CMD ["/srv/meta/scripts/run-prod.bash"]

EXPOSE 9100
