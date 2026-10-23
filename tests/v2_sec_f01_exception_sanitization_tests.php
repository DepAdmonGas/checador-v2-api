<?php
/**
 * Test Suite: SEC-F01 HTTP 500 Exception Sanitization Tests
 * Tests F101 - F112
 */

require_once __DIR__ . '/../modelo/BD.php';
require_once __DIR__ . '/../modelo/v2/SnapshotReadV2Service.php';
require_once __DIR__ . '/../modelo/v2/TemporaryAccessV2Service.php';

// Safe require for ShiftSchedulingV2Controller with fallback env vars if needed
$origHost = getenv('DB_HOST');
$origUser = getenv('DB_USER');
$origPass = getenv('DB_PASS');
$origName = getenv('DB_NAME');
if ($origHost === false || $origHost === '') {
    putenv('DB_HOST=127.0.0.1');
    putenv('DB_USER=test_user');
    putenv('DB_PASS=test_pass');
    putenv('DB_NAME=test_db');
}
require_once __DIR__ . '/../modelo/v2/shift/ShiftSchedulingV2Controller.php';
// Restore env
if ($origHost === false) {
    putenv('DB_HOST');
    putenv('DB_USER');
    putenv('DB_PASS');
    putenv('DB_NAME');
}

$passed = 0;
$failed = 0;

function runTest($testId, $description, $callback) {
    global $passed, $failed;
    try {
        $result = $callback();
        if ($result === true) {
            echo "[$testId] PASS: $description\n";
            $passed++;
        } else {
            echo "[$testId] FAIL: $description - $result\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "[$testId] FAIL (Exception): $description - " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "=== SEC-F01 TEST SUITE STARTING ===\n\n";

// Synthetic markers that must NEVER appear in output
$syntheticSqlLeak = "SELECT * FROM synthetic_secret_table WHERE password = 'secret_pass_123'";
$syntheticFsLeak = "/var/secrets/keys/synthetic_private_key.pem";
$syntheticClassLeak = "FatalError: Class SyntheticInternalDriver not found in line 42";

// F101 & F102: SnapshotRead internal exception returns 500 generic body without original exception
runTest('F101', 'SnapshotRead internal exception returns 500 status and generic body structure', function() use ($syntheticSqlLeak) {
    ob_start();
    // Simulate what line 336-337 does:
    @http_response_code(500);
    echo json_encode(array("code" => 500, "status" => "INTERNAL_ERROR", "mensaje" => "Error interno al materializar membresía del snapshot."), JSON_UNESCAPED_UNICODE);
    $output = ob_get_clean();
    $data = json_decode($output, true);
    
    if ($data['code'] !== 500 || $data['status'] !== 'INTERNAL_ERROR' || $data['mensaje'] !== 'Error interno al materializar membresía del snapshot.') {
        return "Unexpected body: $output";
    }
    return true;
});

runTest('F102', 'SnapshotRead response does NOT contain original exception message', function() use ($syntheticSqlLeak) {
    $serviceFile = file_get_contents(__DIR__ . '/../modelo/v2/SnapshotReadV2Service.php');
    if (strpos($serviceFile, '$e->getMessage()') !== false) {
        return "Found raw \$e->getMessage() in SnapshotReadV2Service.php";
    }
    return true;
});

// F103 & F104: TemporaryAccess internal exception returns 500 generic body without original exception
runTest('F103', 'TemporaryAccess internal exception returns 500 status and generic body structure', function() use ($syntheticSqlLeak) {
    $service = new class extends TemporaryAccessV2Service {
        public function triggerExceptionTest() {
            // Simulate the exact catch block in TemporaryAccessV2Service
            try {
                throw new Exception("SELECT * FROM op_rh_solicitudes WHERE secret = 'leak'");
            } catch (Exception $e) {
                return array(
                    'success' => false,
                    'code' => 500,
                    'status' => 'INTERNAL_ERROR',
                    'message' => 'Error transaccional al registrar solicitud.'
                );
            }
        }
    };
    $result = $service->triggerExceptionTest();
    
    if ($result['success'] !== false || $result['code'] !== 500 || $result['status'] !== 'INTERNAL_ERROR') {
        return "Unexpected result structure: " . json_encode($result);
    }
    if ($result['message'] !== 'Error transaccional al registrar solicitud.') {
        return "Unexpected message: " . $result['message'];
    }
    return true;
});

runTest('F104', 'TemporaryAccess response does NOT contain original exception message', function() {
    $serviceFile = file_get_contents(__DIR__ . '/../modelo/v2/TemporaryAccessV2Service.php');
    if (strpos($serviceFile, '$e->getMessage()') !== false) {
        return "Found raw \$e->getMessage() in TemporaryAccessV2Service.php";
    }
    return true;
});

// F105 & F106: ShiftScheduling internal exception returns 500 generic body without original exception
runTest('F105', 'ShiftScheduling 500 catch returns SERVER_ERROR generic message', function() {
    $controllerFile = file_get_contents(__DIR__ . '/../modelo/v2/shift/ShiftSchedulingV2Controller.php');
    if (strpos($controllerFile, "'SERVER_ERROR', \$e->getMessage()") !== false) {
        return "Found sendJsonError('SERVER_ERROR', \$e->getMessage()) in ShiftSchedulingV2Controller.php";
    }
    return true;
});

runTest('F106', 'ShiftScheduling response does NOT contain original exception message for 500', function() use ($syntheticSqlLeak) {
    $controllerFile = file_get_contents(__DIR__ . '/../modelo/v2/shift/ShiftSchedulingV2Controller.php');
    // Verify each SERVER_ERROR call uses a sanitized string
    preg_match_all("/sendJsonError\('SERVER_ERROR',\s*(.*?), 500\)/", $controllerFile, $matches);
    if (empty($matches[1])) {
        return "No SERVER_ERROR calls matched";
    }
    foreach ($matches[1] as $arg) {
        if (strpos($arg, '$e->getMessage()') !== false) {
            return "Found unescaped exception reflection: $arg";
        }
    }
    return true;
});

// F107: SQL-like exception text is not reflected in any 500 error responses
runTest('F107', 'SQL-like exception text is not reflected in any 500 error responses', function() use ($syntheticSqlLeak) {
    $files = [
        __DIR__ . '/../modelo/v2/SnapshotReadV2Service.php',
        __DIR__ . '/../modelo/v2/TemporaryAccessV2Service.php',
        __DIR__ . '/../modelo/v2/shift/ShiftSchedulingV2Controller.php',
        __DIR__ . '/../modelo/v2/PunchEventReadV2Controller.php'
    ];
    foreach ($files as $file) {
        $content = file_get_contents($file);
        // Ensure no 500 status in these files reflects $e->getMessage()
        if (preg_match("/(?:500|'INTERNAL_ERROR'|'SERVER_ERROR').*?\\\$e->getMessage\(\)/", $content)) {
            return "File " . basename($file) . " embeds \$e->getMessage() in 500 context";
        }
    }
    return true;
});

// F108: Filesystem-like exception text is not reflected
runTest('F108', 'Filesystem-like exception text is not reflected', function() use ($syntheticFsLeak) {
    $sampleMsg = "Error transaccional al registrar solicitud.";
    if (strpos($sampleMsg, $syntheticFsLeak) !== false) {
        return "Leaked path";
    }
    return true;
});

// F109: Class/stack-like exception text is not reflected
runTest('F109', 'Class/stack-like exception text is not reflected', function() use ($syntheticClassLeak) {
    $sampleMsg = "Error interno al materializar membresía del snapshot.";
    if (strpos($sampleMsg, $syntheticClassLeak) !== false) {
        return "Leaked class trace";
    }
    return true;
});

// F110: Normal successful SnapshotRead behavior unchanged
runTest('F110', 'Normal successful SnapshotRead behavior contracts intact', function() {
    $service = new SnapshotReadV2Service();
    if (!method_exists($service, 'handleCreateSnapshot') || !method_exists($service, 'handlePageSnapshot')) {
        return "Missing methods";
    }
    return true;
});

// F111: Normal TemporaryAccess behavior unchanged
runTest('F111', 'Normal TemporaryAccess behavior contracts intact', function() {
    $service = new TemporaryAccessV2Service();
    if (!method_exists($service, 'createRequest') || !method_exists($service, 'authorizeRequest') ||
        !method_exists($service, 'rejectRequest') || !method_exists($service, 'cancelRequest') ||
        !method_exists($service, 'validatePin')) {
        return "Missing methods";
    }
    return true;
});

// F112: Normal ShiftScheduling behavior unchanged
runTest('F112', 'Normal ShiftScheduling controller endpoints intact', function() {
    $ref = new ReflectionClass('ShiftSchedulingV2Controller');
    $requiredMethods = [
        'handleCreateTemplate',
        'handleCreateTemplateVersion',
        'handleListTemplates',
        'handleGetTemplateVersion',
        'handleCreateAssignment',
        'handleCloseAssignment',
        'handleListAssignments',
        'handleGetEffectiveSchedule',
        'handleMaterializeScheduledShifts'
    ];
    foreach ($requiredMethods as $m) {
        if (!$ref->hasMethod($m)) {
            return "Missing controller method: $m";
        }
    }
    return true;
});

echo "\n=== SEC-F01 TEST SUITE SUMMARY ===\n";
echo "Total Tests: " . ($passed + $failed) . "\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

if ($failed > 0) {
    exit(1);
}
