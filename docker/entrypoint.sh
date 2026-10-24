#!/bin/sh
# Carbure container entrypoint: prepares /data, brings the database schema up to
# date, optionally schedules the bank synchronization, then starts PHP-FPM and nginx.
# On the first start (no /data/conf/prod.ini), the installation is done in the
# portal: open http://<host>:<published port>/ in a browser (PORT, 8080 by default).
set -e

APP=/var/www/carbure
mkdir -p /data/conf /data/logs /data/cache /data/woob
chown -R www-data:www-data /data
# woob (logins and passwords of the banks, modules): readable by www-data only. woob
# refuses to start if its file of the banks is readable by other users, as it is once
# copied into a NAS shared folder; a copied folder may also have lost its owner rights.
chmod -R u+rwX,go-rwx /data/woob 2>/dev/null || true

# Apply creditmutuel patch (FormNotFound fix for SYNASSINT page) if requested.
# Runs after woob config update so the patch survives module updates.
# Enable with PATCH_CREDITMUT=TRUE (set in docker-compose.yml or docker run -e).
patch_creditmutuel() {
    local module_dir="/data/woob/.local/share/woob/modules/3.7/woob_modules/creditmutuel"
    local pages_py="$module_dir/pages.py"
    if [ ! -f "$pages_py" ]; then
        return 0
    fi
    # Check if already patched (new xpath)
    if grep -q '//form\[.//input\[@name="_FID_GoBusinessSpaceLife"\]\]' "$pages_py"; then
        echo "Carbure: creditmutuel patch already applied" >&2
        return 0
    fi
    # Apply patch: replace old xpath with new robust one
    sed -i "s|xpath=\"//form\\[@id='C:P1:F' or @id='C:P14:F' or @id='C:P4:F' or @id='C:P5:F'or @id='C:P6:F'\\]\"|xpath='//form[.//input[@name=\"_FID_GoBusinessSpaceLife\"]]'|" "$pages_py"
    if grep -q '//form\[.//input\[@name="_FID_GoBusinessSpaceLife"\]\]' "$pages_py"; then
        echo "Carbure: creditmutuel patch applied successfully" >&2
    else
        echo "Carbure: WARNING: creditmutuel patch may have failed (pattern not found)" >&2
    fi
    chown www-data:www-data "$pages_py" 2>/dev/null || true
}

# Update of woob at each start: repositories, keyring of the woob repository (to check
# the signature of the bank modules; without it, installing a module fails with "No
# keyring found") and installed bank modules. In the background (the portal starts
# without waiting for the network), as www-data (the owner of /data/woob).
# The keyring of the woob repository is trusted by an administrator, who compares its
# fingerprint in the portal (Accounts page, "update the bank modules"): until then, the
# modules are not updated. The next keyrings are checked by woob with the previous one.
woob_update() {
    if [ -z "$(ls -A /data/woob/.local/share/woob/keyrings 2>/dev/null)" ]; then
        echo "Carbure: woob repository not trusted yet: an administrator must trust it from the Accounts page of the portal to update the bank modules" >&2
        return 0
    fi
    # Only once a keyring is trusted: without one, woob would trust the keyring of its
    # repository by itself ("*** ASSUMING YES ***"); with one, woob checks the next ones
    output=$(su -s /bin/sh www-data -c "env HOME=/data/woob woob config update" < /dev/null 2>&1) || true
    if echo "$output" | grep -qiE "aborted|error|not trusted|invalid signature"; then
        echo "Carbure: woob update failed, it will be retried at the next start: $(echo "$output" | grep -iE "aborted|error|not trusted|invalid signature" | tail -1)" >&2
        return 1
    fi
    # Record successful update timestamp
    date -u +"%Y-%m-%dT%H:%M:%SZ" > /data/woob/.last_woob_update 2>/dev/null || true
    chown www-data:www-data /data/woob/.last_woob_update 2>/dev/null || true
}
woob_update &

# Apply creditmutuel patch if requested (after woob_update in case update ran)
if [ "${PATCH_CREDITMUT}" = "TRUE" ]; then
    patch_creditmutuel
fi

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
    echo "Carbure: not installed yet, open the portal in a browser to install it (http://HOST:${CARBURE_PORT:-8080}/)"
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
