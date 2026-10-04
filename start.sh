#!/usr/bin/env bash
# Starts Carbure for development, with the architecture of production: the Docker image
# of Carbure (nginx, PHP-FPM, woob) and MariaDB 11, the code of the project being mounted
# so that a change is taken at the next request (docker/docker-compose.yml with the
# override docker/docker-compose.dev.yml).
#
#   ./start.sh            start (sample data the first time), then show the logs
#   ./start.sh --reset    start with fresh sample data (also after a change of sql/carbure.sql)
#   ./start.sh --build    rebuild the image first (change of docker/ or of the PHP extensions)
#   ./start.sh --fake-woob  stub woob: the fake woob of the tests answers for the banks,
#                         so that the sample accounts can be synchronized (WOOB=fake)
#   ./start.sh --stop     stop the stack (the database and /data are kept)
#   ./start.sh --clean    stop the stack and delete the database and /data
#   ./start.sh --help     show this help
#
# Portal: http://localhost:8000/portal/ (admin / admin-password, marie / marie-password).
# The options can be combined (./start.sh --reset --fake-woob).
# Settings: PORT (8000), DB_PORT (3308: MariaDB from the host, carbure / dev).
# Ctrl+C stops showing the logs, the stack keeps running.
set -euo pipefail

cd "$(dirname "$0")"

export PORT=${PORT:-8000} DB_PORT=${DB_PORT:-3308} DB_PASSWORD=dev
WOOB=${WOOB:-real}

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
fail() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }
compose() { docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml "$@"; }

# Help: the comment at the top of this script
usage() { sed -n '2,/^set /{/^set /d;s/^# \{0,1\}//;p}' "$0"; }

for option in "$@"; do
    case "$option" in -h|--help) usage; exit 0 ;; esac
done

command -v docker >/dev/null 2>&1 || fail "Docker is required"

RESET=false
BUILD=()
for option in "$@"; do
    case "$option" in
        --stop) compose down; exit 0 ;;
        --clean) compose down --volumes; exit 0 ;;
        --reset) RESET=true ;;
        --build) BUILD=(--build) ;;
        --fake-woob) WOOB=fake ;;
        *) usage >&2; fail "unknown option $option" ;;
    esac
done

# Image of production (built once: woob takes a few minutes)
if [ ${#BUILD[@]} -gt 0 ] || [ -z "$(docker images -q carbure:dev)" ]; then
    log "Building the image of Carbure (docker/Dockerfile, as in production)"
    compose build carbure
fi

log "Starting MariaDB"
compose up -d --wait db

# Configuration of the instance, as written by the installation wizard in production
case "$WOOB" in
    fake) WOOB_PATH="php /var/www/carbure/tests/fixtures/fake-woob.php" ;;
    real) WOOB_PATH="env HOME=/data/woob woob" ;;
    *) fail "WOOB must be real or fake (or use --fake-woob)" ;;
esac
log "Writing /data/conf/prod.ini (woob: $WOOB)"
compose run --rm --no-deps -T --entrypoint sh carbure -c 'mkdir -p /data/conf && cat > /data/conf/prod.ini' <<INI
; Development instance written by start.sh at each start: do not edit
[global]
log_level=debug
development=true
savings_category=Epargne

[database]
db_hostname=db
db_port=3306
db_username=carbure
db_password=dev
db_name=carbure_dev

[cleansing]
regex_label["/FACTURE CARTE DU \d{6}\s*/"]="CB "
regex_label["/\s(CARTE.*|CARTE|CART|CAR|CA|C)$/"]=""
regex_label["/(PRLV SEPA.*)(ECH.*|MDT.*)$/"]="\$1"

[auth]
jwtsecret=dev-only-not-a-real-secret-xxxxxxxxxxxxxxxxxx
password_salt=dev
sync_token=dev-sync-token

[woob]
woob_path="$WOOB_PATH"
woob_transactions=100
woob_logging=error
woob_debug=false
woob_auto_update=false
INI

# Sample data the first time (or with --reset), before the start of Carbure: its
# entrypoint then applies the migrations, as on an existing instance
if ! $RESET && ! compose exec -T db mariadb -ucarbure -pdev carbure_dev -N -e "SHOW TABLES LIKE 'users'" | grep -q .; then
    RESET=true
fi
if $RESET; then
    log "Loading the sample data"
    compose run --rm --no-deps -T --entrypoint php carbure tests/e2e/server/seed.php --demo
fi

log "Starting Carbure"
if ! compose up -d --wait carbure; then
    compose logs --tail 30 carbure >&2
    fail "Carbure did not start (logs above)"
fi

log "Carbure: http://localhost:$PORT/portal/  (admin / admin-password, marie / marie-password)"
log "Logs below; Ctrl+C leaves the stack running, ./start.sh --stop stops it"
compose logs -f carbure
