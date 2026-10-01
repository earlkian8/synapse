#!/bin/sh
# Prepares the container from its environment, then hands over to supervisord.
set -e

trap 'status=$?; [ "$status" -ne 0 ] && echo "[synapse] startup FAILED (exit $status) - see the error above" >&2' EXIT

cd /app/server
echo "[synapse] starting (APP_ENV=${APP_ENV}, PORT=${PORT:-8080}, DB_HOST=${DB_HOST:-unset})"

if [ -z "$APP_KEY" ]; then
    echo "[synapse] APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

# Normalise the supervisord switches to true/false.
for var in RUN_QUEUE RUN_SCHEDULER RUN_MIGRATIONS; do
    eval "value=\${$var:-true}"
    case "$value" in
        1|true|TRUE|True|yes|on) export "$var=true" ;;
        *) export "$var=false" ;;
    esac
done
export ML_WORKERS="${ML_WORKERS:-1}"

sed -i "s/listen \(\[::\]:\)\?[0-9_A-Z]* /listen \1${PORT:-8080} /" /etc/nginx/nginx.conf

# Writable state may arrive on an empty volume.
mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    /app/model/artifacts/local
chown -R www-data:www-data storage bootstrap/cache /app/model/artifacts/local

run() { su -s /bin/sh www-data -c "$*"; }

# Cache config/routes/views/events from the runtime environment. Not
# optimize:clear: it also empties the database cache store, whose table does not
# exist until the first migration has run.
echo "[synapse] caching config, routes and views"
run "php artisan optimize"

if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "[synapse] running migrations"
    run "php artisan migrate --force"
fi

echo "[synapse] starting web server, ML service, queue and scheduler on port ${PORT:-8080}"
trap - EXIT
exec "$@"
