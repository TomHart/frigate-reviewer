# syntax=docker/dockerfile:1
#
# Frigate Review — single-container production image (design.md D12).
#
#   Stage 1 (app_build)     composer install --no-dev + prod cache warmup
#   Stage 2 (tailwind_build) Tailwind CSS compiled with the standalone CLI
#                           (arch-specific binary, no Node runtime)
#   Stage 3 (runtime)       FrankenPHP (Caddy + embedded PHP) serving /app/public
#
# State: the SQLite database and snapshot storage live on a volume mounted at
# /data (SQLITE_DB_PATH=/data/data.db, SNAPSHOT_STORAGE_PATH=/data/snapshots);
# the image itself is stateless.
#
# Build:    docker build -t frigate-review .
# Run:      docker run -v frigate-review-data:/data -p 8080:8080 frigate-review

# PHP_VERSION must satisfy composer.lock (the locked dependency set requires
# PHP >= 8.4, stricter than the composer.json floor of >= 8.2).
ARG PHP_VERSION=8.4
ARG TAILWIND_VERSION=4.3.5
ARG FRANKENPHP_VERSION=1

# ---------------------------------------------------------------------------
# Stage 1: PHP application + vendor dependencies
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli-alpine AS app_build

# unzip: composer needs it to unpack dist archives from Packagist.
# gd + intl: runtime extensions the application uses (thumbnails/perceptual
# hash via GD, locale handling via intl); pdo_sqlite ships with the base image.
RUN apk add --no-cache unzip \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first so this layer is cached until composer.lock changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress

COPY . .

# The auto-scripts (cache:clear, assets:install, importmap:install) need the
# application source; run them with production settings so the prod container
# cache is warmed inside the image. Asset compilation happens in the runtime
# stage, after the Tailwind CSS has been built and copied in.
RUN APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# ---------------------------------------------------------------------------
# Stage 2: Tailwind CSS via the standalone CLI (no Node)
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli-alpine AS tailwind_build

ARG TAILWIND_VERSION

# libstdc++: the musl build of the Tailwind CLI links against it.
RUN apk add --no-cache libstdc++ && set -eux; \
    # uname -m works with any builder (BuildKit's TARGETARCH is not always set).
    case "$(uname -m)" in \
        x86_64) tailwind_arch="linux-x64" ;; \
        aarch64|arm64) tailwind_arch="linux-arm64" ;; \
        *) echo >&2 "unsupported CPU architecture: $(uname -m)"; exit 1 ;; \
    esac; \
    release_url="https://github.com/tailwindlabs/tailwindcss/releases/download/v${TAILWIND_VERSION}/tailwindcss-${tailwind_arch}-musl"; \
    wget -q -O /usr/local/bin/tailwindcss "${release_url}"; \
    wget -q -O /tmp/sha256sums.txt "https://github.com/tailwindlabs/tailwindcss/releases/download/v${TAILWIND_VERSION}/sha256sums.txt"; \
    expected="$(awk -v asset="tailwindcss-${tailwind_arch}-musl" '$2 ~ asset {print $1}' /tmp/sha256sums.txt)"; \
    actual="$(sha256sum /usr/local/bin/tailwindcss | awk '{print $1}')"; \
    [ "${expected}" = "${actual}" ] || { echo >&2 "tailwindcss checksum mismatch: expected ${expected}, got ${actual}"; exit 1; }; \
    chmod 0755 /usr/local/bin/tailwindcss

WORKDIR /app

# The Tailwind input stylesheet declares its sources explicitly
# (@source templates/ and assets/), so only those trees are needed here.
COPY assets assets
COPY templates templates

RUN tailwindcss -i assets/styles/tailwind.input.css -o assets/styles/app.built.css --minify

# ---------------------------------------------------------------------------
# Stage 3: runtime — FrankenPHP (Caddy + embedded PHP)
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-alpine AS runtime

# su-exec: drop privileges after preparing the data volume.
# gd + intl: runtime extensions (opcache is bundled with the base image).
RUN apk add --no-cache su-exec \
    libpng \
    libjpeg-turbo \
    freetype \
    icu-libs \
 && apk add --no-cache --virtual .build-deps \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd intl \
 && apk del .build-deps

WORKDIR /app

COPY --from=app_build /app /app
# Overwrite with the CSS compiled from this exact source tree (stage 2).
COPY --from=tailwind_build /app/assets/styles/app.built.css /app/assets/styles/app.built.css

COPY docker/php-prod.ini /usr/local/etc/php/conf.d/99-app-prod.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
# Compile the asset mapper AFTER the Tailwind output is in place, so the
# manifest references files that actually exist in the image.
RUN APP_ENV=prod APP_DEBUG=0 php bin/console asset-map:compile \
 && chmod 0755 /usr/local/bin/entrypoint.sh \
 # The warmed cache and logs are written at runtime by the unprivileged
 # application user; cache lives in the container layer, not on the volume.
 && chown -R www-data:www-data /app/var

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SQLITE_DB_PATH=/data/data.db \
    SNAPSHOT_STORAGE_PATH=/data/snapshots \
    XDG_CONFIG_HOME=/tmp/caddy-config \
    XDG_DATA_HOME=/tmp/caddy-data \
    PHP_USER=www-data \
    PHP_GROUP=www-data

VOLUME /data
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD wget -q --spider http://127.0.0.1:8080/ || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
