#!/bin/sh
# Carbure container entrypoint: prepares /data, brings the database schema up to
# date, optionally schedules the bank synchronization, then starts PHP-FPM and nginx.
# On the first start (no /data/conf/prod.ini), the installation is done in the
# portal: open http://<host>:8080/ in a browser.
set -e

APP=/var/www/carbure
mkdir -p /data/conf/certs /data/logs /data/woob
chown -R www-data:www-data /data

if [ -f /data/conf/prod.ini ]; then
    # Wait for the database, then apply the migrations of the new version (if any);
    # the container stops if a migration fails rather than running on a wrong schema
    for i in $(seq 1 60); do
        php -r '$c = parse_ini_file("/data/conf/prod.ini"); mysqli_report(MYSQLI_REPORT_OFF);
            exit(@new mysqli($c["db_hostname"], $c["db_username"], $c["db_password"], $c["db_name"], (int)($c["db_port"] ?? 3306)) && !mysqli_connect_errno() ? 0 : 1);' 2>/dev/null && break
        echo "Carbure: waiting for the database..."
        sleep 2
    done
    su -s /bin/sh www-data -c "php $APP/tools/migrate.php"
else
    echo "Carbure: not installed yet, open the portal in a browser to install it (http://<host>:8080/)"
fi

# Optional periodic synchronization (e.g. SYNC_INTERVAL=86400 for once a day);
# the token is read at each run: the configuration may be created later by the wizard
if [ -n "${SYNC_INTERVAL}" ]; then
    (
        while true; do
            sleep "${SYNC_INTERVAL}"
            TOKEN=$(php -r '$c = @parse_ini_file("/data/conf/prod.ini"); echo $c["sync_token"] ?? "";')
            [ -n "${TOKEN}" ] || continue
            curl -fsS -H "X-Sync-Token: ${TOKEN}" http://localhost/api/bank/sync > /dev/null 2>&1 \
                || echo "Carbure: scheduled synchronization failed" >&2
        done
    ) &
fi

php-fpm -D
exec "$@"
