<?php
/**
 * Test Suite: SEC-B Legacy GET Credential Transport Dual-Stack POST Implementation
 * Tests scenarios SB01 through SB15.
 */

putenv("DB_NAME=bd_admongas_v2_test_20261002");
putenv("DB_HOST=127.0.0.1");
putenv("DB_USER=root");
putenv("DB_PASS=kimera");

require_once __DIR__ . '/test_bootstrap_guard.php';
require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/Encriptar.php';

// Pre-load helper functions
@include_once __DIR__ . '/../index.php';

// Mock stream wrapper for php://input
class MockPhpStreamSecB {
    public static $content = '';
    public $position = 0;

    public function stream_open($path, $mode, $options, &$opened_path) {
        $this->position = 0;
        return true;
    }
    public function stream_read($count) {
        $ret = substr(self::$content, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }
    public function stream_write($data) {
        self::$content = $data;
        return strlen($data);
    }
    public function stream_tell() {
        return $this->position;
    }
    public function stream_eof() {
        return $this->position >= strlen(self::$content);
    }
    public function stream_stat() {
        return [];
    }
}

// Register stream wrapper once
stream_wrapper_unregister("php");
stream_wrapper_register("php", "MockPhpStreamSecB");

$testCount = 0;
$passCount = 0;
$failCount = 0;

function run_sec_b_test(string $testId, string $description, callable $fn) {
    global $testCount, $passCount, $failCount;
    $testCount++;
    try {
        $result = $fn();
        if ($result === true) {
            $passCount++;
            echo "[ PASS ] {$testId}: {$description}\n";
        } else {
            $failCount++;
            echo "[ FAIL ] {$testId}: {$description} - Assertion returned false\n";
        }
    } catch (Throwable $e) {
        $failCount++;
        echo "[ FAIL ] {$testId}: {$description} - Uncaught exception: " . $e->getMessage() . "\n";
    }
}

// Helper to execute index.php directly in process
function execute_index_request(string $method, string $uri, array $get = [], array $post = [], ?string $rawBody = null, ?string $contentType = null, ?string $legacyGetFlag = null): array {
    $savedServer = $_SERVER;
    $savedGet = $_GET;
    $savedPost = $_POST;
    $savedLegacyFlag = getenv('LEGACY_ACCESO_EMPRESA_GET_ENABLED');

    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    if ($contentType !== null) {
        $_SERVER['CONTENT_TYPE'] = $contentType;
    } else {
        unset($_SERVER['CONTENT_TYPE']);
    }
    $_GET = $get;
    $_POST = $post;

    if ($legacyGetFlag !== null) {
        putenv("LEGACY_ACCESO_EMPRESA_GET_ENABLED={$legacyGetFlag}");
    } else {
        putenv("LEGACY_ACCESO_EMPRESA_GET_ENABLED");
    }

    if ($rawBody !== null) {
        MockPhpStreamSecB::$content = $rawBody;
    } else {
        MockPhpStreamSecB::$content = '';
    }

    ob_start();
    require __DIR__ . '/../index.php';
    $output = ob_get_clean();

    // Restore env
    $_SERVER = $savedServer;
    $_GET = $savedGet;
    $_POST = $savedPost;
    if ($savedLegacyFlag !== false) {
        putenv("LEGACY_ACCESO_EMPRESA_GET_ENABLED={$savedLegacyFlag}");
    } else {
        putenv("LEGACY_ACCESO_EMPRESA_GET_ENABLED");
    }

    $json = json_decode($output, true);
    return [
        'raw' => $output,
        'json' => $json
    ];
}

echo "=== INICIANDO PRUEBAS DE SEGURIDAD SEC-B ACCESO-EMPRESA TRANSPORT ===\n\n";

// Ensure station 888 test fixture exists
$classBD = new BD();
$conexion = $classBD->conectarBD();
$conexion->query("DELETE FROM op_rh_localidades_perfil WHERE id_estacion = 888");
$conexion->query("DELETE FROM op_rh_localidades WHERE id = 888");
$conexion->query("INSERT INTO op_rh_localidades (id, numlista, localidad, recuperacion_vapores) VALUES (888, 888, 'Estacion Test SEC-B 888', '0')");

$classEncriptar = new Encriptar();
$usrEnc = $classEncriptar->encrypt('secb_user');
$pwdEnc = $classEncriptar->encrypt('secb_pass');
$conexion->query("INSERT INTO op_rh_localidades_perfil (id_estacion, usuario, password, status, token) VALUES (888, '$usrEnc', '$pwdEnc', 1, '')");
$classBD->desconectarBD($conexion);

// SB01: POST JSON valid credentials -> 200, payload parity
run_sec_b_test("SB01", "POST JSON valid credentials returns 200 OK with payload parity", function() {
    $res = execute_index_request(
        'POST',
        '/portal-api-v.1.0.1/acceso-empresa',
        [],
        [],
        json_encode(['usuario' => 'secb_user', 'password' => 'secb_pass']),
        'application/json'
    );
    $data = $res['json']['resultado'] ?? null;
    return ($data !== null &&
            $data['code'] === 200 &&
            $data['status'] === 'OK' &&
            $data['id_estacion'] === 888 &&
            $data['empresa'] === 'Estacion Test SEC-B 888' &&
            !empty($data['token']) &&
            !empty($data['fecha']) &&
            !empty($data['hora']));
});

// SB02: POST form-urlencoded valid credentials -> 200, payload parity
run_sec_b_test("SB02", "POST form-urlencoded valid credentials returns 200 OK with payload parity", function() {
    $body = http_build_query(['usuario' => 'secb_user', 'password' => 'secb_pass']);
    $res = execute_index_request(
        'POST',
        '/portal-api-v.1.0.1/acceso-empresa',
        [],
        ['usuario' => 'secb_user', 'password' => 'secb_pass'],
        $body,
        'application/x-www-form-urlencoded'
    );
    $data = $res['json']['resultado'] ?? null;
    return ($data !== null &&
            $data['code'] === 200 &&
            $data['status'] === 'OK' &&
            $data['id_estacion'] === 888 &&
            !empty($data['token']));
});

// SB03: POST invalid credentials -> 401 NO AUTORIZADO
run_sec_b_test("SB03", "POST invalid credentials returns 401 NO AUTORIZADO", function() {
    $res = execute_index_request(
        'POST',
        '/portal-api-v.1.0.1/acceso-empresa',
        [],
        [],
        json_encode(['usuario' => 'secb_user', 'password' => 'wrong_pass']),
        'application/json'
    );
    $data = $res['json']['resultado'] ?? null;
    return ($data !== null && $data['code'] === 401 && $data['status'] === 'NO AUTORIZADO');
});

// SB04: POST missing usuario -> 204 SIN CONTENIDO
run_sec_b_test("SB04", "POST missing usuario returns 204 SIN CONTENIDO", function() {
    $res = execute_index_request(
        'POST',
        '/portal-api-v.1.0.1/acceso-empresa',
        [],
        [],
        json_encode(['password' => 'secb_pass']),
        'application/json'
    );
    $data = $res['json']['resultado'] ?? null;
    return ($data !== null && $data['code'] === 204 && $data['status'] === 'SIN CONTENIDO');
});

// SB05: POST missing password -> 204 SIN CONTENIDO
run_sec_b_test("SB05", "POST missing password returns 204 SIN CONTENIDO", function() {
    $res = execute_index_request(
        'POST',
        '/portal-api-v.1.0.1/acceso-empresa',
        [],
        [],
        json_encode(['usuario' => 'secb_user']),
        'application/json'
    );
    $data = $res['json']['resultado'] ?? null;
    return ($data !== null && $data['code'] === 204 && $data['status'] === 'SIN CONTENIDO');
});

// SB06: POST malformed JSON -> fails closed, returns 204 without query fallback
run_sec_b_test("SB06", "POST malformed JSON fails closed without query fallback", function() {
    $res = execute_index_request(
        'POST',
        '/portal-api-v.1.0.1/acceso-empresa?usuario=secb_user&password=secb_pass',
        ['usuario' => 'secb_user', 'password' => 'secb_pass'],
        [],
        '{"invalid-json-content',
        'application/json'
    );
    $data = $res['json']['resultado'] ?? null;
    return ($data !== null && $data['code'] === 204 && $data['status'] === 'SIN CONTENIDO');
});

// SB07: POST body missing credentials but query has credentials -> MUST NOT authenticate
run_sec_b_test("SB07", "POST with credentials only in query string fails closed and does not authenticate", function() {
    $res = execute_index_request(
        'POST',
        '/portal-api-v.1.0.1/acceso-empresa?usuario=secb_user&password=secb_pass',
        ['usuario' => 'secb_user', 'password' => 'secb_pass'],
        [],
        '',
        'application/json'
    );
    $data = $res['json']['resultado'] ?? null;
    return ($data !== null && $data['code'] === 204 && $data['status'] === 'SIN CONTENIDO');
});

// SB08: GET explicitly enabled -> legacy behavior preserved
run_sec_b_test("SB08", "GET explicitly enabled preserves legacy login behavior (200 OK)", function() {
    $res = execute_index_request(
        'GET',
        '/portal-api-v.1.0.1/acceso-empresa?usuario=secb_user&password=secb_pass',
        ['usuario' => 'secb_user', 'password' => 'secb_pass'],
        [],
        null,
        null,
        '1' // explicitly enabled
    );
    $data = $res['json']['resultado'] ?? null;
    return ($data !== null &&
            $data['code'] === 200 &&
            $data['status'] === 'OK' &&
            $data['id_estacion'] === 888 &&
            !empty($data['token']));
});

// SB09: GET disabled -> 405 Method Not Allowed with Allow: POST
run_sec_b_test("SB09", "GET explicitly disabled returns 405 METODO NO PERMITIDO", function() {
    $res = execute_index_request(
        'GET',
        '/portal-api-v.1.0.1/acceso-empresa?usuario=secb_user&password=secb_pass',
        ['usuario' => 'secb_user', 'password' => 'secb_pass'],
        [],
        null,
        null,
        '0' // explicitly disabled
    );
    $data = $res['json']['resultado'] ?? null;
    return ($data !== null && $data['code'] === 405 && $data['status'] === 'METODO NO PERMITIDO');
});

// SB10: GET config missing -> disabled / fail-secure (405)
run_sec_b_test("SB10", "GET config missing defaults to fail-secure disabled (405)", function() {
    $res = execute_index_request(
        'GET',
        '/portal-api-v.1.0.1/acceso-empresa?usuario=secb_user&password=secb_pass',
        ['usuario' => 'secb_user', 'password' => 'secb_pass'],
        [],
        null,
        null,
        null // missing flag
    );
    $data = $res['json']['resultado'] ?? null;
    return ($data !== null && $data['code'] === 405 && $data['status'] === 'METODO NO PERMITIDO');
});

// SB11: Unsupported method -> 405 Method Not Allowed
run_sec_b_test("SB11", "Unsupported HTTP method (DELETE) returns 405 METODO NO PERMITIDO", function() {
    $res = execute_index_request(
        'DELETE',
        '/portal-api-v.1.0.1/acceso-empresa',
        [],
        [],
        null,
        null,
        '1'
    );
    $data = $res['json']['resultado'] ?? null;
    return ($data !== null && $data['code'] === 405 && $data['status'] === 'METODO NO PERMITIDO');
});

// SB12: Successful POST response matches GET legacy response schema
run_sec_b_test("SB12", "Successful POST response schema exactly matches GET legacy schema", function() {
    $resPost = execute_index_request(
        'POST',
        '/portal-api-v.1.0.1/acceso-empresa',
        [],
        [],
        json_encode(['usuario' => 'secb_user', 'password' => 'secb_pass']),
        'application/json'
    );
    $resGet = execute_index_request(
        'GET',
        '/portal-api-v.1.0.1/acceso-empresa?usuario=secb_user&password=secb_pass',
        ['usuario' => 'secb_user', 'password' => 'secb_pass'],
        [],
        null,
        null,
        '1'
    );

    $keysPost = array_keys($resPost['json']['resultado'] ?? []);
    $keysGet = array_keys($resGet['json']['resultado'] ?? []);
    sort($keysPost);
    sort($keysGet);

    $expectedKeys = ['code', 'empresa', 'fecha', 'hora', 'id_estacion', 'status', 'token'];
    return ($keysPost === $expectedKeys && $keysPost === $keysGet);
});

// SB13: No password appears in response/error output
run_sec_b_test("SB13", "Password is never leaked in successful or failed response bodies", function() {
    $password = 'SecretNeverToAppearInResponse987!';
    $res = execute_index_request(
        'POST',
        '/portal-api-v.1.0.1/acceso-empresa',
        [],
        [],
        json_encode(['usuario' => 'secb_user', 'password' => $password]),
        'application/json'
    );
    return (strpos($res['raw'], $password) === false);
});

// SB14: No password or query-string logging introduced in index.php
run_sec_b_test("SB14", "Static inspection confirms index.php introduces no password logging", function() {
    $content = file_get_contents(__DIR__ . '/../index.php');
    $hasPassLog = (strpos($content, 'error_log') !== false && strpos($content, 'password') !== false);
    return !$hasPassLog;
});

// SB15: Station alignment regression preserved
run_sec_b_test("SB15", "Station alignment regression preserved in POST response", function() {
    $res = execute_index_request(
        'POST',
        '/portal-api-v.1.0.1/acceso-empresa',
        [],
        [],
        json_encode(['usuario' => 'secb_user', 'password' => 'secb_pass']),
        'application/json'
    );
    $data = $res['json']['resultado'] ?? [];
    return (isset($data['id_estacion']) && $data['id_estacion'] === 888 && $data['empresa'] === 'Estacion Test SEC-B 888');
});

// Clean up test data
$classBD = new BD();
$conexion = $classBD->conectarBD();
$conexion->query("DELETE FROM op_rh_localidades_perfil WHERE id_estacion = 888");
$conexion->query("DELETE FROM op_rh_localidades WHERE id = 888");
$classBD->desconectarBD($conexion);

echo "\n=== RESUMEN PRUEBAS SEC-B ACCESO-EMPRESA TRANSPORT ===\n";
echo "TOTAL PRUEBAS : {$testCount}\n";
echo "PASARON       : {$passCount}\n";
echo "FALLARON      : {$failCount}\n";

if ($failCount > 0) {
    exit(1);
}
