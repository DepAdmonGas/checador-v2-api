<?php
declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/AdministrativeAdjustmentReadAuthorization.php';

$cases = [
    ['no same-module direct grant inherits authorized position grant', null, ['leer' => 1], true],
    ['same-module direct allow takes precedence', ['leer' => 1], ['leer' => 0], true],
    ['same-module direct deny is not overridden by position', ['leer' => 0], ['leer' => 1], false],
    ['no grants deny', null, null, false],
];
$failures = [];
foreach ($cases as [$name, $direct, $position, $expected]) {
    if (AdministrativeAdjustmentReadAuthorization::decidePermission($direct, $position) !== $expected) $failures[] = $name;
}
if ($failures !== []) {
    fwrite(STDERR, "FAIL: " . implode('; ', $failures) . PHP_EOL);
    exit(1);
}
echo count($cases) . "/" . count($cases) . " administrative adjustment authorization unit cases PASS\n";
