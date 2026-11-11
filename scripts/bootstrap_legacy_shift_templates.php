<?php
/**
 * One-time, development-only bootstrap of version-1 shift templates from the
 * approved legacy Interlomas catalog. This file is deliberately not an HTTP
 * entry point and never creates worker assignments.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require_once __DIR__ . '/../modelo/v2/shift/ShiftTemplateService.php';

const CHECADOR_M03_BOOTSTRAP_TASK = 'CHECADOR_V2_M03_AUTHORIZED_TEMPLATE_BOOTSTRAP_EXECUTION_V1';
const CHECADOR_M03_BOOTSTRAP_LOCK = 'checador_v2_m03_legacy_template_bootstrap_v1';

function checadorM03BootstrapParseEnv(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('PORTAL3_DEVELOPMENT_ENV_FILE_UNAVAILABLE');
    }

    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $match)) {
            continue;
        }
        $name = $match[1];
        $value = trim($match[2]);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $quote = $value[0];
            if (strlen($value) < 2 || substr($value, -1) !== $quote) {
                throw new RuntimeException('PORTAL3_ENV_VALUE_MALFORMED');
            }
            $value = substr($value, 1, -1);
            if ($quote === '"') {
                $value = preg_replace_callback('/\\\\([\\\\"nrt$])/', static function (array $m): string {
                    return match ($m[1]) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        default => $m[1],
                    };
                }, $value) ?? $value;
            }
        } else {
            $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            $value = trim($value);
        }
        if (preg_match('/\$\{?[A-Za-z_][A-Za-z0-9_]*\}?/', $value)) {
            throw new RuntimeException('PORTAL3_ENV_VARIABLE_EXPANSION_NOT_SUPPORTED');
        }
        $values[$name] = $value;
    }
    return $values;
}

function checadorM03BootstrapNormalizeTime(string $time): string
{
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $time)) {
        throw new RuntimeException('LEGACY_SOURCE_TIME_INVALID');
    }
    return $time;
}

/** Pure, deterministic projection; exposed for focused isolated tests. */
function checadorM03BootstrapBuildPlan(array $rows, array $locality = ['station_id' => 1, 'numlista' => 2, 'locality_id' => 1, 'locality_name' => 'Interlomas']): array
{
    if ((int) ($locality['station_id'] ?? 0) !== 1 || (int) ($locality['numlista'] ?? 0) !== 2
        || (int) ($locality['locality_id'] ?? 0) !== 1 || strcasecmp((string) ($locality['locality_name'] ?? ''), 'Interlomas') !== 0) {
        throw new RuntimeException('LEGACY_STATION_LOCALITY_MAPPING_UNEXPECTED');
    }
    if (count($rows) !== 17) {
        throw new RuntimeException('LEGACY_SOURCE_ROW_COUNT_UNEXPECTED');
    }

    $excludedTitles = ['VACACIONES', 'INCAPACIDAD'];
    $excluded = [];
    $groups = [];
    $sourceRows = [];
    $overnightCount = 0;

    foreach ($rows as $row) {
        $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT);
        $title = trim((string) ($row['titulo'] ?? ''));
        $stationId = filter_var($row['id_estacion'] ?? 1, FILTER_VALIDATE_INT);
        $start = checadorM03BootstrapNormalizeTime((string) ($row['hora_entrada'] ?? ''));
        $end = checadorM03BootstrapNormalizeTime((string) ($row['hora_salida'] ?? ''));
        if ($id === false || $id < 1 || $title === '' || $stationId !== 1) {
            throw new RuntimeException('LEGACY_SOURCE_ROW_INVALID');
        }

        $normalizedTitle = mb_strtoupper($title, 'UTF-8');
        $sourceProjection = [
            'legacy_id' => (int) $id,
            'station_id' => 1,
            'title' => $title,
            'hora_entrada' => $start,
            'hora_salida' => $end,
        ];
        $sourceProjection['definition_sha256'] = hash('sha256', json_encode($sourceProjection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $sourceRows[] = $sourceProjection;

        if ($start === $end) {
            if (!in_array($normalizedTitle, $excludedTitles, true) || $start !== '00:00:00') {
                throw new RuntimeException('UNCLASSIFIED_ZERO_DURATION_LEGACY_ROW');
            }
            $excluded[] = $sourceProjection;
            continue;
        }
        if (in_array($normalizedTitle, $excludedTitles, true)) {
            throw new RuntimeException('ADMINISTRATIVE_LEGACY_ROW_HAS_WORK_INTERVAL');
        }

        $overnight = strcmp($end, $start) < 0;
        if ($overnight) $overnightCount++;
        $key = 'LEGACY_IL1_' . str_replace(':', '', $start) . '_' . str_replace(':', '', $end);
        if (!isset($groups[$key])) {
            $range = substr($start, 0, 5) . '–' . substr($end, 0, 5);
            $groups[$key] = [
                'clave' => $key,
                'version' => 1,
                'nombre' => '',
                'tipo_turno' => $overnight ? 'NOCTURNO' : 'REGULAR',
                'hora_inicio_local' => $start,
                'hora_fin_local' => $end,
                'dia_offset_inicio' => 0,
                'dia_offset_fin' => $overnight ? 1 : 0,
                'segmentos' => [[
                    'ordinal' => 1,
                    'tipo_segmento' => 'WORK',
                    'hora_inicio_local' => $start,
                    'hora_fin_local' => $end,
                    'dia_offset_inicio' => 0,
                    'dia_offset_fin' => $overnight ? 1 : 0,
                ]],
                'source_rows' => [],
            ];
        } elseif (($groups[$key]['tipo_turno'] === 'NOCTURNO') !== $overnight) {
            throw new RuntimeException('LEGACY_INTERVAL_CLASSIFICATION_CONFLICT');
        }
        $groups[$key]['source_rows'][] = $sourceProjection;
    }

    usort($sourceRows, static fn(array $a, array $b): int => $a['legacy_id'] <=> $b['legacy_id']);
    usort($excluded, static fn(array $a, array $b): int => $a['legacy_id'] <=> $b['legacy_id']);

    if (count($sourceRows) !== 17 || count($excluded) !== 2 || count($groups) !== 12 || $overnightCount !== 2) {
        throw new RuntimeException('LEGACY_CATALOG_EXPECTED_SHAPE_MISMATCH');
    }
    $excludedSeen = array_count_values(array_map(static fn(array $row): string => mb_strtoupper($row['title'], 'UTF-8'), $excluded));
    if (($excludedSeen['VACACIONES'] ?? 0) !== 1 || ($excludedSeen['INCAPACIDAD'] ?? 0) !== 1) {
        throw new RuntimeException('LEGACY_EXCLUSIONS_UNEXPECTED');
    }

    ksort($groups, SORT_STRING);
    foreach ($groups as &$definition) {
        usort($definition['source_rows'], static fn(array $a, array $b): int => $a['legacy_id'] <=> $b['legacy_id']);
        $titles = array_values(array_unique(array_map(static fn(array $row): string => $row['title'], $definition['source_rows'])));
        $definition['nombre'] = 'Legacy Interlomas — ' . implode(' / ', $titles) . ' — '
            . substr($definition['hora_inicio_local'], 0, 5) . '–' . substr($definition['hora_fin_local'], 0, 5);
        if (function_exists('mb_strlen') ? mb_strlen($definition['nombre'], 'UTF-8') > 128 : strlen($definition['nombre']) > 128) {
            throw new RuntimeException('GENERATED_TEMPLATE_NAME_TOO_LONG');
        }
        foreach ($definition['source_rows'] as $index => $sourceRow) {
            $definition['source_rows'][$index]['template_key'] = $definition['clave'];
            $definition['source_rows'][$index]['template_version'] = 1;
        }
        $definition['source_hashes'] = array_values(array_map(static fn(array $row): string => $row['definition_sha256'], $definition['source_rows']));
    }
    unset($definition);

    return [
        'source_rows' => $sourceRows,
        'excluded_rows' => $excluded,
        'definitions' => array_values($groups),
        'counts' => ['source_rows' => 17, 'valid_intervals' => 15, 'excluded_rows' => 2, 'unique_definitions' => 12, 'overnight_definitions' => 2],
    ];
}

function checadorM03BootstrapReadExisting(mysqli $db, string $key): array
{
    $stmt = $db->prepare('SELECT id, clave, version, nombre, tipo_turno, es_activo FROM op_rh_v2_turnos_plantillas WHERE clave = ? ORDER BY version');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $templates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if ($templates === []) return ['status' => 'absent'];
    if (count($templates) !== 1 || (int) $templates[0]['version'] !== 1) {
        return ['status' => 'conflict', 'reason' => 'VERSION_SET_MISMATCH'];
    }
    $template = $templates[0];
    $template['id'] = (int) $template['id'];
    $template['version'] = (int) $template['version'];
    $template['es_activo'] = (int) $template['es_activo'];
    $segmentStmt = $db->prepare('SELECT id, ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin FROM op_rh_v2_turnos_segmentos WHERE id_plantilla = ? ORDER BY ordinal');
    $segmentStmt->bind_param('i', $template['id']);
    $segmentStmt->execute();
    $segments = $segmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($segments as &$segment) {
        foreach (['id', 'ordinal', 'dia_offset_inicio', 'dia_offset_fin'] as $integerField) $segment[$integerField] = (int) $segment[$integerField];
    }
    unset($segment);
    return ['status' => 'present', 'template' => $template, 'segments' => $segments];
}

function checadorM03BootstrapDefinitionMatches(array $existing, array $expected): bool
{
    if (($existing['status'] ?? '') !== 'present') return false;
    $template = $existing['template'];
    if ((string) $template['clave'] !== (string) $expected['clave'] || (int) $template['version'] !== 1
        || (string) $template['nombre'] !== (string) $expected['nombre']
        || (string) $template['tipo_turno'] !== (string) $expected['tipo_turno'] || (int) $template['es_activo'] !== 1) {
        return false;
    }
    $expectedSegments = $expected['segmentos'];
    $actualSegments = $existing['segments'] ?? [];
    if (count($actualSegments) !== count($expectedSegments)) return false;
    foreach ($expectedSegments as $index => $expectedSegment) {
        $actual = $actualSegments[$index] ?? [];
        foreach (['ordinal', 'tipo_segmento', 'hora_inicio_local', 'hora_fin_local', 'dia_offset_inicio', 'dia_offset_fin'] as $field) {
            $left = in_array($field, ['ordinal', 'dia_offset_inicio', 'dia_offset_fin'], true) ? (int) ($actual[$field] ?? -1) : (string) ($actual[$field] ?? '');
            $right = in_array($field, ['ordinal', 'dia_offset_inicio', 'dia_offset_fin'], true) ? (int) $expectedSegment[$field] : (string) $expectedSegment[$field];
            if ($left !== $right) return false;
        }
    }
    return true;
}

function checadorM03BootstrapReadManifest(string $path): array
{
    if (!is_file($path)) return ['task_id' => CHECADOR_M03_BOOTSTRAP_TASK, 'definitions' => [], 'pending' => [], 'executions' => []];
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || ($decoded['task_id'] ?? '') !== CHECADOR_M03_BOOTSTRAP_TASK
        || !is_array($decoded['definitions'] ?? null) || !is_array($decoded['executions'] ?? null)
        || !is_array($decoded['pending'] ?? [])) {
        throw new RuntimeException('PROVENANCE_MANIFEST_INVALID');
    }
    return $decoded;
}

function checadorM03BootstrapWriteManifest(string $path, array $manifest): void
{
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) throw new RuntimeException('PROVENANCE_MANIFEST_DIRECTORY_UNAVAILABLE');
    $temporary = $path . '.tmp.' . getmypid();
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($temporary, $json, LOCK_EX) === false) throw new RuntimeException('PROVENANCE_MANIFEST_WRITE_FAILED');
    @chmod($temporary, 0644);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('PROVENANCE_MANIFEST_ATOMIC_RENAME_FAILED');
    }
}

function checadorM03BootstrapIsTruthy(?string $value): bool
{
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function checadorM03BootstrapSourceRefs(array $definition): array
{
    return array_map(static fn(array $sourceRow): array => [
        'legacy_id' => (int) $sourceRow['legacy_id'],
        'title' => (string) $sourceRow['title'],
        'hora_entrada' => (string) $sourceRow['hora_entrada'],
        'hora_salida' => (string) $sourceRow['hora_salida'],
        'definition_sha256' => (string) $sourceRow['definition_sha256'],
    ], $definition['source_rows']);
}

function checadorM03BootstrapOptions(array $argv): array
{
    $options = ['apply' => false, 'database' => null, 'help' => false];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--apply') $options['apply'] = true;
        elseif ($argument === '--help' || $argument === '-h') $options['help'] = true;
        elseif (str_starts_with($argument, '--database=')) $options['database'] = substr($argument, strlen('--database='));
        else throw new RuntimeException('UNKNOWN_COMMAND_OPTION');
    }
    if ($options['database'] !== null && $options['database'] !== 'bd_admongas') throw new RuntimeException('UNAUTHORIZED_DATABASE_TARGET');
    if (!$options['help'] && $options['database'] !== 'bd_admongas') throw new RuntimeException('EXPLICIT_DEVELOPMENT_DATABASE_REQUIRED');
    return $options;
}

function checadorM03BootstrapConnect(): array
{
    $portalRoot = realpath(dirname(__DIR__, 2) . '/Portal3');
    if ($portalRoot === false) throw new RuntimeException('PORTAL3_CHECKOUT_NOT_FOUND');
    $portalEnvPath = $portalRoot . '/.env';
    $config = checadorM03BootstrapParseEnv($portalEnvPath);
    $processEnvironment = getenv('APP_ENV');
    if (($config['APP_ENV'] ?? '') !== 'dev' || ($processEnvironment !== false && $processEnvironment !== 'dev')) {
        throw new RuntimeException('APP_ENV_DEV_REQUIRED');
    }
    if (($config['DB_CONNECTION'] ?? '') !== 'mysql' || ($config['DB_DATABASE'] ?? '') !== 'bd_admongas') {
        throw new RuntimeException('DEVELOPMENT_DATABASE_CONFIGURATION_MISMATCH');
    }
    $host = strtolower(trim((string) ($config['DB_HOST'] ?? '')));
    if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) throw new RuntimeException('NON_LOCAL_DATABASE_HOST_REJECTED');
    foreach (['DB_USERNAME', 'DB_PASSWORD'] as $required) if (!array_key_exists($required, $config)) throw new RuntimeException('DATABASE_CONFIGURATION_INCOMPLETE');
    $port = filter_var($config['DB_PORT'] ?? '3306', FILTER_VALIDATE_INT);
    if ($port === false || $port < 1 || $port > 65535) throw new RuntimeException('DATABASE_PORT_INVALID');
    $globalPostGate = strtolower(trim((string) ($config['CHECADOR_API_REAL_POSTS_ENABLED'] ?? '')));
    if (checadorM03BootstrapIsTruthy($globalPostGate)) {
        throw new RuntimeException('GLOBAL_API_POST_GATE_MUST_REMAIN_DISABLED');
    }
    if (!in_array($globalPostGate, ['', 'false', '0', 'no', 'off'], true)) throw new RuntimeException('GLOBAL_API_POST_GATE_VALUE_INVALID');

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $db = new mysqli($host, $config['DB_USERNAME'], $config['DB_PASSWORD'], 'bd_admongas', (int) $port);
        $db->set_charset('utf8mb4');
    } catch (Throwable) {
        throw new RuntimeException('LOCAL_DEVELOPMENT_DATABASE_CONNECTION_FAILED');
    }
    return [$db, $config, $portalRoot];
}

function checadorM03BootstrapReadOnlyPreflight(mysqli $db, array $config): array
{
    $db->query('SET TRANSACTION READ ONLY');
    $db->begin_transaction();
    try {
        $identity = $db->query('SELECT DATABASE() AS database_name, @@hostname AS server_hostname, @@port AS server_port, @@version AS server_version, @@read_only AS server_read_only')->fetch_assoc();
        if (($identity['database_name'] ?? '') !== 'bd_admongas' || (int) ($identity['server_read_only'] ?? 1) !== 0
            || strcasecmp((string) ($identity['server_hostname'] ?? ''), (string) gethostname()) !== 0
            || (int) ($identity['server_port'] ?? 0) !== (int) ($config['DB_PORT'] ?? 3306)) {
            throw new RuntimeException('LOCAL_MARIADB_IDENTITY_MISMATCH');
        }

        $requiredColumns = [
            'op_rh_localidades_horario' => ['id', 'id_estacion', 'titulo', 'hora_entrada', 'hora_salida'],
            'tb_estaciones' => ['id', 'numlista', 'estatus'],
            'op_rh_localidades' => ['id', 'numlista', 'localidad'],
            'op_rh_v2_turnos_plantillas' => ['id', 'clave', 'version', 'nombre', 'tipo_turno', 'es_activo'],
            'op_rh_v2_turnos_segmentos' => ['id', 'id_plantilla', 'ordinal', 'tipo_segmento', 'hora_inicio_local', 'hora_fin_local', 'dia_offset_inicio', 'dia_offset_fin'],
            'op_rh_v2_turnos_asignaciones' => ['id'],
            'op_rh_v2_marcaciones_crudas' => ['id', 'event_uuid'],
        ];
        $columnRows = $db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()")->fetch_all(MYSQLI_ASSOC);
        $available = [];
        foreach ($columnRows as $column) $available[$column['TABLE_NAME']][$column['COLUMN_NAME']] = true;
        foreach ($requiredColumns as $table => $columns) foreach ($columns as $column) {
            if (!isset($available[$table][$column])) throw new RuntimeException('REQUIRED_DATABASE_SCHEMA_UNAVAILABLE');
        }

        $stationCount = (int) $db->query('SELECT COUNT(*) AS n FROM tb_estaciones WHERE id = 1')->fetch_assoc()['n'];
        $localityCount = (int) $db->query('SELECT COUNT(*) AS n FROM op_rh_localidades WHERE numlista = 2')->fetch_assoc()['n'];
        $mapping = $db->query("SELECT s.id AS station_id, s.numlista, s.estatus, l.id AS locality_id, l.localidad AS locality_name FROM tb_estaciones s JOIN op_rh_localidades l ON l.numlista = s.numlista WHERE s.id = 1")->fetch_all(MYSQLI_ASSOC);
        if ($stationCount !== 1 || $localityCount !== 1 || count($mapping) !== 1
            || (int) $mapping[0]['station_id'] !== 1 || (int) $mapping[0]['numlista'] !== 2
            || (int) $mapping[0]['locality_id'] !== 1 || strcasecmp((string) $mapping[0]['locality_name'], 'Interlomas') !== 0
            || (string) $mapping[0]['estatus'] !== '0') {
            throw new RuntimeException('LEGACY_STATION_LOCALITY_MAPPING_INVALID_OR_INACTIVE');
        }

        $stmt = $db->prepare('SELECT id, id_estacion, titulo, hora_entrada, hora_salida FROM op_rh_localidades_horario WHERE id_estacion = ? ORDER BY id');
        $stationId = 1;
        $stmt->bind_param('i', $stationId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $plan = checadorM03BootstrapBuildPlan($rows, [
            'station_id' => (int) $mapping[0]['station_id'],
            'numlista' => (int) $mapping[0]['numlista'],
            'locality_id' => (int) $mapping[0]['locality_id'],
            'locality_name' => (string) $mapping[0]['locality_name'],
        ]);

        $counts = [];
        foreach ([
            'templates' => 'op_rh_v2_turnos_plantillas',
            'segments' => 'op_rh_v2_turnos_segmentos',
            'assignments' => 'op_rh_v2_turnos_asignaciones',
        ] as $key => $table) $counts[$key] = (int) $db->query('SELECT COUNT(*) AS n FROM `' . $table . '`')->fetch_assoc()['n'];
        $punchCounts = $db->query('SELECT COUNT(*) AS row_count, COUNT(DISTINCT event_uuid) AS uuid_count FROM op_rh_v2_marcaciones_crudas')->fetch_assoc();
        $counts['punch_rows'] = (int) $punchCounts['row_count'];
        $counts['punch_uuids'] = (int) $punchCounts['uuid_count'];
        if ($counts['punch_rows'] !== 89 || $counts['punch_uuids'] !== 89) throw new RuntimeException('CANONICAL_PUNCH_BASELINE_NOT_89');

        return ['identity' => $identity, 'mapping' => $mapping[0], 'plan' => $plan, 'counts' => $counts];
    } finally {
        $db->rollback();
    }
}

function checadorM03BootstrapPlanFingerprint(array $preflight): string
{
    $sourceHashes = array_map(static fn(array $row): string => $row['definition_sha256'], $preflight['plan']['source_rows']);
    return hash('sha256', json_encode(['source_hashes' => $sourceHashes, 'mapping' => $preflight['mapping'], 'counts' => $preflight['counts']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function checadorM03BootstrapApply(mysqli $db, array $preflight, string $manifestPath): array
{
    $manifest = checadorM03BootstrapReadManifest($manifestPath);
    $manifest['pending'] ??= [];
    if (isset($manifest['source']['physical_station_id']) && ((int) $manifest['source']['physical_station_id'] !== 1
        || (int) ($manifest['source']['canonical_locality_id'] ?? 0) !== 1
        || (int) ($manifest['source']['numlista'] ?? 0) !== 2)) {
        throw new RuntimeException('PROVENANCE_SOURCE_MAPPING_CHANGED');
    }
    if (isset($manifest['source']['source_rows_sha256'])
        && $manifest['source']['source_rows_sha256'] !== array_values(array_map(static fn(array $row): string => $row['definition_sha256'], $preflight['plan']['source_rows']))) {
        throw new RuntimeException('PROVENANCE_SOURCE_CATALOG_CHANGED');
    }

    $run = [
        'execution_id' => gmdate('Ymd\THis\Z') . '-' . getmypid() . '-' . bin2hex(random_bytes(3)),
        'started_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'mode' => 'apply',
        'created' => 0,
        'matched_exact' => 0,
        'conflicts' => [],
    ];
    $createdSegmentCount = 0;
    $service = new ShiftTemplateService($db);
    foreach ($preflight['plan']['definitions'] as $definition) {
        $key = $definition['clave'];
        $existingRecord = checadorM03BootstrapReadExisting($db, $key);
        $manifestRecord = $manifest['definitions'][$key] ?? null;
        $pendingRecord = $manifest['pending'][$key] ?? null;
        if ($pendingRecord !== null && ($pendingRecord['source_hashes'] ?? null) !== $definition['source_hashes']) {
            $run['conflicts'][] = ['key' => $key, 'reason' => 'PENDING_SOURCE_PROVENANCE_CHANGED'];
            continue;
        }
        if ($manifestRecord !== null) {
            $expectedHashes = $definition['source_hashes'];
            if (($manifestRecord['source_hashes'] ?? null) !== $expectedHashes) {
                $run['conflicts'][] = ['key' => $key, 'reason' => 'SOURCE_PROVENANCE_CHANGED'];
                continue;
            }
            if ($existingRecord['status'] === 'absent') {
                $run['conflicts'][] = ['key' => $key, 'reason' => 'MANIFESTED_TEMPLATE_MISSING'];
                continue;
            }
            if ($existingRecord['status'] !== 'present' || !checadorM03BootstrapDefinitionMatches($existingRecord, $definition)
                || (int) ($manifestRecord['template_id'] ?? 0) !== (int) $existingRecord['template']['id']) {
                $run['conflicts'][] = ['key' => $key, 'reason' => 'DATABASE_DEFINITION_DIFFERS_FROM_MANIFEST'];
                continue;
            }
            $run['matched_exact']++;
            continue;
        }

        if ($existingRecord['status'] === 'conflict') {
            $run['conflicts'][] = ['key' => $key, 'reason' => $existingRecord['reason']];
            continue;
        }
        if ($existingRecord['status'] === 'present') {
            if (!checadorM03BootstrapDefinitionMatches($existingRecord, $definition)) {
                $run['conflicts'][] = ['key' => $key, 'reason' => 'EXISTING_DATABASE_DEFINITION_MISMATCH'];
                continue;
            }
            $templateId = (int) $existingRecord['template']['id'];
            $segmentIds = array_map(static fn(array $segment): int => (int) $segment['id'], $existingRecord['segments']);
            $originStatus = $pendingRecord !== null ? 'bootstrap_intent_reconciled_exact_creator_unverified' : 'exact_match_creation_origin_unverified';
            $run['matched_exact']++;
        } else {
            $manifest['schema_version'] = 1;
            $manifest['task_id'] = CHECADOR_M03_BOOTSTRAP_TASK;
            $manifest['definitions'] ??= [];
            $manifest['pending'][$key] = [
                'template_key' => $key,
                'version' => 1,
                'expected_name' => $definition['nombre'],
                'expected_type' => $definition['tipo_turno'],
                'source_hashes' => $definition['source_hashes'],
                'source_rows' => checadorM03BootstrapSourceRefs($definition),
                'intent_recorded_at_utc' => $pendingRecord['intent_recorded_at_utc'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            ];
            checadorM03BootstrapWriteManifest($manifestPath, $manifest);
            try {
                $created = $service->createTemplate([
                    'clave' => $definition['clave'],
                    'nombre' => $definition['nombre'],
                    'tipo_turno' => $definition['tipo_turno'],
                    'segmentos' => $definition['segmentos'],
                ]);
            } catch (Throwable $exception) {
                $afterFailure = checadorM03BootstrapReadExisting($db, $key);
                if ($afterFailure['status'] === 'present' && checadorM03BootstrapDefinitionMatches($afterFailure, $definition)) {
                    $created = $afterFailure['template'];
                    $created['segmentos'] = $afterFailure['segments'];
                } else {
                    $run['conflicts'][] = ['key' => $key, 'reason' => str_contains($exception->getMessage(), 'DUPLICATE_TEMPLATE_VERSION') ? 'CONCURRENT_DUPLICATE' : 'DOMAIN_VALIDATION_OR_PERSISTENCE_REJECTED'];
                    continue;
                }
            }
            $templateId = (int) $created['id'];
            $segmentIds = array_map(static fn(array $segment): int => (int) $segment['id'], $created['segmentos'] ?? []);
            $readBack = checadorM03BootstrapReadExisting($db, $key);
            if (!checadorM03BootstrapDefinitionMatches($readBack, $definition) || (int) ($readBack['template']['id'] ?? 0) !== $templateId) {
                $run['conflicts'][] = ['key' => $key, 'reason' => 'POST_CREATE_READBACK_MISMATCH'];
                continue;
            }
            $originStatus = 'created_by_this_bootstrap';
            $run['created']++;
            $createdSegmentCount += count($segmentIds);
        }

        $manifest['schema_version'] = 1;
        $manifest['task_id'] = CHECADOR_M03_BOOTSTRAP_TASK;
        $manifest['authorization'] = [
            'scope' => 'One development-only CLI bootstrap and idempotent verification reruns; no HTTP or ordinary template administration.',
            'authorized_at' => '2026-11-02',
        ];
        unset($manifest['pending'][$key]);
        $manifest['source'] = [
            'database' => 'bd_admongas',
            'server_hostname' => (string) $preflight['identity']['server_hostname'],
            'table' => 'op_rh_localidades_horario',
            'physical_station_id' => 1,
            'numlista' => 2,
            'canonical_locality_id' => 1,
            'canonical_locality_name' => 'Interlomas',
            'source_row_count' => 17,
            'source_rows_sha256' => array_values(array_map(static fn(array $row): string => $row['definition_sha256'], $preflight['plan']['source_rows'])),
            'excluded_rows' => array_map(static fn(array $row): array => [
                'legacy_id' => (int) $row['legacy_id'],
                'title' => (string) $row['title'],
                'hora_entrada' => (string) $row['hora_entrada'],
                'hora_salida' => (string) $row['hora_salida'],
                'definition_sha256' => (string) $row['definition_sha256'],
                'excluded_reason' => 'Administrative zero-duration legacy record; not a work shift.',
            ], $preflight['plan']['excluded_rows']),
        ];
        $manifest['definitions'][$key] = [
            'template_key' => $key,
            'version' => 1,
            'template_id' => $templateId,
            'name' => $definition['nombre'],
            'shift_type' => $definition['tipo_turno'],
            'segment_ids' => $segmentIds,
            'segments' => $definition['segmentos'],
            'source_hashes' => $definition['source_hashes'],
            'source_rows' => checadorM03BootstrapSourceRefs($definition),
            'origin_status' => $originStatus,
            'recorded_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        checadorM03BootstrapWriteManifest($manifestPath, $manifest);
    }

    $afterTemplates = (int) $db->query('SELECT COUNT(*) AS n FROM op_rh_v2_turnos_plantillas')->fetch_assoc()['n'];
    $afterSegments = (int) $db->query('SELECT COUNT(*) AS n FROM op_rh_v2_turnos_segmentos')->fetch_assoc()['n'];
    $afterAssignments = (int) $db->query('SELECT COUNT(*) AS n FROM op_rh_v2_turnos_asignaciones')->fetch_assoc()['n'];
    $afterPunches = $db->query('SELECT COUNT(*) AS row_count, COUNT(DISTINCT event_uuid) AS uuid_count FROM op_rh_v2_marcaciones_crudas')->fetch_assoc();
    $afterPunchRows = (int) $afterPunches['row_count'];
    $afterPunchUuids = (int) $afterPunches['uuid_count'];
    if ($afterTemplates !== $preflight['counts']['templates'] + $run['created']
        || $afterSegments !== $preflight['counts']['segments'] + $createdSegmentCount
        || $afterAssignments !== $preflight['counts']['assignments']
        || $afterPunchRows !== $preflight['counts']['punch_rows'] || $afterPunchUuids !== $preflight['counts']['punch_uuids']) {
        $run['conflicts'][] = ['key' => '*', 'reason' => 'DATABASE_INVARIANT_CHANGED_DURING_BOOTSTRAP'];
    }

    $run['completed_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
    $run['database_after'] = [
        'templates' => $afterTemplates,
        'segments' => $afterSegments,
        'assignments' => $afterAssignments,
        'raw_punch_rows' => $afterPunchRows,
        'distinct_raw_punch_uuids' => $afterPunchUuids,
    ];
    $manifest['executions'][] = $run;
    checadorM03BootstrapWriteManifest($manifestPath, $manifest);
    return $run;
}

function checadorM03BootstrapMain(array $argv): int
{
    try {
        $options = checadorM03BootstrapOptions($argv);
        if ($options['help']) {
            echo "Usage: APP_ENV=dev php scripts/bootstrap_legacy_shift_templates.php --database=bd_admongas [--apply]\n";
            echo "Default mode is read-only preview; --apply is required to write.\n";
            return 0;
        }
        [$db, $config, $portalRoot] = checadorM03BootstrapConnect();
        $first = checadorM03BootstrapReadOnlyPreflight($db, $config);
        $definitionSummary = array_map(static fn(array $definition): array => [
            'clave' => $definition['clave'],
            'version' => 1,
            'nombre' => $definition['nombre'],
            'tipo_turno' => $definition['tipo_turno'],
            'segmento' => $definition['segmentos'][0],
            'legacy_ids' => array_map(static fn(array $row): int => (int) $row['legacy_id'], $definition['source_rows']),
        ], $first['plan']['definitions']);
        echo json_encode([
            'task' => CHECADOR_M03_BOOTSTRAP_TASK,
            'mode' => $options['apply'] ? 'apply' : 'preview',
            'environment' => 'dev',
            'database' => 'bd_admongas',
            'server_hostname' => $first['identity']['server_hostname'],
            'server_port' => (int) $first['identity']['server_port'],
            'source_mapping' => ['physical_station_id' => 1, 'numlista' => 2, 'canonical_locality_id' => 1, 'canonical_locality_name' => 'Interlomas'],
            'legacy_counts' => $first['plan']['counts'],
            'excluded' => array_map(static fn(array $row): array => ['legacy_id' => $row['legacy_id'], 'title' => $row['title']], $first['plan']['excluded_rows']),
            'database_before' => $first['counts'],
            'definitions' => $definitionSummary,
            'manifest' => 'docs/status/CHECADOR_V2_M03_LEGACY_TEMPLATE_BOOTSTRAP_MANIFEST.json',
            'global_api_posts_gate' => 'disabled',
            'schedule_specific_post_gate' => checadorM03BootstrapIsTruthy($config['CHECADOR_SHIFT_SCHEDULING_POSTS_ENABLED'] ?? null) ? 'enabled' : 'disabled',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

        if (!$options['apply']) {
            $db->close();
            return 0;
        }

        $lockStmt = $db->prepare('SELECT GET_LOCK(?, 0) AS acquired');
        $lockName = CHECADOR_M03_BOOTSTRAP_LOCK;
        $lockStmt->bind_param('s', $lockName);
        $lockStmt->execute();
        if ((int) $lockStmt->get_result()->fetch_assoc()['acquired'] !== 1) throw new RuntimeException('ANOTHER_BOOTSTRAP_IS_RUNNING');
        try {
            $second = checadorM03BootstrapReadOnlyPreflight($db, $config);
            if (checadorM03BootstrapPlanFingerprint($first) !== checadorM03BootstrapPlanFingerprint($second)) {
                throw new RuntimeException('SOURCE_OR_DATABASE_CHANGED_AFTER_PREVIEW; RUN_PREVIEW_AGAIN');
            }
            $manifestPath = dirname(__DIR__) . '/docs/status/CHECADOR_V2_M03_LEGACY_TEMPLATE_BOOTSTRAP_MANIFEST.json';
            $run = checadorM03BootstrapApply($db, $second, $manifestPath);
            echo 'APPLY_RESULT=' . json_encode($run, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            return $run['conflicts'] === [] ? 0 : 2;
        } finally {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->bind_param('s', $lockName);
            $release->execute();
            $db->close();
        }
    } catch (mysqli_sql_exception $exception) {
        fwrite(STDERR, 'ERROR: DATABASE_OPERATION_FAILED (' . $exception->getCode() . '); diagnostic details withheld.' . PHP_EOL);
        return 1;
    } catch (Throwable $exception) {
        $message = preg_match('/^[A-Z0-9_; -]+$/', $exception->getMessage()) ? $exception->getMessage() : 'BOOTSTRAP_FAILED; diagnostic details withheld.';
        fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
        return 1;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(checadorM03BootstrapMain($argv));
}
