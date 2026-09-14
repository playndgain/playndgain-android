# syntax=docker/dockerfile:1.7
#
# PLAYNDGAIN — one image, three roles.
#
# docs/11-infrastructure.md is still the spec: nginx in front of PHP-FPM, Redis
# behind, Horizon on its own supervisors, the scheduler on its own clock. This
# file is that same shape packed into a container so Dokploy can deploy it.
# What changed is where the processes live, not what they are — the nginx
# snippets in this image are the very files from deploy/nginx/snippets, copied
# in verbatim so the header rules the offerwall depends on have exactly one
# source in this repository.
#
# The three roles (app, horizon, scheduler) run the same image and differ only
# by CONTAINER_ROLE, which docker/entrypoint.sh reads. One build, one tag, no
# chance of the worker running last week's code.

ARG PHP_VERSION=8.5
ARG NODE_VERSION=24

# ---------------------------------------------------------------------------
# base — PHP with the extension set docs/11 §1 names, and nothing else
# ---------------------------------------------------------------------------
#
# `bcmath` is not optional: the ledger compares money with bccomp and float
# comparison there produces false drift alerts. `pcntl` is not optional either
# — Horizon's master process forks with it, and without it `artisan horizon`
# refuses to start.

FROM php:${PHP_VERSION}-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        redis \
        zip \
    && apk add --no-cache tzdata

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# ---------------------------------------------------------------------------
# vendor — Composer packages only
# ---------------------------------------------------------------------------
#
# `--no-scripts`: post-autoload-dump runs `package:discover` and
# `filament:upgrade`, and neither can run before the application source is
# here. They run in the runtime stage, once, with everything in place.
#
# The stage inherits `base` rather than using the `composer` image so the
# platform check resolves against the extensions the application will actually
# run on. A vendor tree resolved against a different PHP is a runtime surprise.

FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-progress

# ---------------------------------------------------------------------------
# assets — Vite build
# ---------------------------------------------------------------------------
#
# Node is a build-time dependency only (docs/11 §1): nothing it produces runs
# in production, it emits hashed files into public/build.
#
# `vendor` is copied in because resources/css/filament/theme.css imports
# Filament's own stylesheet and `@source`s four packages' Blade views. Without
# it Tailwind emits a panel theme missing every class the vendor markup uses.

FROM node:${NODE_VERSION}-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
COPY app ./app
COPY public ./public
COPY --from=vendor /var/www/html/vendor ./vendor

# `npm run build` also runs `npm run flags`, which copies flag-icons into
# public/flags. Both outputs are collected by the runtime stage.
RUN npm run build

# ---------------------------------------------------------------------------
# runtime — nginx + PHP-FPM under supervisor
# ---------------------------------------------------------------------------

FROM base AS runtime

# `mariadb-client` is what spatie/laravel-backup shells out to: without
# mysqldump on PATH the nightly backup fails every night and the only place it
# says so is backup:monitor (docs/11 §6). Alpine's client is MariaDB, so
# `mariadb-connector-c` is required too — it ships
# caching_sha2_password.so, which MySQL 9 uses and the slim client package
# does not install. Dump config must also pass skip-ssl (MySQL 9's
# generated cert fails MariaDB's TLS check, error 2026).
# `gettext` is envsubst, which renders the server_name into the three nginx
# blocks at start; `su-exec` is how the entrypoint drops from root — which nginx
# needs to bind :80 — to www-data for every artisan command, so nothing it
# writes into storage/ or bootstrap/cache is owned by a user PHP-FPM is not.
RUN apk add --no-cache \
        nginx \
        supervisor \
        mariadb-client \
        mariadb-connector-c \
        gettext \
        su-exec \
    && mkdir -p \
        /run/nginx \
        /var/log/supervisor \
        /var/lib/nginx/tmp/client_body \
        /var/lib/nginx/tmp/proxy \
        /var/lib/nginx/tmp/fastcgi \
        /var/lib/nginx/tmp/uwsgi \
        /var/lib/nginx/tmp/scgi \
    && chown -R www-data:www-data /var/lib/nginx /run/nginx

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# --- Container configuration ------------------------------------------------
#
# The snippets come from deploy/, not from a copy made for the container. The
# header asymmetry they encode — X-Frame-Options cleared on the wall, SAMEORIGIN
# everywhere else — is asserted by tests/Feature/Security/SecurityChecklistTest
# against those exact files, so a container that copies them keeps the promise
# the test proves. A second copy under docker/ would drift and nothing would
# notice.
COPY deploy/nginx/snippets/                         /etc/nginx/snippets/
COPY deploy/nginx/conf.d/playndgain-limit-zones.conf /etc/nginx/conf.d/
COPY docker/nginx/nginx.conf                        /etc/nginx/nginx.conf
COPY docker/nginx/templates/                        /etc/nginx/templates/
COPY docker/php/php.ini                             /usr/local/etc/php/conf.d/zz-playndgain.ini
COPY docker/php/www.conf                            /usr/local/etc/php-fpm.d/zz-playndgain.conf
COPY docker/supervisord.conf                        /etc/supervisord.conf
COPY docker/entrypoint.sh                           /usr/local/bin/playndgain-entrypoint
COPY docker/migrate-locked.php                      /usr/local/bin/migrate-locked.php

RUN chmod +x /usr/local/bin/playndgain-entrypoint

# --- Application ------------------------------------------------------------

COPY --chown=www-data:www-data . .
COPY --from=vendor  --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=assets  --chown=www-data:www-data /app/public/build    ./public/build
COPY --from=assets  --chown=www-data:www-data /app/public/flags    ./public/flags

# The scripts Composer was told to skip, plus the two build-time artefacts that
# are pure functions of the source: Filament's published assets and the docs
# search index. `config:cache` is deliberately NOT here — it bakes environment
# values, and the environment does not exist until Dokploy starts the container.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && php artisan filament:upgrade \
    && php artisan docs:index \
    && mkdir -p \
        storage/app/public \
        storage/app/private \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache public \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 80

# Health checks live in docker-compose.yml per role. An image-level
# `wget /up` probe would run on horizon and scheduler too, and neither
# process listens on :80 — Swarm then rolls those services back forever.

ENTRYPOINT ["playndgain-entrypoint"]
