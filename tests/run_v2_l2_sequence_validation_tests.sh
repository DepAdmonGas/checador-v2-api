#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONTAINER_NAME="checador-v2-l2-it-$(date +%s)-$$"
DATABASE_NAME="bd_admongas_v2_test_20261002"
DATABASE_PORT="13317"
DATABASE_PASSWORD="$(openssl rand -hex 24)"
TEMP_DIR="$(mktemp -d)"
CONTAINER_STARTED="false"

cleanup() {
    if [[ "$CONTAINER_STARTED" == "true" ]]; then
        podman rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
    fi
    rm -rf -- "$TEMP_DIR"
}
trap cleanup EXIT INT TERM

for command_name in podman php openssl ss; do
    command -v "$command_name" >/dev/null 2>&1 || { echo "BLOCKED: missing required command $command_name"; exit 2; }
done

php -m | rg -qx 'mysqli' || { echo 'BLOCKED: PHP mysqli extension is unavailable.'; exit 2; }
php -m | rg -qx 'curl' || { echo 'BLOCKED: PHP curl extension is unavailable.'; exit 2; }
if ! podman info --format '{{.Host.Security.Rootless}}' | rg -qx 'true'; then
    echo 'BLOCKED: Podman is not running rootless.'
    exit 2
fi
if podman container exists "$CONTAINER_NAME"; then
    echo 'BLOCKED: generated container name already exists.'
    exit 2
fi
if ss -ltn | rg -q ":(${DATABASE_PORT})([[:space:]]|$)"; then
    echo 'BLOCKED: selected loopback test port is already in use.'
    exit 2
fi

echo 'ISOLATED_DATABASE_CREATED: starting a disposable rootless MariaDB container'
podman run --detach --rm \
    --name "$CONTAINER_NAME" \
    --hostname "$CONTAINER_NAME" \
    --env "MARIADB_ROOT_PASSWORD=$DATABASE_PASSWORD" \
    --env 'MARIADB_ROOT_HOST=%' \
    --env "MARIADB_DATABASE=$DATABASE_NAME" \
    --publish "127.0.0.1:${DATABASE_PORT}:3306" \
    docker.io/library/mariadb:10.11 >/dev/null
CONTAINER_STARTED="true"

ready='false'
for _ in $(seq 1 60); do
    if podman exec --env "MYSQL_PWD=$DATABASE_PASSWORD" "$CONTAINER_NAME" \
        mariadb --protocol=socket --user=root --database="$DATABASE_NAME" --batch --skip-column-names --execute='SELECT 1' \
        >/dev/null 2>&1; then
        ready='true'
        break
    fi
    sleep 1
done
if [[ "$ready" != 'true' ]]; then
    echo 'BLOCKED: disposable MariaDB did not become ready.'
    exit 2
fi

identity="$(podman exec --env "MYSQL_PWD=$DATABASE_PASSWORD" "$CONTAINER_NAME" \
    mariadb --protocol=socket --user=root --database="$DATABASE_NAME" --batch --skip-column-names \
    --execute='SELECT DATABASE(), @@hostname, @@port, VERSION()')"
if [[ "$identity" != "$DATABASE_NAME"$'\t'"$CONTAINER_NAME"$'\t3306\t'*MariaDB* ]]; then
    echo 'BLOCKED: disposable database identity did not match the generated test container.'
    exit 2
fi
echo "TEST_DATABASE_IDENTITY_VERIFIED: $DATABASE_NAME @ $CONTAINER_NAME:3306 (MariaDB)"

export CHECADOR_L2_TEST_DISPOSABLE='1'
export CHECADOR_TEST_DB_EXPECTED_HOSTNAME="$CONTAINER_NAME"
export DB_HOST='127.0.0.1'
export DB_PORT="$DATABASE_PORT"
export DB_USER='root'
export DB_PASS="$DATABASE_PASSWORD"
export DB_NAME="$DATABASE_NAME"

php -l "$ROOT_DIR/modelo/v2/IngestaPunchV2Controller.php"
php -l "$ROOT_DIR/tests/v2_l2_false_success_controller_integration_tests.php"
php "$ROOT_DIR/tests/v2_l2_false_success_controller_integration_tests.php"
