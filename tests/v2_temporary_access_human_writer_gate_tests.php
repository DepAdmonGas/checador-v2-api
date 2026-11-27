<?php
declare(strict_types=1);
require_once __DIR__ . '/../modelo/v2/TemporaryAccessV2Service.php';
$service = new TemporaryAccessV2Service();
$pass = 0; $fail = 0;
$check = static function (bool $ok, string $name) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$methods = ['authorizeRequest', 'rejectRequest', 'cancelRequest'];
foreach (['PORTAL3', '', 'api', 'API,PORTAL3', 'invalid'] as $value) {
    putenv('CHECADOR_M08_HUMAN_WRITER=' . $value);
    foreach ($methods as $method) {
        // null cannot be a mysqli connection: reaching any SQL operation would throw.
        $result = $service->$method(null, [1], 'synthetic', 1);
        $check($result['code'] === 503 && $result['status'] === 'ADMINISTRATIVE_WRITER_UNAVAILABLE', "$method blocked for " . var_export($value, true));
    }
}
foreach ([null, 'API'] as $value) {
    $value === null ? putenv('CHECADOR_M08_HUMAN_WRITER') : putenv('CHECADOR_M08_HUMAN_WRITER=' . $value);
    foreach ($methods as $method) {
        $reachedTransaction = false;
        try { $service->$method(null, [1], 'synthetic', 1); } catch (TypeError $e) { $reachedTransaction = true; }
        $check($reachedTransaction, "$method reaches existing transaction with " . ($value ?? 'unset'));
    }
}
putenv('CHECADOR_M08_HUMAN_WRITER');
echo "RESULT: $pass PASS / $fail FAIL\n";
exit($fail ? 1 : 0);
