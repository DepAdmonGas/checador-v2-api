#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONTAINER_NAME="checador-v2-l2-append-$(date +%s)-$$"
DATABASE_NAME="bd_admongas_v2_test_20261002"
DATABASE_PORT="13308"
HTTP_PORT="18090"
DATABASE_PASSWORD="$(openssl rand -hex 24)"
TEMP_DIR="$(mktemp -d)"
CONTAINER_STARTED="false"
SERVER_PID=""

cleanup() {
    if [[ -n "$SERVER_PID" ]]; then
        kill "$SERVER_PID" >/dev/null 2>&1 || true
        wait "$SERVER_PID" >/dev/null 2>&1 || true
    fi
    if [[ "$CONTAINER_STARTED" == 'true' ]]; then
        podman rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
    fi
    rm -rf -- "$TEMP_DIR"
}
trap cleanup EXIT INT TERM

for command_name in podman php openssl ss curl; do
    command -v "$command_name" >/dev/null 2>&1 || { echo "BLOCKED: missing required command $command_name"; exit 2; }
done

if ! podman info --format '{{.Host.Security.Rootless}}' | rg -qx 'true'; then
    echo 'BLOCKED: Podman is not running rootless.'
    exit 2
fi
if ss -ltn | rg -q ":(${DATABASE_PORT}|${HTTP_PORT})([[:space:]]|$)"; then
    echo 'BLOCKED: a selected loopback test port is already in use.'
    exit 2
fi

mkdir -p "$TEMP_DIR/same" "$TEMP_DIR/conflict"
podman run --detach --rm \
    --name "$CONTAINER_NAME" \
    --hostname "$CONTAINER_NAME" \
    --env "MARIADB_ROOT_PASSWORD=$DATABASE_PASSWORD" \
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

for migration in \
    "$ROOT_DIR/migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql" \
    "$ROOT_DIR/migraciones/v2/2026_10_05_000002_create_v2_level2_server_tables.sql"; do
    podman exec --interactive --env "MYSQL_PWD=$DATABASE_PASSWORD" "$CONTAINER_NAME" \
        mariadb --protocol=socket --user=root --database="$DATABASE_NAME" < "$migration"
done

echo 'ISOLATION_VERIFIED: disposable rootless MariaDB; no bd_admongas or preproduction access'
export DB_HOST=127.0.0.1
export DB_PORT="$DATABASE_PORT"
export DB_USER=root
export DB_PASS="$DATABASE_PASSWORD"
export DB_NAME="$DATABASE_NAME"
export CHECADOR_L2_APPEND_TEST_DIR="$TEMP_DIR"
export CHECADOR_TEST_DB_EXPECTED_HOSTNAME="$CONTAINER_NAME"
export CHECADOR_TEST_TELEMETRY_URL="http://127.0.0.1:${HTTP_PORT}/portal-api-v.1.0.1/api/v2/devices/telemetry"
export APP_ENV=testing
export CHECADOR_DEPLOYMENT_PROFILE=testing
export JWT_SECRET='synthetic-local-jwt-secret-for-level2-telemetry-only'

php -S "127.0.0.1:${HTTP_PORT}" -t "$ROOT_DIR" "$ROOT_DIR/index.php" >"$TEMP_DIR/php-server.log" 2>&1 &
SERVER_PID="$!"
server_ready='false'
for _ in $(seq 1 30); do
    response_code="$(curl --silent --output /dev/null --write-out '%{http_code}' \
        --request POST --header 'Content-Type: application/json' --data '{}' "$CHECADOR_TEST_TELEMETRY_URL" || true)"
    if [[ "$response_code" == '401' ]]; then
        server_ready='true'
        break
    fi
    sleep 1
done
if [[ "$server_ready" != 'true' ]]; then
    echo 'BLOCKED: loopback API did not become ready.'
    tail -40 "$TEMP_DIR/php-server.log" || true
    exit 2
fi

OVERALL_STATUS=0
php "$ROOT_DIR/tests/v2_level2_server_api_tests.php" || OVERALL_STATUS=1
podman exec --interactive --env "MYSQL_PWD=$DATABASE_PASSWORD" "$CONTAINER_NAME" \
    mariadb --protocol=socket --user=root --database="$DATABASE_NAME" \
    < "$ROOT_DIR/migraciones/v2/2026_10_05_000002_create_v2_level2_server_tables.sql"
php "$ROOT_DIR/tests/v2_level2_telemetry_coverage_runtime_tests.php" || OVERALL_STATUS=1
php "$ROOT_DIR/tests/v2_level2_telemetry_append_only_tests.php" || OVERALL_STATUS=1
php -l "$ROOT_DIR/modelo/v2/Level2ServerService.php" || OVERALL_STATUS=1
php -l "$ROOT_DIR/tests/v2_level2_telemetry_append_only_tests.php" || OVERALL_STATUS=1
php -l "$ROOT_DIR/tests/v2_level2_server_api_tests.php" || OVERALL_STATUS=1
php -l "$ROOT_DIR/tests/v2_level2_telemetry_coverage_runtime_tests.php" || OVERALL_STATUS=1
git -C "$ROOT_DIR" diff --check || OVERALL_STATUS=1
echo 'RESULT: append-only Level 2 telemetry checks complete; disposable resources will now be removed.'
exit "$OVERALL_STATUS"
