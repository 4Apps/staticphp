####################
### Install base ###
####################

FROM debian:bookworm as base
MAINTAINER gm@gm.lv

# Avoid interactive cli blockers
ENV DEBIAN_FRONTEND noninteractive

# OS dependecies
RUN apt-get update -y \
    && apt-get install -y  --no-install-recommends apt-utils

RUN apt-get install -y  --no-install-recommends \
    apt-transport-https lsb-release ca-certificates gnupg \
    less nano wget curl unzip rsync gettext-base

# PHP
ARG PHP_VERSION=8.2
RUN wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg \
    && sh -c 'echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list' \
    && apt-get update -y \
    && apt-get install -y --no-install-recommends php${PHP_VERSION}-cli php${PHP_VERSION}-dev \
        php${PHP_VERSION}-curl php${PHP_VERSION}-bcmath php${PHP_VERSION}-xml php${PHP_VERSION}-zip \
        php${PHP_VERSION}-mbstring php${PHP_VERSION}-gd php${PHP_VERSION}-intl \
        php${PHP_VERSION}-pgsql php${PHP_VERSION}-mysql php${PHP_VERSION}-ldap php${PHP_VERSION}-sybase

# Replace php ini entries
RUN sed -i "s/memory_limit = .*/memory_limit = 512M/" /etc/php/${PHP_VERSION}/cli/php.ini \
    && sed -i "s/max_execution_time = .*/max_execution_time = 300/" /etc/php/${PHP_VERSION}/cli/php.ini \
    && sed -i "s/max_input_time = .*/max_input_time = 300/" /etc/php/${PHP_VERSION}/cli/php.ini \
    && sed -i "s/post_max_size = .*/post_max_size = 100M/" /etc/php/${PHP_VERSION}/cli/php.ini \
    && sed -i "s/upload_max_filesize = .*/upload_max_filesize = 100M/" /etc/php/${PHP_VERSION}/cli/php.ini \
    && sed -i "s/;date.timezone.*/date.timezone = Europe\/Riga/" /etc/php/${PHP_VERSION}/cli/php.ini

# Copy config and run scripts
COPY docker/app/conf/ /root/meta/conf/
COPY docker/app/data/ /root/meta/data/
COPY docker/app/scripts/ /root/meta/scripts/


###################
### Development ###
###################

FROM base AS development

# Go to web folder
WORKDIR /srv/app

# Install notify tools for watching file system changes
RUN apt-get -y  --no-install-recommends install \
    openssh-client git supervisor \
    inotify-tools

# Python
RUN apt-get install -y --no-install-recommends python3 python3-pip python3-venv
RUN update-alternatives --install /usr/bin/python python /usr/bin/python3 1

# Create virtualenv
RUN python3 -m venv /srv/venv
ENV PATH="/srv/venv/bin:$PATH"

# Copy and install python requirements
COPY requirements.txt /root/meta/data/requirements-dev.txt
RUN python3 -m pip install --no-cache-dir -r /root/meta/data/requirements-dev.txt

# Nodejs
ARG NODE_VERSION=20
RUN mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_${NODE_VERSION}.x nodistro main" | tee /etc/apt/sources.list.d/nodesource.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends nodejs \
    && npm install -g npm@latest

# Setup git
# ARG GIT_NAME
# ARG GIT_EMAIL
# RUN git config --global user.email "$GIT_NAME" \
#     && git config --global user.name "$GIT_EMAIL" \
#     && git config --global pull.rebase true

# PHP composer
# Source: https://stackoverflow.com/a/42147748
RUN wget -O /tmp/composer-setup.php https://getcomposer.org/installer \
    && wget -O /tmp/composer-setup.sig https://composer.github.io/installer.sig \
    # Make sure we're installing what we think we're installing!
    && php -r "if (hash('SHA384', file_get_contents('/tmp/composer-setup.php')) !== trim(file_get_contents('/tmp/composer-setup.sig'))) { unlink('/tmp/composer-setup.php'); echo 'Invalid installer' . PHP_EOL; exit(1); }" \
    && php /tmp/composer-setup.php --no-ansi --install-dir=/usr/local/bin --filename=composer --stable \
    && rm -f /tmp/composer-setup.*

# Setup supervisor
RUN envsubst < /root/meta/conf/supervisord.services.conf > /etc/supervisor/conf.d/services.conf

# Set runtime stuff
CMD ["/root/meta/scripts/run.bash"]

EXPOSE 9100


###############
### Testing ###
###############

# Everything CI runs, in the project's own image. Kept as its own stage so the build
# stage below stays free of dev dependencies.
FROM development AS testing

WORKDIR /srv/app

COPY . .

RUN composer install --prefer-dist --no-interaction --no-progress \
    && npm ci --no-audit --no-fund

CMD ["./scripts/code_tests.bash"]


#############
### Build ###
#############

FROM development AS build

WORKDIR /srv/app/build

# Copy all to build folder
COPY . .

# Install composer dependencies
RUN composer install --prefer-dist --no-dev -o

# Install npm dependencies
RUN npm install && npx update-browserslist-db@latest

# Copy and Build assets
# RUN cp -r `pwd`/modules/[private_php_module] vendor/[private_php_module]
RUN npm run build

# Set runtime stuff
CMD ["/root/meta/scripts/build.bash"]


##########################
### Production EXAMPLE ###
##########################

FROM base AS production

WORKDIR /srv/app

# Copy whole app into container
COPY --from=build /srv/app/build /srv/app

# Install php runner
RUN apt-get update -y \
    && apt-get install -y --no-install-recommends php${PHP_VERSION}-fpm \
    && ln -s /usr/sbin/php-fpm8.2 /usr/sbin/php-fpm

# Clean up caches
RUN apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && rm -rf /var/cache/apt/*

# Set runtime stuff
CMD ["/root/meta/scripts/run-prod.bash"]

EXPOSE 9100
