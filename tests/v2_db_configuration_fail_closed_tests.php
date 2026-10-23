<?php
/**
 * Isolated Security Tests: DB Configuration Fail-Closed Validation (SEC-A)
 * Tests scenarios DB01 through DB12.
 */

require_once __DIR__ . '/../modelo/BD.php';

$testCount = 0;
$passCount = 0;
$failCount = 0;

function run_test(string $testId, string $description, callable $fn) {
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

// Helper to save and restore environment variables
function with_isolated_env(array $envVars, callable $fn) {
    $keys = ['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'];
    $saved = [];
    foreach ($keys as $k) {
        $val = getenv($k);
        $saved[$k] = ($val === false) ? null : $val;
    }

    // Set or unset according to $envVars
    foreach ($keys as $k) {
        if (array_key_exists($k, $envVars)) {
            if ($envVars[$k] === null) {
                putenv("{$k}");
            } else {
                putenv("{$k}={$envVars[$k]}");
            }
        } else {
            putenv("{$k}");
        }
    }

    try {
        return $fn();
    } finally {
        // Restore
        foreach ($saved as $k => $val) {
            if ($val === null) {
                putenv("{$k}");
            } else {
                putenv("{$k}={$val}");
            }
        }
    }
}

echo "=== INICIANDO PRUEBAS DE SEGURIDAD DB CONFIG FAIL-CLOSED (SEC-A) ===\n\n";

// DB01: All required variables absent -> fails closed with no fallback
run_test("DB01", "All required database configuration variables absent fails closed without fallback", function() {
    return with_isolated_env([], function() {
        $bd = new BD();
        try {
            $bd->conectarBD();
            return false;
        } catch (RuntimeException $e) {
            return strpos($e->getMessage(), 'DB_HOST is required') !== false;
        }
    });
});

// DB02: Missing DB_HOST -> fails closed
run_test("DB02", "Missing DB_HOST fails closed", function() {
    return with_isolated_env([
        'DB_USER' => 'valid_user',
        'DB_PASS' => 'valid_pass',
        'DB_NAME' => 'valid_db'
    ], function() {
        $bd = new BD();
        try {
            $bd->conectarBD();
            return false;
        } catch (RuntimeException $e) {
            return strpos($e->getMessage(), 'DB_HOST is required') !== false;
        }
    });
});

// DB03: Missing DB_USER -> fails closed
run_test("DB03", "Missing DB_USER fails closed", function() {
    return with_isolated_env([
        'DB_HOST' => '127.0.0.1',
        'DB_PASS' => 'valid_pass',
        'DB_NAME' => 'valid_db'
    ], function() {
        $bd = new BD();
        try {
            $bd->conectarBD();
            return false;
        } catch (RuntimeException $e) {
            return strpos($e->getMessage(), 'DB_USER is required') !== false;
        }
    });
});

// DB04: Missing DB_PASS -> fails closed
run_test("DB04", "Missing DB_PASS fails closed", function() {
    return with_isolated_env([
        'DB_HOST' => '127.0.0.1',
        'DB_USER' => 'valid_user',
        'DB_NAME' => 'valid_db'
    ], function() {
        $bd = new BD();
        try {
            $bd->conectarBD();
            return false;
        } catch (RuntimeException $e) {
            return strpos($e->getMessage(), 'DB_PASS is required') !== false;
        }
    });
});

// DB05: Missing DB_NAME -> fails closed
run_test("DB05", "Missing DB_NAME fails closed", function() {
    return with_isolated_env([
        'DB_HOST' => '127.0.0.1',
        'DB_USER' => 'valid_user',
        'DB_PASS' => 'valid_pass'
    ], function() {
        $bd = new BD();
        try {
            $bd->conectarBD();
            return false;
        } catch (RuntimeException $e) {
            return strpos($e->getMessage(), 'DB_NAME is required') !== false;
        }
    });
});

// DB06: Explicit valid configuration accepted and connects or attempts connection with provided values
run_test("DB06", "Explicit valid configuration accepted and attempts connection with configured parameters", function() {
    return with_isolated_env([
        'DB_HOST' => '127.0.0.1',
        'DB_USER' => 'root',
        'DB_PASS' => 'kimera',
        'DB_NAME' => 'bd_admongas_v2_test_20261002'
    ], function() {
        $bd = new BD();
        $con = $bd->conectarBD();
        if ($con instanceof mysqli) {
            $bd->desconectarBD($con);
            return true;
        }
        return false;
    });
});

// DB07: Source code scan: no 'root' string as fallback user in BD.php
run_test("DB07", "Static code scan confirms absence of hardcoded fallback user 'root'", function() {
    $source = file_get_contents(__DIR__ . '/../modelo/BD.php');
    // Ensure 'root' is nowhere in BD.php
    return (stripos($source, 'root') === false);
});

// DB08: Source code scan: no embedded hardcoded fallback password in BD.php
run_test("DB08", "Static code scan confirms absence of hardcoded fallback password", function() {
    $source = file_get_contents(__DIR__ . '/../modelo/BD.php');
    // Ensure neither the known legacy password nor hardcoded fallback strings exist
    $legacyPass = base64_decode('a2ltZXJh');
    return (stripos($source, $legacyPass) === false);
});

// DB09: Source code scan: no hardcoded fallback database name in BD.php
run_test("DB09", "Static code scan confirms absence of fallback database name 'bd_admongas'", function() {
    $source = file_get_contents(__DIR__ . '/../modelo/BD.php');
    return (stripos($source, 'bd_admongas') === false);
});

// DB10: Failure message does not leak DB_PASS
run_test("DB10", "Failure message does not leak secret password or values", function() {
    $secret = "SuperSecretDbPasswordThatMustNeverLeak123!";
    return with_isolated_env([
        'DB_HOST' => '127.0.0.1',
        'DB_USER' => '',
        'DB_PASS' => $secret,
        'DB_NAME' => 'test_db'
    ], function() use ($secret) {
        $bd = new BD();
        try {
            $bd->conectarBD();
            return false;
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            return (strpos($msg, $secret) === false);
        }
    });
});

// DB11: Failure message does not dump environment variables
run_test("DB11", "Failure message does not dump full environment array or dump variables", function() {
    return with_isolated_env([], function() {
        $bd = new BD();
        try {
            $bd->conectarBD();
            return false;
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            // Should be a concise single line error, no var_dump / print_r
            return (strpos($msg, '=>') === false && strlen($msg) < 200);
        }
    });
});

// DB12: Public BD interface (conectarBD, desconectarBD) remains compatible
run_test("DB12", "Public BD interface methods and reflection signatures remain compatible", function() {
    $class = new ReflectionClass('BD');
    if (!$class->hasMethod('conectarBD') || !$class->hasMethod('desconectarBD')) {
        return false;
    }
    $conectar = $class->getMethod('conectarBD');
    $desconectar = $class->getMethod('desconectarBD');

    return ($conectar->isPublic() &&
            $conectar->getNumberOfParameters() === 0 &&
            $desconectar->isPublic() &&
            $desconectar->getNumberOfParameters() === 1);
});

echo "\n=== RESUMEN PRUEBAS DB CONFIG FAIL-CLOSED (SEC-A) ===\n";
echo "TOTAL PRUEBAS : {$testCount}\n";
echo "PASARON       : {$passCount}\n";
echo "FALLARON      : {$failCount}\n";

if ($failCount > 0) {
    exit(1);
}
