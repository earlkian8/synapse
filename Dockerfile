# syntax=docker/dockerfile:1.7
#
# SYNAPSE — the Laravel ERP (server/) and the ML inference service (model/) in one
# image. supervisord runs nginx + PHP-FPM (the web app), uvicorn (the models, on
# 127.0.0.1:8001 — reachable only from inside the container), the queue worker and
# the scheduler. Configuration comes entirely from environment variables.
#
#   docker build -t earlkian8/synapse:latest .
#   docker run --env-file server/.env.production -p 8080:8080 earlkian8/synapse:latest

ARG PHP_VERSION=8.5
ARG NODE_VERSION=24
ARG PYTHON_VERSION=3.14

# COPY --from cannot expand build args, so versioned images get named stages.
FROM node:${NODE_VERSION}-trixie-slim AS node

# ---------------------------------------------------------------------------
# PHP runtime with the extensions the app needs (shared by build and final).
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-trixie AS php-base

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        bcmath exif gd gmp intl opcache pcntl pdo_pgsql pgsql zip

# ---------------------------------------------------------------------------
# Build: Composer dependencies + Vite assets. Wayfinder's Vite plugin runs
# `php artisan wayfinder:generate`, so the asset build needs PHP and vendor/.
# ---------------------------------------------------------------------------
FROM php-base AS build

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app/server

COPY server/composer.json server/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY server/package.json server/package-lock.json server/.npmrc ./
RUN npm ci --no-audit --no-fund

COPY server/ ./
RUN mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && npm run build \
    && rm -rf node_modules resources/js/actions resources/js/routes resources/js/wayfinder \
    && rm -f bootstrap/cache/*.php

# ---------------------------------------------------------------------------
# Python: the interpreter the models were trained on (3.14) and a slim venv
# holding only what the inference service imports.
# ---------------------------------------------------------------------------
FROM php-base AS python
ARG PYTHON_VERSION

COPY --from=ghcr.io/astral-sh/uv:latest /uv /usr/local/bin/uv
ENV UV_PYTHON_INSTALL_DIR=/opt/python \
    UV_LINK_MODE=copy \
    UV_COMPILE_BYTECODE=1

COPY model/requirements-serve.txt /tmp/requirements-serve.txt
RUN uv python install ${PYTHON_VERSION} \
    && uv venv --python ${PYTHON_VERSION} /opt/ml-venv \
    && uv pip install --python /opt/ml-venv/bin/python --no-cache -r /tmp/requirements-serve.txt

# ---------------------------------------------------------------------------
# Final image.
# ---------------------------------------------------------------------------
FROM php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor curl \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/* /etc/nginx/conf.d/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini       $PHP_INI_DIR/conf.d/zz-synapse.ini
COPY docker/php-fpm.conf  /usr/local/etc/php-fpm.d/zz-synapse.conf
COPY docker/nginx.conf    /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/synapse-entrypoint

COPY --from=python /opt/python /opt/python
COPY --from=python /opt/ml-venv /opt/ml-venv

COPY --from=build --chown=www-data:www-data /app/server /app/server
COPY --chown=www-data:www-data model/api        /app/model/api
COPY --chown=www-data:www-data model/synapse_ml /app/model/synapse_ml
COPY --chown=www-data:www-data model/artifacts  /app/model/artifacts

RUN mkdir -p /app/model/artifacts/local /app/model/logs \
    && chown -R www-data:www-data /app/model/artifacts /app/model/logs \
    && ln -sfn /app/server/storage/app/public /app/server/public/storage

# Defaults for a production container. Anything set in DigitalOcean overrides them.
ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    ML_SERVICE_URL=http://127.0.0.1:8001 \
    PORT=8080 \
    RUN_MIGRATIONS=true \
    RUN_QUEUE=true \
    RUN_SCHEDULER=true \
    ML_WORKERS=1 \
    PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1

WORKDIR /app/server
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${PORT}/up" > /dev/null || exit 1

ENTRYPOINT ["synapse-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
