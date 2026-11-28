# R3-B — Procedimiento de runners del Motor en MariaDB aislada

**Estado:** procedimiento de una instancia efímera y sin red; el fixture de membresía se reproduce desde el DDL de Portal3 consultado en modo lectura. Las regresiones y resultados ejecutados en esta intervención se registran en el changelog. No usar una base de desarrollo existente, PREPROD o producción.

## Requisitos del fixture

- Linux con MariaDB server/client, PHP CLI con `mysqli`, `pcntl` y `posix`.
- Directorio aleatorio y privado creado por el operador bajo `/tmp` con modo `0700`.
- `TMPDIR` dentro del fixture; InnoDB puede necesitar temporales y `/var/tmp` puede ser de sólo lectura en el entorno de pruebas.
- Servidor MariaDB dedicado iniciado con `--skip-networking`, `--port=0`, socket y datadir dentro de ese mismo directorio.
- `@@pid_file` dentro del directorio; el PID debe pertenecer al `mariadbd` cuya línea de comandos contiene el datadir, socket y `--skip-networking`.
- Variables explícitas: `CHECADOR_DESTRUCTIVE_TESTS=1`, `JOURNEY_BATCH_TEST_SOCKET=<socket de esta instancia>`. El schema batch debe ser `checador_batch_isolated_v1`; las suites heredadas usan `bd_admongas_v2_test_20261002` a través de `test_bootstrap_guard.php`, que fija `DB_HOST=localhost`, `DB_PORT=3306`, `DB_NAME` y `mysqli.default_socket` antes de invocar `BD.php`.

El helper rechaza otros schemas, hosts TCP, puertos distintos de cero, sockets fuera del patrón/ruta privada, datadirs ajenos, PID files fuera del fixture, procesos sin `--skip-networking` y falta de autorización. El proceso se identifica con el PID file leído desde la conexión y se contrasta con `/proc`; no basta con que un operador suministre un PID o un nombre de base.

## Crear la instancia vacía

Ejemplo para Fedora (sustituir el sufijo por un valor aleatorio; no reutilizar un directorio existente):

```sh
TEST_ROOT="$(mktemp -d /tmp/checador-batch-validation-XXXXXXXX)"
chmod 0700 "$TEST_ROOT"
mkdir -m 0700 "$TEST_ROOT/data"
mkdir -m 0700 "$TEST_ROOT/tmp"
TMPDIR="$TEST_ROOT/tmp" mariadb-install-db --no-defaults --datadir="$TEST_ROOT/data" --auth-root-authentication-method=normal --skip-test-db
mariadbd --no-defaults --user="$(id -un)" --datadir="$TEST_ROOT/data" --tmpdir="$TEST_ROOT/tmp" \
  --socket="$TEST_ROOT/mysql.sock" --pid-file="$TEST_ROOT/mariadb.pid" \
  --skip-networking --port=0 --log-error="$TEST_ROOT/mariadb.log" >"$TEST_ROOT/server.stdout" 2>&1 &
for _ in $(seq 1 100); do test -S "$TEST_ROOT/mysql.sock" && break; sleep 0.1; done
test -S "$TEST_ROOT/mysql.sock"
```

Confirmar desde el socket que `DATABASE()` está vacío, `@@datadir` resuelve a `$TEST_ROOT/data`, `@@socket` es `$TEST_ROOT/mysql.sock`, `@@port=0`, y `@@pid_file`/`/proc/<pid>/cmdline` pertenecen a esa instancia. Crear exclusivamente el schema sintético para el runner correspondiente:

```sh
mariadb --no-defaults --protocol=SOCKET --socket="$TEST_ROOT/mysql.sock" -uroot \
  -e 'CREATE DATABASE checador_batch_isolated_v1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
```

Para suites heredadas, crear en la misma instancia aparte `bd_admongas_v2_test_20261002`; el guard legacy fuerza esa base y el socket local antes de conectar. Nunca pasarle variables de una cuenta/host real.

## DDL existente para los runners T/J1/J2

Importar en este orden en el schema sintético apropiado. No importar propuestas ni SQL de revisión. La única excepción bajo `docs/v2/` es el SQL de referencia M11 V1.1 identificado en el paso 13, solo tras comprobar columnas ausentes y solo dentro de la instancia efímera:

1. `migraciones/bootstrap/preprod_baseline_legacy_schema.sql` (sólo DDL base; el archivo exige base nueva y vacía).
2. Verificar `op_rh_personal.id` y `op_rh_localidades.id`: `INT` signed PRIMARY KEY, InnoDB.
3. Aplicar `tests/fixtures/op_rh_personal_localidad_version.sql`, fixture fiel a la migración Portal3 identificada abajo; no es migración productiva.
4. `migraciones/v2/2026_09_28_000001_create_v2_raw_punch_tables.sql`.
5. `migraciones/v2/2026_10_02_000002_add_integrity_seal_to_raw_punches.sql`.
6. `migraciones/v2/2026_10_02_000003_add_raw_punch_immutability_triggers.sql`.
7. `migraciones/v2/2026_10_05_000002_create_v2_level2_server_tables.sql`.
8. `migraciones/v2/2026_10_06_000001_create_v2_shift_scheduling_tables.sql`.
9. `migraciones/v2/2026_10_12_000001_create_v2_workplace_timezone_authority.sql`.
10. `migraciones/v2/2026_11_03_000001_create_attendance_policy_versions.sql` y `migraciones/v2/2026_11_02_000001_create_v2_shift_scheduling_permission_grants.sql` (prerequisito de grants).
11. `migraciones/v2/2026_11_03_000004_m11_complete_configuration.sql`.
12. `migraciones/v2/2026_11_03_000005_m03_m04_local_templates_and_habitual_temporary_schedules.sql` en el fixture sintético, después de crear las plantillas/asignaciones y `op_rh_v2_permission_grants`. Este addendum agrega `tipo_asignacion`, metadatos de plantillas/asignaciones, el log de turnos y el grant de desarrollo requerido por los runners; su DDL/DML se ejecuta únicamente en el esquema efímero, nunca en una base institucional.
13. Confirmar ausentes los tres parámetros M11 en `information_schema.COLUMNS`; aplicar `docs/v2/CHECADOR_V2_J1_V11_M11_MIGRATION_20261126.sql` exclusivamente en el esquema efímero recién creado para obtener `early_entry_allowed_minutes`, `duplicate_punch_window_minutes` y `exit_priority_window_minutes`. El propio SQL es referencia y no autorización de ejecución en ningún entorno persistente.
14. `migraciones/v2/2026_10_07_000001_create_v2_journey_pairing_tables.sql`.
15. `migraciones/v2/2026_10_07_000002_create_v2_journey_calculation_tables.sql`.

Importar una entrada a la vez y detenerse ante cualquier error; no aplicar rollback ni destruir tablas como recuperación automática de una importación fallida. Los runners incorporan fixtures sintéticos sobre el schema vacío. T11 y T17 requieren cada uno un schema batch sin datos iniciales; ejecutar cada uno en una instancia/schema sintético nuevo, no sobre un schema que otro runner ya pobló.

## Ejecutar con autorización explícita

```sh
export CHECADOR_DESTRUCTIVE_TESTS=1
export JOURNEY_BATCH_TEST_SOCKET="$TEST_ROOT/mysql.sock"
export DB_USER=root
export DB_PASS=''
php tests/test_mariadb_isolation_guard_tests.php
php tests/v2_r3b_membership_fixture_schema_tests.php
php tests/v2_r3b_isolation_guard_negative_integration_tests.php
php tests/v2_journey_batch_t01_mariadb_tests.php
php tests/v2_journey_pairing_runtime_tests.php
php tests/v2_journey_calculation_runtime_tests.php
# T11 requires a separate new TEST_ROOT and empty batch schema; re-import DDL first.
php tests/v2_journey_batch_t11_recovery_mariadb_tests.php
# T17 requires another separate new TEST_ROOT and empty batch schema; re-import DDL first.
php "$PWD/tests/v2_journey_batch_t17_mariadb_tests.php"
```

Cada runner debe terminar con código 0. Guardar cada código y salida. Ejecutar T17 con path absoluto y un `TEST_ROOT` nuevo: su comprobación del hijo compara la línea de comandos del PID con el path canónico del runner y su directorio de checkpoints falla cerrado si ya existía. T17 sólo puede señalizar el proceso hijo que valida contra el runner actual, su cwd y las variables de fixture. T11/T12 sólo pueden matar la conexión cuyo propio `CONNECTION_ID()` se obtuvo y cuya instancia se volvió a verificar por ambos lados.

Las suites de ingesta T09 usan un servidor HTTP de prueba en `127.0.0.1` únicamente; el fixture debe conservar esa dirección de bind y no exponer el servidor a otras interfaces. Ejecutar `php tests/v2_r3b_membership_fixture_schema_tests.php` antes de poblar la tabla para comparar tipos, nulabilidad, precisión, motor/collation, índices/orden, unicidad, FKs/linaje y claves padre. Ejecutar `php tests/v2_r3b_isolation_guard_negative_integration_tests.php` para las seis negativas pendientes; usa solo un segundo schema sintético y un proceso PHP señuelo inofensivo, y comprueba que ambos sigan vivos después del rechazo.

## Detener y retirar el fixture

Antes de limpiar, comprobar con el PID leído desde `$TEST_ROOT/mariadb.pid` que `/proc/<pid>/cmdline` incluye exactamente `$TEST_ROOT/data`, `$TEST_ROOT/mysql.sock` y `--skip-networking`. Terminar sólo ese PID y esperar su salida; después confirmar que el socket desapareció y eliminar sólo el directorio cuyo nombre y realpath coincidan con el `TEST_ROOT` creado por esta ejecución. Para repetir un runner que ya insertó fixtures, usar una instancia efímera nueva en vez de limpiar tablas. No usar `pkill`, patrones globales, `DROP DATABASE` como limpieza rutinaria ni rutas constantes.

## Fuente de la tabla sintética de membresía

`tests/fixtures/op_rh_personal_localidad_version.sql` reproduce el `Schema::create()` de `WorkerWorkplaceAssignmentVersionsMigration::up()` de Portal3, `scripts/migrate_worker_workplace_assignment_versions.php`, referencia `DepAdmonGas/Portal3@d72aaa88f737f2ddd7cea8906b3d54c5ed652c7a`. Conserva engine, charset/collation, columnas y tipos, nulabilidad, DATETIME(6), índices, únicos y FKs worker/workplace/supersedes. No ejecuta ni incorpora el bootstrap de Portal3; en una instancia nueva se verifican las claves padre antes de aplicarlo.

## Alcance de la validación

El guard común se aplica a los runners batch T01/T05/T07/T08/T08B/T09B/T10/T11/T11-recovery/T12-T13/T14A/T14B/T17, coordinación y runtime batch. Las suites J1/J2 heredadas pasan por `test_bootstrap_guard.php` y conectan mediante el socket configurado. Ante cualquier error de instalación, detenerse y preparar una instancia sintética nueva; no cambiar la conexión ni reutilizar `bd_admongas`, PREPROD o producción para salvar el runner.
