<?php

declare(strict_types=1);

/**
 * HTTP integration checks for GET /api/v2/punch-events.
 * Must run only from run_v2_real_ingestion_integration.sh against its disposable MariaDB/API.
 */

$failures = [];
$checks = 0;
$passes = 0;

$check = static function (string $label, bool $condition, string $detail = '') use (&$failures, &$checks, &$passes): void {
    $checks++;
    if ($condition) {
        $passes++;
        echo "[PASS_REAL] {$label}\n";
        return;
    }
    $failures[] = $label . ($detail === '' ? '' : ': ' . $detail);
    echo "[FAIL] {$label}" . ($detail === '' ? '' : ": {$detail}") . "\n";
};

foreach (['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS', 'DB_NAME', 'CHECADOR_TEST_DB_EXPECTED_HOSTNAME', 'CHECADOR_TEST_API_URL', 'JWT_SECRET'] as $name) {
    if (getenv($name) === false || getenv($name) === '') {
        fwrite(STDERR, "BLOCKED: missing isolated test variable {$name}\n");
        exit(2);
    }
}

$pdo = new PDO(
    'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
    getenv('DB_USER'),
    getenv('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$identity = $pdo->query('SELECT @@hostname AS hostname, @@port AS port, DATABASE() AS database_name')->fetch();
$expectedHost = getenv('CHECADOR_TEST_DB_EXPECTED_HOSTNAME');
$isolated = ($identity['database_name'] ?? '') === 'bd_admongas_v2_test_20261002'
    && str_starts_with((string) ($identity['hostname'] ?? ''), (string) $expectedHost)
    && (int) ($identity['port'] ?? 0) === 3306
    && ($identity['database_name'] ?? '') !== 'bd_admongas';
$check('read API targets the disposable MariaDB instance', $isolated, json_encode($identity, JSON_UNESCAPED_SLASHES));
if (!$isolated) {
    fwrite(STDERR, "BLOCKED: refusing fixture ACL writes outside the disposable test database.\n");
    exit(2);
}

// Synthetic API ACL fixture. Physical station IDs (501/502) intentionally
// differ from canonical locality IDs (701/702).
$pdo->exec("CREATE TABLE IF NOT EXISTS tb_modulos_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    modulo_key VARCHAR(100) NOT NULL,
    tipo VARCHAR(50) DEFAULT 'stations_only',
    estaciones_soportadas LONGTEXT,
    tipo_departamento VARCHAR(50) DEFAULT 'localidades',
    allow_all TINYINT DEFAULT 0,
    activo TINYINT DEFAULT 1
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS modulos_usuarios_do (
    id INT PRIMARY KEY AUTO_INCREMENT,
    id_modulo INT NOT NULL,
    id_usuario INT NOT NULL,
    leer TINYINT DEFAULT 0,
    crear TINYINT DEFAULT 0,
    editar TINYINT DEFAULT 0,
    eliminar TINYINT DEFAULT 0,
    descargar TINYINT DEFAULT 0
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS modulos_puestos_do (
    id INT PRIMARY KEY AUTO_INCREMENT,
    id_modulo INT NOT NULL,
    id_puesto INT NOT NULL,
    leer TINYINT DEFAULT 0,
    crear TINYINT DEFAULT 0,
    editar TINYINT DEFAULT 0,
    eliminar TINYINT DEFAULT 0,
    descargar TINYINT DEFAULT 0
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS tb_multiestacion_usuario (id INT PRIMARY KEY AUTO_INCREMENT, id_usuario INT NOT NULL, estaciones TEXT NULL, activo TINYINT DEFAULT 1) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS tb_multiestacion_puesto (id INT PRIMARY KEY AUTO_INCREMENT, id_puesto INT NOT NULL, estaciones TEXT NULL, activo TINYINT DEFAULT 1) ENGINE=InnoDB");
$pdo->exec("INSERT INTO modulos_dpto_operativo (id, clave, activo) VALUES (20, 'biometricos', 1) ON DUPLICATE KEY UPDATE clave = VALUES(clave), activo = VALUES(activo)");
$pdo->exec("INSERT INTO tb_modulos_config (id, modulo_key, tipo, estaciones_soportadas, tipo_departamento, allow_all, activo)
    VALUES (20, 'biometricos', 'stations_only', '[501,502]', 'localidades', 0, 1)
    ON DUPLICATE KEY UPDATE modulo_key = VALUES(modulo_key), tipo = VALUES(tipo), estaciones_soportadas = VALUES(estaciones_soportadas), tipo_departamento = VALUES(tipo_departamento), allow_all = VALUES(allow_all), activo = VALUES(activo)");

$users = [
    ['id' => 9001, 'name' => 'Synthetic Operations Admin', 'username' => 'synthetic_ops_admin', 'station' => 501, 'position' => 13],
    ['id' => 9002, 'name' => 'Synthetic Station Manager', 'username' => 'synthetic_station_manager', 'station' => 501, 'position' => 6],
];
$userInsert = $pdo->prepare('INSERT INTO tb_usuarios (id, nombre, usuario, estatus, id_gas, id_puesto) VALUES (?, ?, ?, 0, ?, ?) ON DUPLICATE KEY UPDATE estatus = 0, id_gas = VALUES(id_gas), id_puesto = VALUES(id_puesto)');
$grantInsert = $pdo->prepare('INSERT INTO modulos_usuarios_do (id_modulo, id_usuario, leer, crear, editar, eliminar, descargar) VALUES (20, ?, 1, 0, 0, 0, 0)');
foreach ($users as $user) {
    $userInsert->execute([$user['id'], $user['name'], $user['username'], $user['station'], $user['position']]);
    $deleteGrant = $pdo->prepare('DELETE FROM modulos_usuarios_do WHERE id_modulo = 20 AND id_usuario = ?');
    $deleteGrant->execute([$user['id']]);
    $grantInsert->execute([$user['id']]);
}

$base64UrlEncode = static fn(string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
$makeJwt = static function (int $userId) use ($base64UrlEncode): string {
    $secret = (string) getenv('JWT_SECRET');
    $header = $base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $payload = $base64UrlEncode(json_encode([
        'iss' => 'portal3_auth',
        'exp' => time() + 3600,
        'type' => 'access',
        'sub' => $userId,
    ], JSON_THROW_ON_ERROR));
    $signature = $base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
    return $header . '.' . $payload . '.' . $signature;
};
$getPunches = static function (int $userId, int $localityId, string $from, string $to) use ($makeJwt): array {
    $url = (string) getenv('CHECADOR_TEST_API_URL') . '?' . http_build_query([
        'station_id' => $localityId,
        'from' => $from,
        'to' => $to,
        'page' => 1,
        'per_page' => 200,
    ], '', '&', PHP_QUERY_RFC3986);
    $jwt = $makeJwt($userId);
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer {$jwt}\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $body = file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $matches)) $status = (int) $matches[1];
    }
    return ['http' => $status, 'payload' => is_string($body) ? json_decode($body, true) : null, 'body' => $body];
};

$validRow = $pdo->query("SELECT event_uuid, ocurrido_at_utc FROM op_rh_v2_marcaciones_crudas WHERE event_uuid LIKE 'syn-valid-%' ORDER BY id DESC LIMIT 1")->fetch();
$delayedRow = $pdo->query("SELECT event_uuid, ocurrido_at_utc FROM op_rh_v2_marcaciones_crudas WHERE event_uuid LIKE 'syn-delayed-%' ORDER BY id DESC LIMIT 1")->fetch();
$ingestionSummary = $pdo->query("SELECT COUNT(*) AS total, SUM(event_uuid LIKE 'syn-valid-%') AS valid_rows, SUM(event_uuid LIKE 'syn-delayed-%') AS delayed_rows FROM op_rh_v2_marcaciones_crudas")->fetch();
$check('synthetic API ingestion rows exist for read comparison', is_array($validRow) && is_array($delayedRow), json_encode($ingestionSummary));
if (!is_array($validRow) || !is_array($delayedRow)) {
    exit(1);
}

$toBounds = static function (string $utc): array {
    $instant = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return [
        $instant->modify('-10 seconds')->format('Y-m-d\TH:i:s\Z'),
        $instant->modify('+10 seconds')->format('Y-m-d\TH:i:s\Z'),
    ];
};
[$stationBFrom, $stationBTo] = $toBounds((string) $validRow['ocurrido_at_utc']);
$adminRead = $getPunches(9001, 702, $stationBFrom, $stationBTo);
$adminEvents = $adminRead['payload']['data'] ?? [];
$validEvent = null;
foreach ($adminEvents as $event) {
    if (($event['event_uuid'] ?? '') === $validRow['event_uuid']) $validEvent = $event;
}
$check('authorized API HTTP read maps locality 702 to physical station 502', $adminRead['http'] === 200
    && is_array($validEvent)
    && (int) ($validEvent['id_estacion_resuelta'] ?? 0) === 502
    && (int) ($validEvent['id_personal_resuelto'] ?? 0) === 9001
    && (($validEvent['integrity']['status'] ?? '') === 'VALID'), json_encode($adminRead['payload'], JSON_UNESCAPED_SLASHES));
$check('authorized API HTTP read contains no event from another physical station', $adminEvents !== []
    && array_reduce($adminEvents, static fn(bool $ok, array $event): bool => $ok && (int) ($event['id_estacion_resuelta'] ?? 0) === 502, true));

[$stationAFrom, $stationATo] = $toBounds((string) $delayedRow['ocurrido_at_utc']);
$managerOwnStation = $getPunches(9002, 701, $stationAFrom, $stationATo);
$managerCrossStation = $getPunches(9002, 702, $stationBFrom, $stationBTo);
$managerEvents = $managerOwnStation['payload']['data'] ?? [];
$check('station manager can read the event-time assignment for own authorized locality', $managerOwnStation['http'] === 200
    && $managerEvents !== []
    && array_reduce($managerEvents, static fn(bool $ok, array $event): bool => $ok && (int) ($event['id_estacion_resuelta'] ?? 0) === 501, true));
$check('station manager is denied cross-station GET on the server', $managerCrossStation['http'] === 403
    && (($managerCrossStation['payload']['status'] ?? '') === 'STATION_SCOPE_DENIED'));

echo "PUNCH_READ_HTTP_INTEGRATION: {$passes}/{$checks} PASS_REAL\n";
if ($failures !== []) {
    echo 'PUNCH_READ_HTTP_FAILURES: ' . json_encode($failures, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}
