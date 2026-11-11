#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONTAINER_NAME="checador-v2-it-$(date +%s)-$$"
DATABASE_NAME="bd_admongas_v2_test_20261002"
DATABASE_PORT="13307"
HTTP_PORT="18089"
DATABASE_PASSWORD="$(openssl rand -hex 24)"
TEMP_DIR="$(mktemp -d)"
SERVER_PID=""
CONTAINER_STARTED="false"

cleanup() {
    if [[ -n "$SERVER_PID" ]]; then
        kill "$SERVER_PID" >/dev/null 2>&1 || true
        wait "$SERVER_PID" >/dev/null 2>&1 || true
    fi
    if [[ "$CONTAINER_STARTED" == "true" ]]; then
        podman rm -f "$CONTAINER_NAME" >/dev/null 2>&1 || true
    fi
    rm -rf -- "$TEMP_DIR"
}
trap cleanup EXIT INT TERM

for command_name in podman php curl openssl ss; do
    command -v "$command_name" >/dev/null 2>&1 || { echo "BLOCKED: missing required command $command_name"; exit 2; }
done

if ! podman info --format '{{.Host.Security.Rootless}}' | rg -qx 'true'; then
    echo 'BLOCKED: Podman is not running rootless.'
    exit 2
fi
if podman container exists "$CONTAINER_NAME"; then
    echo 'BLOCKED: generated container name already exists.'
    exit 2
fi
if ss -ltn | rg -q ":(${DATABASE_PORT}|${HTTP_PORT})([[:space:]]|$)"; then
    echo 'BLOCKED: a selected loopback test port is already in use.'
    exit 2
fi

echo 'ISOLATED_DATABASE_CREATED: starting a disposable rootless MariaDB container'
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

apply_migration() {
    local migration_path="$1"
    podman exec --interactive --env "MYSQL_PWD=$DATABASE_PASSWORD" "$CONTAINER_NAME" \
        mariadb --protocol=socket --user=root --database="$DATABASE_NAME" < "$migration_path"
}

apply_migration "$ROOT_DIR/migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql"
apply_migration "$ROOT_DIR/migraciones/v2/2026_10_02_000002_add_integrity_seal_to_raw_punches.sql"
apply_migration "$ROOT_DIR/migraciones/v2/2026_10_05_000002_create_v2_level2_server_tables.sql"
apply_migration "$ROOT_DIR/migraciones/v2/2026_10_02_000003_add_raw_punch_immutability_triggers.sql"
podman exec --env "MYSQL_PWD=$DATABASE_PASSWORD" "$CONTAINER_NAME" \
    mariadb --protocol=socket --user=root --database="$DATABASE_NAME" --execute='
CREATE TABLE tb_estaciones (id INT PRIMARY KEY, numlista INT NOT NULL, estatus INT NOT NULL DEFAULT 0) ENGINE=InnoDB;
INSERT INTO tb_estaciones (id, numlista, estatus) VALUES (501, 501, 0), (502, 502, 0);
CREATE TABLE op_rh_localidades (id INT PRIMARY KEY, numlista INT NOT NULL, localidad VARCHAR(80) NOT NULL, recuperacion_vapores INT NOT NULL DEFAULT 0) ENGINE=InnoDB;
INSERT INTO op_rh_localidades (id, numlista, localidad) VALUES (701, 501, "Synthetic Locality A"), (702, 502, "Synthetic Locality B");
CREATE TABLE op_rh_personal (id INT PRIMARY KEY, no_colaborador INT, nombre_completo VARCHAR(100), id_estacion INT) ENGINE=InnoDB;
CREATE TABLE tb_usuarios (id INT PRIMARY KEY, nombre VARCHAR(100), usuario VARCHAR(100), estatus INT, id_gas INT NULL, id_puesto INT NULL) ENGINE=InnoDB;
CREATE TABLE modulos_dpto_operativo (id INT PRIMARY KEY AUTO_INCREMENT, nombre VARCHAR(120) NULL, clave VARCHAR(64) NOT NULL, activo TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB;'

echo 'ISOLATION_VERIFIED: migrations and fixtures will target only the disposable container'
export APP_ENV=testing
export CHECADOR_DEPLOYMENT_PROFILE=testing
export DB_HOST=127.0.0.1
export DB_PORT="$DATABASE_PORT"
export DB_USER=root
export DB_PASS="$DATABASE_PASSWORD"
export DB_NAME="$DATABASE_NAME"
export JWT_SECRET='synthetic-local-jwt-secret-for-read-integration-only'
export RAW_PUNCH_INTEGRITY_MANDATORY=true
export RAW_PUNCH_ACTIVE_KEY_ID=test-key-v1
export RAW_PUNCH_KEY_RING='{"test-key-v1":"synthetic-local-hmac-secret-for-integration-only"}'
export CHECADOR_TEST_DB_EXPECTED_HOSTNAME="$CONTAINER_NAME"

php -S "127.0.0.1:${HTTP_PORT}" -t "$ROOT_DIR" "$ROOT_DIR/index.php" >"$TEMP_DIR/php-server.log" 2>&1 &
SERVER_PID="$!"
export CHECADOR_TEST_API_URL="http://127.0.0.1:${HTTP_PORT}/portal-api-v.1.0.1/api/v2/punch-events"

server_ready='false'
for _ in $(seq 1 30); do
    status_code="$(curl --silent --output /dev/null --write-out '%{http_code}' \
        --request POST --header 'Content-Type: application/json' --data '{}' "$CHECADOR_TEST_API_URL" || true)"
    if [[ "$status_code" == '401' ]]; then
        server_ready='true'
        break
    fi
    sleep 1
done
if [[ "$server_ready" != 'true' ]]; then
    echo 'BLOCKED: local HTTP API did not become ready.'
    tail -40 "$TEMP_DIR/php-server.log" || true
    exit 2
fi
echo 'HTTP_API_STARTED: PASS_REAL'

OVERALL_STATUS=0
php "$ROOT_DIR/tests/v2_real_ingestion_http_integration.php" || OVERALL_STATUS=1

echo 'FOCUSED_PUNCH_READ_TESTS: '
php "$ROOT_DIR/tests/v2_punch_read_api_tests.php" || OVERALL_STATUS=1
php "$ROOT_DIR/tests/v2_punch_read_http_integration.php" || OVERALL_STATUS=1

echo 'PIN_REGRESSION: '
php "$ROOT_DIR/tests/run_v2_temporary_access_tests.php" || OVERALL_STATUS=1

echo 'FOCUSED_STATION_UNIT_TESTS: '
php "$ROOT_DIR/tests/v2_event_time_station_resolution_tests.php" || OVERALL_STATUS=1
echo 'PHP_LINT: '
php -l "$ROOT_DIR/modelo/BD.php" || OVERALL_STATUS=1
php -l "$ROOT_DIR/modelo/v2/IngestaPunchV2Controller.php" || OVERALL_STATUS=1
php -l "$ROOT_DIR/modelo/v2/Level2ServerService.php" || OVERALL_STATUS=1
php -l "$ROOT_DIR/tests/v2_real_ingestion_http_integration.php" || OVERALL_STATUS=1
php -l "$ROOT_DIR/tests/v2_event_time_station_resolution_tests.php" || OVERALL_STATUS=1
php -l "$ROOT_DIR/tests/v2_punch_read_api_tests.php" || OVERALL_STATUS=1
php -l "$ROOT_DIR/tests/v2_punch_read_http_integration.php" || OVERALL_STATUS=1
echo 'GIT_DIFF_CHECK: '
git -C "$ROOT_DIR" diff --check || OVERALL_STATUS=1
echo 'RESULT: isolated HTTP and database integration run finished; disposable resources will now be removed.'
exit "$OVERALL_STATUS"
