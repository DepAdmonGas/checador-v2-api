<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/shift/WorkplaceCoverageFeatureGate.php';

$cases = [
    ['int(10) unsigned', 'int', true],
    ['int unsigned', 'int', true],
    ['bigint(20) unsigned', 'bigint', true],
    ['BIGINT UNSIGNED', 'bigint', true],
    ['int(11)', 'int', false],
    ['bigint(20)', 'bigint', false],
    ['smallint(5) unsigned', 'int', false],
    ['int(10) unsigned zerofill', 'int', false],
];
$failed = 0;
foreach ($cases as $index => [$actual, $expectedType, $expected]) {
    $actualResult = WorkplaceCoverageFeatureGate::unsignedIntegerColumnTypeMatches($actual, $expectedType);
    $pass = $actualResult === $expected;
    echo '[' . ($index + 1) . '] ' . ($pass ? 'PASS' : 'FAIL') . ' ' . $actual . ' as ' . $expectedType . PHP_EOL;
    if (!$pass) $failed++;
}
echo 'WORKPLACE_COVERAGE_SCHEMA_GATE_TYPES: ' . (count($cases) - $failed) . ' PASS: ' . $failed . ' FAIL' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
