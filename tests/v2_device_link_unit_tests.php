<?php

declare(strict_types=1);
require_once __DIR__ . '/../modelo/v2/DeviceLinkV2Service.php';
require_once __DIR__ . '/../modelo/v2/DeviceLinkRateLimit.php';
$passed=0; $failed=0;
$check = static function(bool $ok, string $label) use (&$passed, &$failed): void { echo ($ok?'PASS ':'FAIL ') . $label . "\n"; if ($ok) $passed++; else $failed++; };
$check(DeviceLinkV2Service::validToken(str_repeat('a',64)), '64 lowercase hexadecimal characters accepted');
foreach (['', str_repeat('a',63), str_repeat('a',65), str_repeat('A',64), str_repeat('g',64), 123, [], null] as $invalid) {
    $check(!DeviceLinkV2Service::validToken($invalid), 'malformed token rejected');
}
$controller = file_get_contents(__DIR__ . '/../modelo/v2/DeviceLinkV2Controller.php');
$service = file_get_contents(__DIR__ . '/../modelo/v2/DeviceLinkV2Service.php');
$router = file_get_contents(__DIR__ . '/../index.php');
$check(str_contains($controller,"'Cache-Control: no-store") && !str_contains($controller,'error_log'), 'link response no-store and no token logging');
$check(str_contains($service, "hash('sha256', \$token)") && str_contains($service, 'PASSWORD_BCRYPT'), 'token SHA-256 and device bcrypt are distinct');
$check(str_contains($router, "getenv('CHECADOR_DEVICE_LINK_ENABLED') === '1'"), 'link route defaults off');
$dir = sys_get_temp_dir() . '/checador_rate_test_' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('TEST_DIRECTORY_FAILED');
try {
    $allowed = true;
    for ($i=0; $i<10; $i++) $allowed = DeviceLinkRateLimit::allow($dir, '192.0.2.1', 1000) && $allowed;
    $check($allowed && !DeviceLinkRateLimit::allow($dir, '192.0.2.1', 1000), 'eleventh request is rate limited');
    $check(DeviceLinkRateLimit::allow($dir, '192.0.2.2', 1000) && DeviceLinkRateLimit::allow($dir, '192.0.2.1', 1060), 'limit is per address and resets after 60 seconds');
    $invalid = false; try { DeviceLinkRateLimit::allow($dir, 'invalid', 1000); } catch (RuntimeException) { $invalid=true; }
    $check($invalid, 'missing client address fails closed');
    if (function_exists('pcntl_fork')) {
        $children = [];
        for ($i=0; $i<16; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) { try { exit(DeviceLinkRateLimit::allow($dir, '192.0.2.3', 2000) ? 0 : 1); } catch (Throwable) { exit(2); } }
            if ($pid < 0) throw new RuntimeException('FORK_FAILED');
            $children[] = $pid;
        }
        $accepted = 0; $limited = 0;
        foreach ($children as $pid) { pcntl_waitpid($pid, $status); if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) $accepted++; elseif (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 1) $limited++; }
        $check($accepted === 10 && $limited === 6, 'concurrent rate limiter admits only ten attempts');
    } else {
        echo "SKIP concurrency requires pcntl\n";
    }
} finally {
    foreach (glob($dir . '/*.limit') ?: [] as $entry) unlink($entry);
    rmdir($dir);
}
echo "RESULT: {$passed} PASS / {$failed} FAIL\n";
exit($failed ? 1 : 0);
