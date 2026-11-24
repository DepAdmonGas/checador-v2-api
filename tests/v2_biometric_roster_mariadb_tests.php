<?php

declare(strict_types=1);

// Disposable MariaDB integration suite. It refuses all names/hosts except its dedicated localhost DB.
const TEST_DATABASE = 'bd_admongas_v2_biometric_roster_test_20261124';

if (($argv[1] ?? '') === '--probe') {
    $_SERVER['REQUEST_METHOD'] = (string) (getenv('ROSTER_TEST_METHOD') ?: 'GET');
    $_SERVER['REQUEST_URI'] = '/api/v2/devices/station/biometric-roster';
    $_SERVER['HTTP_X_DEVICE_ID'] = (string) (getenv('ROSTER_TEST_DEVICE_ID') ?: '');
    $_SERVER['HTTP_X_CREDENTIAL_KEY'] = (string) (getenv('ROSTER_TEST_CREDENTIAL_KEY') ?: '');
    $_SERVER['HTTP_AUTHORIZATION'] = (string) (getenv('ROSTER_TEST_DEVICE_AUTH') ?: '');
    $_SERVER['HTTP_X_SUPERVISOR_TOKEN'] = (string) (getenv('ROSTER_TEST_SUPERVISOR_TOKEN') ?: '');
    register_shutdown_function(static function (): void {
        fwrite(STDERR, "\n__HTTP_STATUS__:" . (string) http_response_code() . "\n");
    });
    require __DIR__ . '/../index.php';
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = (string) (getenv('DB_HOST') ?: '');
$user = (string) (getenv('DB_USER') ?: '');
$password = (string) (getenv('DB_PASS') ?: '');
$database = (string) (getenv('DB_NAME') ?: '');
$port = (int) (getenv('DB_PORT') ?: 3306);
if (!in_array($host, ['127.0.0.1', 'localhost'], true)
    || $database !== TEST_DATABASE || $user === '' || $port < 1 || $port > 65535) {
    fwrite(STDERR, "Refusing DB test: use localhost and DB_NAME=" . TEST_DATABASE . " only.\n");
    exit(2);
}

$db = new mysqli($host, $user, $password, $database, $port);
$db->set_charset('utf8mb4');
$identity = $db->query('SELECT DATABASE() AS db, CURRENT_USER() AS `current_user`, @@hostname AS host, @@port AS port, VERSION() AS version')->fetch_assoc();
if (($identity['db'] ?? '') !== TEST_DATABASE || (int) ($identity['port'] ?? 0) !== 3306) {
    fwrite(STDERR, "MariaDB identity check failed.\n");
    exit(2);
}
echo 'DATABASE_IDENTITY ' . json_encode([
    'database' => $identity['db'], 'current_user' => $identity['current_user'],
    'host' => $identity['host'], 'external_port' => $port,
    'server_port' => (int) $identity['port'], 'version' => $identity['version'],
], JSON_UNESCAPED_SLASHES) . "\n";

$passed = 0;
$failed = 0;
$assert = static function (bool $ok, string $name) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    $ok ? $passed++ : $failed++;
};
$tables = [
    'op_rh_comodines_dia', 'op_rh_personal_acceso', 'op_rh_personal', 'op_rh_puestos',
    'op_rh_v2_dispositivo_identidad_personal', 'op_rh_v2_dispositivo_credenciales',
    'op_rh_v2_dispositivos', 'tb_modulos_config', 'modulos_usuarios_do',
    'modulos_puestos_do', 'modulos_dpto_operativo', 'tb_multiestacion_usuario',
    'tb_multiestacion_puesto', 'op_rh_localidades', 'tb_estaciones', 'tb_usuarios',
];
$cleanup = static function () use ($db, $tables): void {
    $db->query('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) $db->query("DROP TABLE IF EXISTS `{$table}`");
    $db->query('SET FOREIGN_KEY_CHECKS=1');
};

try {
    // All destructive setup/cleanup is confined to the exact disposable database checked above.
    $cleanup();
    $ddl = [
        'CREATE TABLE tb_usuarios (id INT PRIMARY KEY, usuario VARCHAR(100), id_gas INT, id_puesto INT, estatus INT)',
        'CREATE TABLE modulos_dpto_operativo (id INT PRIMARY KEY, clave VARCHAR(100), activo TINYINT)',
        'CREATE TABLE modulos_usuarios_do (id INT AUTO_INCREMENT PRIMARY KEY, id_modulo INT, id_usuario INT, leer TINYINT DEFAULT 0, crear TINYINT DEFAULT 0, editar TINYINT DEFAULT 0, eliminar TINYINT DEFAULT 0, descargar TINYINT DEFAULT 0)',
        'CREATE TABLE modulos_puestos_do (id INT AUTO_INCREMENT PRIMARY KEY, id_modulo INT, id_puesto INT, leer TINYINT DEFAULT 0, crear TINYINT DEFAULT 0, editar TINYINT DEFAULT 0, eliminar TINYINT DEFAULT 0, descargar TINYINT DEFAULT 0)',
        'CREATE TABLE tb_modulos_config (id INT AUTO_INCREMENT PRIMARY KEY, modulo_key VARCHAR(100), activo TINYINT, estaciones_soportadas TEXT, tipo_departamento VARCHAR(32), tipo VARCHAR(32), allow_all TINYINT DEFAULT 0, departamentos_soportados TEXT NULL)',
        'CREATE TABLE tb_estaciones (id INT PRIMARY KEY, numlista INT, nombre VARCHAR(100), estatus INT)',
        'CREATE TABLE op_rh_localidades (id INT PRIMARY KEY, numlista INT, localidad VARCHAR(100))',
        'CREATE TABLE tb_multiestacion_usuario (id INT AUTO_INCREMENT PRIMARY KEY, id_usuario INT, estaciones TEXT, activo TINYINT)',
        'CREATE TABLE tb_multiestacion_puesto (id INT AUTO_INCREMENT PRIMARY KEY, id_puesto INT, estaciones TEXT, activo TINYINT)',
        'CREATE TABLE op_rh_v2_dispositivos (id INT PRIMARY KEY, uuid_dispositivo VARCHAR(64), id_estacion INT, nombre VARCHAR(100), timezone VARCHAR(64), estado TINYINT)',
        'CREATE TABLE op_rh_v2_dispositivo_credenciales (id INT AUTO_INCREMENT PRIMARY KEY, id_dispositivo INT, credential_key VARCHAR(64), secret_hash VARCHAR(255), estado VARCHAR(16), valido_desde DATETIME, valido_hasta DATETIME NULL, revocado_at DATETIME NULL)',
        'CREATE TABLE op_rh_v2_dispositivo_identidad_personal (id INT AUTO_INCREMENT PRIMARY KEY, id_dispositivo INT, referencia_externa_personal VARCHAR(64), id_personal INT, valido_desde DATETIME, valido_hasta DATETIME NULL, es_activo TINYINT)',
        'CREATE TABLE op_rh_personal (id INT PRIMARY KEY, id_estacion INT, no_colaborador INT, nombre_completo VARCHAR(255), puesto INT, estado INT)',
        'CREATE TABLE op_rh_puestos (id INT PRIMARY KEY, puesto VARCHAR(100))',
        'CREATE TABLE op_rh_personal_acceso (id INT AUTO_INCREMENT PRIMARY KEY, id_personal INT, huella TEXT NULL, pin INT NULL)',
        'CREATE TABLE op_rh_comodines_dia (id INT AUTO_INCREMENT PRIMARY KEY, id_reporte INT, id_usuario INT, id_personal INT, id_estacion INT, dia VARCHAR(16))',
    ];
    foreach ($ddl as $statement) $db->query($statement);

    $db->query("INSERT INTO tb_estaciones VALUES (21,101,'Synthetic North',0),(22,202,'Synthetic South',0)");
    $db->query("INSERT INTO op_rh_localidades VALUES (1001,101,'Synthetic North Locality'),(2002,202,'Synthetic South Locality')");
    $db->query("INSERT INTO modulos_dpto_operativo VALUES (1,'biometricos',1)");
    $db->query("INSERT INTO tb_modulos_config (modulo_key,activo,estaciones_soportadas,tipo_departamento,tipo,allow_all) VALUES ('biometricos',1,'[21,22]','localidades','stations_only',0)");
    $db->query("INSERT INTO tb_usuarios VALUES (10,'native-supervisor',21,6,0),(11,'foreign-supervisor',22,6,0),(12,'acl-denied-supervisor',21,6,0)");
    $db->query("INSERT INTO modulos_usuarios_do (id_modulo,id_usuario,leer) VALUES (1,10,1),(1,11,1),(1,12,0)");
    $db->query("INSERT INTO op_rh_v2_dispositivos VALUES (1,'DEV-ROSTER-1',21,'Synthetic Reader','America/Mexico_City',1),(2,'DEV-ROSTER-2',22,'Synthetic Reader 2','America/Mexico_City',1)");
    $secret = 'synthetic-device-secret';
    $hash = password_hash($secret, PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO op_rh_v2_dispositivo_credenciales (id_dispositivo,credential_key,secret_hash,estado,valido_desde) VALUES (1,'roster-key-1',?,'ACTIVE',UTC_TIMESTAMP()),(2,'roster-key-2',?,'ACTIVE',UTC_TIMESTAMP())");
    $stmt->bind_param('ss', $hash, $hash);
    $stmt->execute();
    $db->query("INSERT INTO op_rh_puestos VALUES (1,'Synthetic Position')");
    $db->query("INSERT INTO op_rh_personal VALUES
        (101,21,1001,'Synthetic Worker No Template',1,1),
        (102,21,1002,'Synthetic Worker With Template',1,1),
        (103,21,1003,'Synthetic Worker Expired Identity',1,1),
        (104,22,1004,'Synthetic Worker Foreign Station',1,1),
        (105,21,1005,'Synthetic Worker Ambiguous Identity',1,1),
        (106,21,1006,'Synthetic Worker Duplicate Access',1,1),
        (107,21,1007,'Synthetic Worker Missing Identity',1,1),
        (108,22,1008,'Synthetic Legacy Wildcard',1,1)");
    $template = base64_encode('synthetic-template-bytes');
    $access = $db->prepare('INSERT INTO op_rh_personal_acceso (id_personal,huella,pin) VALUES (?,?,987654)');
    $access->bind_param('is', $workerId, $template);
    foreach ([102, 106] as $workerId) $access->execute();
    $duplicateTemplate = base64_encode('duplicate-template-must-not-be-used');
    $access->bind_param('is', $duplicateWorkerId, $duplicateTemplate);
    $duplicateWorkerId = 106;
    $access->execute();

    $from = gmdate('Y-m-d H:i:s', time() - 3600);
    $past = gmdate('Y-m-d H:i:s', time() - 60);
    $map = $db->prepare('INSERT INTO op_rh_v2_dispositivo_identidad_personal (id_dispositivo,referencia_externa_personal,id_personal,valido_desde,valido_hasta,es_activo) VALUES (1,?,?,?, ?,1)');
    $map->bind_param('siss', $ref, $workerId, $validFrom, $validTo);
    $validFrom = $from; $validTo = null;
    foreach ([101 => 'reader-ref-101', 102 => 'reader-ref-102', 104 => 'foreign-ref-104', 106 => 'reader-ref-106', 108 => 'legacy-wildcard-ref'] as $workerId => $ref) $map->execute();
    $workerId = 103; $ref = 'expired-ref-103'; $validTo = $past; $map->execute();
    $workerId = 105; $ref = 'ambiguous-ref-105'; $validTo = null; $map->execute();
    $ref = 'second-ambiguous-ref-105'; $map->execute();
    $db->query("INSERT INTO op_rh_v2_dispositivo_identidad_personal (id_dispositivo,referencia_externa_personal,id_personal,valido_desde,es_activo) VALUES (2,'reader-ref-106',106,UTC_TIMESTAMP(),1),(2,'reader-ref-101',104,UTC_TIMESTAMP(),1)");
    $db->query("INSERT INTO op_rh_comodines_dia (id_reporte,id_usuario,id_personal,id_estacion,dia) VALUES (1,108,108,21,CURDATE())");

    putenv('DB_HOST=' . $host);
    putenv('DB_PORT=' . $port);
    putenv('DB_USER=' . $user);
    putenv('DB_PASS=' . $password);
    putenv('DB_NAME=' . $database);
    putenv('JWT_SECRET=synthetic-roster-jwt-signing-key');
    putenv('ROSTER_TEST_DEVICE_ID=1');
    putenv('ROSTER_TEST_CREDENTIAL_KEY=roster-key-1');
    putenv('ROSTER_TEST_DEVICE_AUTH=Bearer ' . $secret);

    $b64 = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $jwt = static function (int $userId, int $expiresAt) use ($b64): string {
        $header = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $b64(json_encode(['iss' => 'portal3_auth', 'exp' => $expiresAt, 'type' => 'access', 'sub' => $userId], JSON_THROW_ON_ERROR));
        $secret = 'synthetic-roster-jwt-signing-key';
        $signature = $b64(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
        return $header . '.' . $payload . '.' . $signature;
    };
    $probe = static function (string $supervisorToken, string $deviceAuth = 'Bearer synthetic-device-secret') use ($assert): array {
        putenv('ROSTER_TEST_SUPERVISOR_TOKEN=' . $supervisorToken);
        putenv('ROSTER_TEST_DEVICE_AUTH=' . $deviceAuth);
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--probe'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, dirname(__DIR__));
        if (!is_resource($process)) throw new RuntimeException('Could not start route probe process.');
        fclose($pipes[0]);
        $body = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        proc_close($process);
        preg_match('/__HTTP_STATUS__:(\d+)/', (string) $stderr, $statusMatch);
        $json = json_decode((string) $body, true);
        return ['http' => (int) ($statusMatch[1] ?? 0), 'json' => $json, 'stderr' => (string) $stderr];
    };

    $good = $probe($jwt(10, time() + 600));
    $assert($good['http'] === 200 && ($good['json']['status'] ?? '') === 'OK', 'authorized device + supervisor + ACL returns HTTP 200');
    $workers = $good['json']['workers'] ?? [];
    $refs = array_column($workers, 'external_worker_reference');
    $assert(count($workers) === 3 && in_array('reader-ref-101', $refs, true) && in_array('reader-ref-102', $refs, true) && in_array('reader-ref-106', $refs, true), 'current native workers with unique identity are included');
    $assert(!in_array('expired-ref-103', $refs, true) && !in_array('ambiguous-ref-105', $refs, true) && !in_array('foreign-ref-104', $refs, true) && !in_array('legacy-wildcard-ref', $refs, true) && !in_array(107, array_column($workers, 'id_personal'), true), 'expired, ambiguous, missing identity, cross-station, and legacy wildcard workers are excluded');
    $assert(($good['json']['total_workers'] ?? null) === 3 && ($good['json']['workers_with_biometrics'] ?? null) === 1, 'catalog counts reflect only unambiguous templates');
    $assert(!str_contains((string) $body = json_encode($good['json']), 'pin') && !str_contains($body, '987654'), 'response excludes legacy PIN field and value');
    $assert(!str_contains($good['stderr'], $template) && str_contains($good['stderr'], 'CHECADOR_V2_BIOMETRIC_ROSTER_READ'), 'success audit contains metadata and no template');

    $missing = $probe('');
    $assert($missing['http'] === 400 && ($missing['json']['status'] ?? '') === 'MISSING_HEADERS', 'missing supervisor token is rejected');
    $badDevice = $probe($jwt(10, time() + 600), 'Bearer invalid-device-secret');
    $assert($badDevice['http'] === 401, 'invalid device secret is rejected');
    $badJwt = $probe('not-a-jwt');
    $assert($badJwt['http'] === 401, 'invalid supervisor JWT is rejected');
    $aclDenied = $probe($jwt(12, time() + 600));
    $assert($aclDenied['http'] === 403, 'missing biometric read grant is rejected');
    $foreignSupervisor = $probe($jwt(11, time() + 600));
    $assert($foreignSupervisor['http'] === 403 && ($foreignSupervisor['json']['status'] ?? '') === 'STATION_JURISDICTION_MISMATCH', 'supervisor from another station is rejected');
    $expiredJwt = $probe($jwt(10, time() - 30));
    $assert($expiredJwt['http'] === 401, 'expired supervisor JWT is rejected');

    $db->query("UPDATE op_rh_v2_dispositivo_credenciales SET revocado_at=UTC_TIMESTAMP() WHERE id_dispositivo=1");
    $revokedCredential = $probe($jwt(10, time() + 600));
    $assert($revokedCredential['http'] === 401, 'revoked device credential is rejected');
    $db->query('UPDATE op_rh_v2_dispositivo_credenciales SET revocado_at=NULL WHERE id_dispositivo=1');
    $db->query('UPDATE op_rh_v2_dispositivos SET estado=0 WHERE id=1');
    $revokedDevice = $probe($jwt(10, time() + 600));
    $assert($revokedDevice['http'] === 401, 'inactive device is rejected');
    $db->query('UPDATE op_rh_v2_dispositivos SET estado=1 WHERE id=1');

    require_once __DIR__ . '/../modelo/v2/PunchEventV2.php';
    require_once __DIR__ . '/../modelo/v2/TemporaryAccessV2Service.php';
    $now = gmdate('Y-m-d H:i:s');
    $punchResolved = (new PunchEventV2())->resolverTrabajadorHistorico($db, 1, 'reader-ref-101', $now);
    $resolver = new ReflectionMethod(TemporaryAccessV2Service::class, 'resolverPersonalId');
    $resolver->setAccessible(true);
    $temporaryResolved = $resolver->invoke(new TemporaryAccessV2Service(), $db, 1, 'reader-ref-101');
    $assert($punchResolved === 101 && $temporaryResolved === 101, 'same device-specific external reference resolves in punch and temporary access');
    $otherDevicePunch = (new PunchEventV2())->resolverTrabajadorHistorico($db, 2, 'reader-ref-101', $now);
    $otherDeviceTemporary = $resolver->invoke(new TemporaryAccessV2Service(), $db, 2, 'reader-ref-101');
    $assert($otherDevicePunch === 104 && $otherDeviceTemporary === 104, 'same external string resolves to a different worker on another device');
    $temporaryExpired = $resolver->invoke(new TemporaryAccessV2Service(), $db, 1, 'expired-ref-103');
    $assert($temporaryExpired === null, 'expired identity cannot resolve for temporary access');

    echo "RESULT: {$passed} PASS / {$failed} FAIL\n";
    $cleanup();
    exit($failed === 0 ? 0 : 1);
} catch (Throwable $exception) {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'MariaDB roster test failed: ' . get_class($exception) . "\n");
    exit(1);
}
