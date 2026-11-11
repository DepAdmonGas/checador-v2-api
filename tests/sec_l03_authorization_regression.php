<?php

require_once __DIR__ . '/../modelo/v2/Portal3JwtAuthHelper.php';

class SecL03FakeResult {
    private $rows;
    private $offset = 0;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function fetch_assoc() { return $this->rows[$this->offset++] ?? null; }
}

class SecL03FakeStatement {
    private $connection;
    private $sql;
    private $params = [];
    private $result;
    public function __construct($connection, string $sql) { $this->connection = $connection; $this->sql = $sql; }
    public function bind_param($types, &...$params) {
        foreach ($params as $param) $this->params[] = $param;
        return true;
    }
    public function execute() { $this->result = new SecL03FakeResult($this->connection->select($this->sql, $this->params)); return true; }
    public function get_result() { return $this->result; }
    public function close() { return true; }
}

class SecL03FakeConnection {
    public $queries = [];
    public $failOn = null;
    public $throwOn = null;
    public $data = [];
    public function prepare($sql) {
        $this->queries[] = $sql;
        if ($this->throwOn !== null && strpos($sql, $this->throwOn) !== false) throw new RuntimeException('Unexpected fixture failure.');
        if ($this->failOn !== null && strpos($sql, $this->failOn) !== false) return false;
        return new SecL03FakeStatement($this, $sql);
    }
    public function select(string $sql, array $params): array {
        $d = $this->data;
        if (strpos($sql, 'FROM tb_usuarios') !== false) {
            foreach ($d['users'] ?? [] as $row) if ((int) $row['id'] === (int) $params[0] && (int) $row['estatus'] === 0) return [$row];
            return [];
        }
        if (strpos($sql, 'FROM modulos_dpto_operativo') !== false) {
            foreach ($d['modules'] ?? [] as $row) if ($row['clave'] === $params[0]) return [$row];
            return [];
        }
        if (strpos($sql, 'FROM modulos_usuarios_do') !== false) {
            if (strpos($sql, 'id_modulo = ?') !== false) {
                foreach ($d['user_grants'] ?? [] as $row) if ((int) $row['id_modulo'] === (int) $params[0] && (int) $row['id_usuario'] === (int) $params[1]) return [$row];
                return [];
            }
            foreach ($d['user_grants'] ?? [] as $row) if ((int) $row['id_usuario'] === (int) $params[0]) return [['id' => 1]];
            return [];
        }
        if (strpos($sql, 'FROM modulos_puestos_do') !== false) {
            foreach ($d['role_grants'] ?? [] as $row) if ((int) $row['id_modulo'] === (int) $params[0] && (int) $row['id_puesto'] === (int) $params[1]) return [$row];
            return [];
        }
        if (strpos($sql, 'FROM tb_modulos_config') !== false) {
            foreach ($d['module_configs'] ?? [] as $row) if ($row['modulo_key'] === $params[0]) return [$row];
            return [];
        }
        if (strpos($sql, 'FROM tb_multiestacion_usuario') !== false) {
            foreach ($d['user_station_configs'] ?? [] as $row) if ((int) $row['id_usuario'] === (int) $params[0] && (int) $row['activo'] === 1) return [$row];
            return [];
        }
        if (strpos($sql, 'FROM tb_multiestacion_puesto') !== false) {
            foreach ($d['role_station_configs'] ?? [] as $row) if ((int) $row['id_puesto'] === (int) $params[0] && (int) $row['activo'] === 1) return [$row];
            return [];
        }
        if (strpos($sql, 'FROM tb_estaciones') !== false) {
            if (strpos($sql, 'numlista = ?') !== false) {
                $matched = [];
                foreach ($d['stations'] ?? [] as $row) {
                    if ((int) ($row['numlista'] ?? 0) === (int) $params[0] && (int) ($row['estatus'] ?? 0) === 0) {
                        $matched[] = $row;
                    }
                }
                if (strpos($sql, 'COUNT(*)') !== false) {
                    return [['cnt' => count($matched)]];
                }
                return $matched;
            }
            foreach ($d['stations'] ?? [] as $row) if ((int) $row['id'] === (int) $params[0] && (int) $row['estatus'] === 0) return [$row];
            return [];
        }
        if (strpos($sql, 'FROM op_rh_localidades') !== false) {
            $matched = [];
            foreach ($d['localities'] ?? [] as $row) {
                if ((int) ($row['numlista'] ?? 0) === (int) $params[0]) {
                    $matched[] = $row;
                }
            }
            if (strpos($sql, 'COUNT(*)') !== false) {
                return [['cnt' => count($matched)]];
            }
            return $matched;
        }
        throw new RuntimeException('Unexpected query in test fixture: ' . $sql);
    }
}

function secL03Token(array $payload, string $secret): string {
    $encode = static function ($value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); };
    $header = $encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $body = $encode(json_encode($payload));
    $signature = $encode(hash_hmac('sha256', $header . '.' . $body, $secret, true));
    return $header . '.' . $body . '.' . $signature;
}

$passes = 0;
$fails = 0;
$assert = static function ($condition, string $message) use (&$passes, &$fails): void {
    if ($condition) { echo "PASS: {$message}\n"; $passes++; }
    else { echo "FAIL: {$message}\n"; $fails++; }
};
$secret = 'sec-l03-fixture-secret-only';
putenv('JWT_SECRET=' . $secret);
$connection = new SecL03FakeConnection();
$connection->data = [
    'users' => [
        ['id' => 7, 'usuario' => 'fixture-user', 'id_gas' => 10, 'id_puesto' => 90, 'estatus' => 0],
        ['id' => 8, 'usuario' => 'inactive-user', 'id_gas' => 10, 'id_puesto' => 90, 'estatus' => 1],
    ],
    'modules' => [['id' => 22, 'clave' => 'acceso-temporal', 'activo' => 1]],
    'module_configs' => [['modulo_key' => 'acceso-temporal', 'activo' => 1, 'tipo_departamento' => 'localidades', 'estaciones_soportadas' => '[10,20,30,40]']],
    'user_grants' => [],
    'role_grants' => [],
    'user_station_configs' => [],
    'role_station_configs' => [],
    'stations' => [
        ['id' => 10, 'numlista' => 500, 'estatus' => 0],
        ['id' => 20, 'numlista' => 501, 'estatus' => 0],
        ['id' => 30, 'numlista' => 502, 'estatus' => 1],
        ['id' => 40, 'numlista' => 503, 'estatus' => 0],
        ['id' => 50, 'numlista' => 504, 'estatus' => 0],
    ],
    'localities' => [
        ['id' => 801, 'numlista' => 500],
        ['id' => 802, 'numlista' => 501],
        ['id' => 803, 'numlista' => 502],
        ['id' => 804, 'numlista' => 503],
        ['id' => 805, 'numlista' => 504],
    ],
];
$basePayload = ['iss' => 'portal3_auth', 'sub' => 7, 'exp' => time() + 600, 'type' => 'access'];
$valid = Portal3JwtAuthHelper::validateToken($connection, 'Bearer ' . secL03Token($basePayload, $secret));
$assert(!empty($valid['valid']) && $valid['user_id'] === 7, 'issuer portal3_auth and estatus=0 authenticate by tb_usuarios.id');
$assert((Portal3JwtAuthHelper::validateToken($connection, 'Bearer ' . secL03Token(array_diff_key($basePayload, ['iss' => true]), $secret))['code'] ?? 0) === 401, 'issuer ausente es rechazado con 401');
$wrongIssuer = $basePayload; $wrongIssuer['iss'] = 'portal3';
$assert((Portal3JwtAuthHelper::validateToken($connection, 'Bearer ' . secL03Token($wrongIssuer, $secret))['code'] ?? 0) === 401, 'issuer incorrecto es rechazado con 401');
$expiredPayload = $basePayload; $expiredPayload['exp'] = time();
$assert((Portal3JwtAuthHelper::validateToken($connection, 'Bearer ' . secL03Token($expiredPayload, $secret))['code'] ?? 0) === 401, 'expiración en el instante actual es rechazada');
$fractionalSub = $basePayload; $fractionalSub['sub'] = 7.2;
$assert((Portal3JwtAuthHelper::validateToken($connection, 'Bearer ' . secL03Token($fractionalSub, $secret))['code'] ?? 0) === 401, 'sub no entero es rechazado sin truncamiento');
$inactivePayload = $basePayload; $inactivePayload['sub'] = 8;
$assert((Portal3JwtAuthHelper::validateToken($connection, 'Bearer ' . secL03Token($inactivePayload, $secret))['code'] ?? 0) === 401, 'estatus=1 se considera usuario inactivo');
$connection->failOn = 'FROM tb_usuarios';
$assert((Portal3JwtAuthHelper::validateToken($connection, 'Bearer ' . secL03Token($basePayload, $secret))['code'] ?? 0) === 503, 'fallo de consulta de usuario falla cerrado sin bypass');
$connection->failOn = null;

$connection->data['user_grants'] = [['id_modulo' => 22, 'id_usuario' => 7, 'leer' => 1, 'crear' => 0, 'editar' => 0, 'eliminar' => 0, 'descargar' => 0]];
$connection->data['role_grants'] = [['id_modulo' => 22, 'id_puesto' => 90, 'leer' => 0, 'crear' => 0, 'editar' => 1, 'eliminar' => 0, 'descargar' => 0]];
$read = Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer');
$assert(!empty($read['success']) && $read['authorized_stations'] === [801], 'leer=1 permite queue sólo en home station convertida a localidad');
$assert(($read['authorized_physical_station_ids'] ?? null) === [10]
    && $read['authorized_stations'] !== $read['authorized_physical_station_ids'], 'PIN scope preserves both ID spaces: canonical locality 801 maps to physical station 10');
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'editar')['code'] ?? 0) === 403, 'sin editar directo no hereda el flag del puesto');
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'crear')['code'] ?? 0) === 403, 'crear=0 y editar=0 deniegan autorización PIN');
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'eliminar')['code'] ?? 0) === 403, 'eliminar=0 y editar=0 deniegan cancelación PIN');
$connection->data['user_grants'] = [['id_modulo' => 22, 'id_usuario' => 7, 'leer' => 1, 'crear' => 1, 'editar' => 0, 'eliminar' => 1, 'descargar' => 0]];
$assert(!empty(Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'crear')['success']), 'crear=1 permite autorizar sin editar');
$assert(!empty(Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'eliminar')['success']), 'eliminar=1 permite cancelar sin editar');
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'editar')['code'] ?? 0) === 403, 'crear/eliminar no implican editar ni rechazo');
$connection->data['user_grants'] = [['id_modulo' => 22, 'id_usuario' => 7, 'leer' => 1, 'crear' => 0, 'editar' => 1, 'eliminar' => 0, 'descargar' => 0]];
$assert(!empty(Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'crear')['success'])
    && !empty(Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'eliminar')['success'])
    && !empty(Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'editar')['success']), 'legacy editar=1 preserves prior authorization, cancellation and rejection behavior');
$assert(!in_array(802, $read['authorized_stations'], true), 'home station scope niega otra localidad');

$connection->data['user_grants'] = [];
$roleAllow = ['id_modulo' => 22, 'id_puesto' => 90, 'leer' => 1, 'crear' => 0, 'editar' => 1, 'eliminar' => 0, 'descargar' => 0];
$connection->data['role_grants'] = [$roleAllow];
$assert(!empty(Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer')['success']), 'sin grant directo el puesto puede conceder leer');
$assert(!empty(Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'editar')['success']), 'editar=1 del puesto permite authorize/reject/cancel');

$connection->data['user_grants'] = [['id_modulo' => 99, 'id_usuario' => 7, 'leer' => 0, 'editar' => 0]];
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer')['code'] ?? 0) === 403, 'grant directo en cualquier módulo desactiva fallback de puesto');
$connection->data['user_grants'] = [['id_modulo' => 22, 'id_usuario' => 7, 'leer' => 1, 'editar' => 1]];
$assert(!empty(Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'editar')['success']), 'grant directo actualizado se observa en el siguiente request');
$connection->data['user_grants'] = [];
$connection->data['role_grants'] = [];
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer')['code'] ?? 0) === 403, 'grant revocado se deniega en el siguiente request');
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'editar')['code'] ?? 0) === 403, 'ausencia de editar produce 403');

$connection->data['role_grants'] = [$roleAllow];
$connection->data['modules'][0]['activo'] = 0;
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer')['code'] ?? 0) === 403, 'módulo funcional inactivo produce 403');
$connection->data['modules'][0]['activo'] = 1;
$connection->data['module_configs'][0]['activo'] = 0;
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer')['code'] ?? 0) === 403, 'configuración de módulo de estaciones inactiva produce 403');
$connection->data['module_configs'][0]['activo'] = 1;
$connection->data['module_configs'] = [];
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer')['code'] ?? 0) === 403, 'configuración de módulo de estaciones inexistente produce 403');
$connection->data['module_configs'] = [['modulo_key' => 'acceso-temporal', 'activo' => 1, 'tipo_departamento' => 'localidades', 'estaciones_soportadas' => '[10,20,30,40]']];
$connection->data['modules'] = [];
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer')['code'] ?? 0) === 403, 'módulo inexistente produce 403');
$connection->data['modules'] = [['id' => 22, 'clave' => 'acceso-temporal', 'activo' => 1]];

$connection->data['role_station_configs'] = [['id_puesto' => 90, 'activo' => 1, 'estaciones' => '[20,40]']];
$multiRole = Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer');
$assert(!empty($multiRole['success']) && $multiRole['authorized_stations'] === [802, 804], 'multiestación por puesto mapea IDs soportados vía numlista');
$connection->data['user_station_configs'] = [['id_usuario' => 7, 'activo' => 1, 'estaciones' => '[10,20]']];
$multiUser = Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer');
$assert(!empty($multiUser['success']) && $multiUser['authorized_stations'] === [801, 802], 'configuración individual prevalece sobre multiestación de puesto');
$connection->data['user_station_configs'] = [];
$connection->data['role_station_configs'] = [['id_puesto' => 90, 'activo' => 1, 'estaciones' => '[30]']];
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer')['code'] ?? 0) === 403, 'estación inactiva no obtiene scope');
$connection->data['role_station_configs'] = [['id_puesto' => 90, 'activo' => 1, 'estaciones' => '[50]']];
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer')['code'] ?? 0) === 403, 'estación no soportada por el módulo no obtiene scope');
$connection->data['role_station_configs'] = [];
$noHome = $valid; $noHome['id_gas'] = null;
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $noHome, 'leer')['code'] ?? 0) === 403, 'ausencia de relación de estación falla cerrado');

$connection->failOn = 'FROM modulos_usuarios_do WHERE id_usuario';
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer')['code'] ?? 0) === 503, 'fallo de consulta ACL produce 503');
$connection->failOn = 'FROM tb_estaciones';
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer')['code'] ?? 0) === 503, 'fallo de consulta de estación produce 503');
$connection->failOn = null;
$connection->throwOn = 'FROM modulos_usuarios_do WHERE id_usuario';
$assert((Portal3JwtAuthHelper::authorizeTemporaryAccess($connection, $valid, 'leer')['code'] ?? 0) === 500, 'fallo interno inesperado se distingue como 500');
$connection->throwOn = null;

$queries = implode("\n", $connection->queries);
$assert(strpos($queries, 'op_rh_personal') === false, 'JWT sub no se resuelve como op_rh_personal.id');
$assert(strpos($queries, 'op_rh_localidades_perfil') === false, 'username legacy no se trata como identidad Portal3');
$controller = file_get_contents(__DIR__ . '/../modelo/v2/TemporaryAccessV2Controller.php');
$temporaryService = file_get_contents(__DIR__ . '/../modelo/v2/TemporaryAccessV2Service.php');
$assert(strpos($controller, "autenticarPortal3User('leer')") !== false, 'GET queue está conectado al permiso leer');
$assert(strpos($controller, "autenticarPortal3User('crear')") !== false
    && strpos($controller, "autenticarPortal3User('editar')") !== false
    && strpos($controller, "autenticarPortal3User('eliminar')") !== false,
    'authorize/reject/cancel usan crear/editar/eliminar de forma independiente');
$assert(substr_count($controller, "\$userAuth['authorized_physical_station_ids'] ?? []") === 4
    && str_contains($temporaryService, "r.id_estacion IN (\$inClause)")
    && str_contains($temporaryService, "in_array(intval(\$row['id_estacion']), \$authorizedStationIds, true)"),
    'Temporary Access filters request rows in physical-device station ID space after canonical locality authorization');
$createMethod = substr($controller, strpos($controller, 'public function handleCreateRequest()'), strpos($controller, 'public function handleListPendingRequests()') - strpos($controller, 'public function handleCreateRequest()'));
$validateMethod = substr($controller, strpos($controller, 'public function handleValidatePin()'), strpos($controller, 'private function autenticarDispositivo()') - strpos($controller, 'public function handleValidatePin()'));
$assert(strpos($createMethod, 'autenticarDispositivo()') !== false && strpos($createMethod, 'autenticarPortal3User') === false, 'Device create conserva Device Auth sin ACL humano');
$assert(strpos($validateMethod, 'autenticarDispositivo()') !== false && strpos($validateMethod, 'autenticarPortal3User') === false, 'Device validate conserva Device Auth sin ACL humano');

printf("SEC_L03_API_RESULT: %d PASS / %d FAIL\n", $passes, $fails);
exit($fails === 0 ? 0 : 1);
