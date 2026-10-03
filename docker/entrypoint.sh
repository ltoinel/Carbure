#!/bin/sh
# Carbure container entrypoint: prepares /data, installs on first start,
# optionally schedules the bank synchronization, then starts Apache.
set -e

APP=/var/www/carbure
mkdir -p /data/conf/certs /data/logs /data/woob
chown -R www-data:www-data /data

# Wait for the database
echo "Carbure: waiting for the database ${DB_HOST:-db}:${DB_PORT:-3306}..."
for i in $(seq 1 60); do
    if php -r 'mysqli_report(MYSQLI_REPORT_OFF); exit(@new mysqli(getenv("DB_HOST") ?: "db", getenv("DB_USER") ?: "carbure", getenv("DB_PASSWORD") ?: "", getenv("DB_NAME") ?: "carbure", (int)(getenv("DB_PORT") ?: 3306)) && !mysqli_connect_errno() ? 0 : 1);' 2>/dev/null; then
        break
    fi
    sleep 2
done

# First start: schema, administrator and /data/conf/prod.ini with random secrets
if [ ! -f /data/conf/prod.ini ]; then
    if [ -z "${ADMIN_PASSWORD}" ]; then
        echo "Carbure: ADMIN_PASSWORD is required for the first start" >&2
        exit 1
    fi
    php "$APP/tools/install.php" --no-interaction \
        --db-host="${DB_HOST:-db}" --db-port="${DB_PORT:-3306}" \
        --db-name="${DB_NAME:-carbure}" --db-user="${DB_USER:-carbure}" --db-password="${DB_PASSWORD}" \
        --admin-user="${ADMIN_USER:-admin}" --admin-password="${ADMIN_PASSWORD}" \
        --admin-email="${ADMIN_EMAIL:-}" --language="${LANGUAGE:-fr}" \
        --woob-path="env HOME=/data/woob woob"
    chown www-data:www-data /data/conf/prod.ini
fi

# Database schema up to date at each start (new image = possibly new migrations);
# the container stops if a migration fails rather than running on a wrong schema
php "$APP/tools/migrate.php"

# Optional periodic synchronization (e.g. SYNC_INTERVAL=86400 for once a day)
if [ -n "${SYNC_INTERVAL}" ]; then
    TOKEN=$(php -r '$c = parse_ini_file("/data/conf/prod.ini"); echo $c["sync_token"] ?? "";')
    (
        while true; do
            sleep "${SYNC_INTERVAL}"
            curl -fsS -H "X-Sync-Token: ${TOKEN}" http://localhost/api/bank/sync > /dev/null 2>&1 \
                || echo "Carbure: scheduled synchronization failed" >&2
        done
    ) &
fi

exec "$@"
