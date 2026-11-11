<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/WorkplacePhysicalStationResolver.php';

$host = (string) (getenv('DB_HOST') ?: '');
$user = (string) (getenv('DB_USER') ?: '');
$password = (string) (getenv('DB_PASS') ?: '');
$database = (string) (getenv('DB_NAME') ?: '');
$port = (int) (getenv('DB_PORT') ?: 0);
if ($host !== '127.0.0.1' || $port < 1 || $database !== 'bd_admongas_v2_test_20261026_e2e') {
    fwrite(STDERR, "Refusing to run outside the disposable localhost E2E database.\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($host, $user, $password, $database, $port);
$db->set_charset('utf8mb4');
$identity = $db->query('SELECT DATABASE() AS database_name, @@port AS port, VERSION() AS version')->fetch_assoc();
if (($identity['database_name'] ?? '') !== $database
    || (int) ($identity['port'] ?? 0) !== 3306
    || !str_starts_with((string) ($identity['version'] ?? ''), '11.8.8-MariaDB')) {
    throw new RuntimeException('Disposable MariaDB identity verification failed.');
}

$checks = [];
$assert = static function (string $name, bool $condition) use (&$checks): void {
    $checks[$name] = $condition;
    echo '[' . ($condition ? 'PASS' : 'FAIL') . "] {$name}\n";
};

$db->begin_transaction();
try {
    $numlista = random_int(900000, 1900000000);
    $stationSql = "INSERT INTO tb_estaciones
        (numlista,nombre,es,permisocre,razonsocial,rfc,direccioncompleta,di_estado,di_municipio,
         apoderado_legal,firma,politica,mision,vision,franquicia,producto_uno,producto_dos,
         producto_tres,sasisopa,fecha_autorizacion,organigrama,volumetrico,noregistro_generador,
         categoria,latitud,longitud,distmax,ubicacion,estatus)
        VALUES ({$numlista},'Synthetic station resolver test','','','','','','','',
         '','','','','','','','','','','2000-01-01','','','','',0,0,0,0,0)";
    $db->query($stationSql);
    $physicalStationId = (int) $db->insert_id;
    $locality = $db->prepare('INSERT INTO op_rh_localidades (numlista, localidad, recuperacion_vapores) VALUES (?, ?, ?)');
    $label = 'Synthetic locality resolver test';
    $empty = '';
    $locality->bind_param('iss', $numlista, $label, $empty);
    $locality->execute();
    $localityId = (int) $db->insert_id;

    $resolved = WorkplacePhysicalStationResolver::resolve($db, $localityId);
    $assert('canonical locality maps to a different physical station ID', $localityId !== $physicalStationId && $resolved === $physicalStationId);
    $assert('missing locality fails closed', WorkplacePhysicalStationResolver::resolve($db, 2147483647) === null);

    $db->query("INSERT INTO tb_estaciones
        (numlista,nombre,es,permisocre,razonsocial,rfc,direccioncompleta,di_estado,di_municipio,
         apoderado_legal,firma,politica,mision,vision,franquicia,producto_uno,producto_dos,
         producto_tres,sasisopa,fecha_autorizacion,organigrama,volumetrico,noregistro_generador,
         categoria,latitud,longitud,distmax,ubicacion,estatus)
        VALUES ({$numlista},'Duplicate synthetic station','','','','','','','',
         '','','','','','','','','','','2000-01-01','','','','',0,0,0,0,0)");
    $assert('duplicate physical mapping fails closed', WorkplacePhysicalStationResolver::resolve($db, $localityId) === null);

    $db->rollback();
} catch (Throwable $exception) {
    $db->rollback();
    throw $exception;
}

$failed = count(array_filter($checks, static fn (bool $passed): bool => !$passed));
echo json_encode(['database' => $identity, 'pass' => count($checks) - $failed, 'fail' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit($failed === 0 ? 0 : 1);
