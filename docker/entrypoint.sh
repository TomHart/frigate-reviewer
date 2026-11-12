#!/bin/sh
# Container entrypoint: prepare the data volume, apply database migrations,
# then hand off to FrankenPHP (Caddy + embedded PHP) in the foreground.
#
# The container starts as root so it can fix ownership on a freshly mounted
# volume; everything after that runs as the unprivileged PHP user.
set -eu

PHP_USER="${PHP_USER:-www-data}"
PHP_GROUP="${PHP_GROUP:-www-data}"

data_dir="$(dirname "$SQLITE_DB_PATH")"

mkdir -p "$data_dir" "$SNAPSHOT_STORAGE_PATH"
chown -R "$PHP_USER:$PHP_GROUP" "$data_dir" "$SNAPSHOT_STORAGE_PATH"

# CSRF protection needs a secret. If the operator has not supplied one,
# generate it once and persist it on the data volume so it survives
# container restarts. A real APP_SECRET environment variable always wins.
if [ -z "${APP_SECRET:-}" ]; then
    secret_file="$data_dir/app_secret"
    if [ ! -s "$secret_file" ]; then
        echo "APP_SECRET not set: generating one and storing it at $secret_file"
        umask 077
        od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > "$secret_file"
        chown "$PHP_USER:$PHP_GROUP" "$secret_file"
    fi
    APP_SECRET="$(cat "$secret_file")"
    export APP_SECRET
fi

echo "Running database migrations..."
su-exec "$PHP_USER:$PHP_GROUP" php bin/console doctrine:migrations:migrate --no-interaction

exec su-exec "$PHP_USER:$PHP_GROUP" frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
