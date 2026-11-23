<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/shift/ShiftTemplateStationWriteCutover.php';

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/modelo/v2/shift/ShiftSchedulingV2Controller.php') ?: '';
$router = file_get_contents($root . '/index.php') ?: '';
$passed = 0;
$failed = 0;
$assert = static function (bool $ok, string $message) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$savedApiFlag = [array_key_exists(ShiftTemplateStationWriteCutover::FLAG, $_ENV), $_ENV[ShiftTemplateStationWriteCutover::FLAG] ?? null, getenv(ShiftTemplateStationWriteCutover::FLAG)];
unset($_ENV[ShiftTemplateStationWriteCutover::FLAG], $_SERVER[ShiftTemplateStationWriteCutover::FLAG]);
putenv(ShiftTemplateStationWriteCutover::FLAG);
$assert(!ShiftTemplateStationWriteCutover::stationWritesEnabled()
    && !ShiftTemplateStationWriteCutover::stationWritesEnabled('')
    && ShiftTemplateStationWriteCutover::stationWritesEnabled('1')
    && ShiftTemplateStationWriteCutover::stationWritesEnabled('true'),
    'unset and empty station-write flag fail closed; explicit true is required to open the API writer');
$assert(!ShiftTemplateStationWriteCutover::stationWritesEnabled('0')
    && !ShiftTemplateStationWriteCutover::stationWritesEnabled('false')
    && !ShiftTemplateStationWriteCutover::stationWritesEnabled('unexpected'),
    'explicit false and malformed station-gate values fail closed');
if ($savedApiFlag[0]) $_ENV[ShiftTemplateStationWriteCutover::FLAG] = $savedApiFlag[1];
else unset($_ENV[ShiftTemplateStationWriteCutover::FLAG]);
if ($savedApiFlag[2] === false) putenv(ShiftTemplateStationWriteCutover::FLAG);
else putenv(ShiftTemplateStationWriteCutover::FLAG . '=' . $savedApiFlag[2]);

$mutations = 0;
$stationClosed = ShiftTemplateStationWriteCutover::execute(['scope' => 'STATION', 'station_id' => 1], static function () use (&$mutations): array {
    $mutations++;
    return ['written' => true];
}, '0');
$assert(($stationClosed['allowed'] ?? true) === false
    && ($stationClosed['code'] ?? '') === ShiftTemplateStationWriteCutover::ERROR_CODE
    && ($stationClosed['status'] ?? 0) === 503 && $mutations === 0,
    'closed station create/customize/version gate rejects with 503 without invoking the mutation callback');

$fixtureRecords = [
    'templates' => [['id' => 10, 'name' => 'fixture-before']],
    'segments' => [['template_id' => 10, 'kind' => 'WORK']],
    'lineage' => [['template_id' => 10, 'source_id' => 4]],
    'audit' => [['id' => 7, 'event' => 'existing']],
];
foreach (['create', 'customize', 'version'] as $operation) {
    $before = $fixtureRecords;
    $rejected = ShiftTemplateStationWriteCutover::execute(
        ['scope' => 'STATION', 'station_id' => 1],
        static function () use (&$fixtureRecords, $operation): array {
            foreach (array_keys($fixtureRecords) as $table) $fixtureRecords[$table][] = ['operation' => $operation];
            return ['written' => true];
        },
        '0'
    );
    $assert(($rejected['allowed'] ?? true) === false
        && ($rejected['status'] ?? 0) === 503
        && ($rejected['code'] ?? '') === ShiftTemplateStationWriteCutover::ERROR_CODE
        && $fixtureRecords === $before,
        "closed {$operation} request returns 503 and leaves isolated template/segment/lineage/audit snapshots unchanged");
}
foreach (['create', 'customize', 'version'] as $operation) {
    $before = $fixtureRecords;
    $accepted = ShiftTemplateStationWriteCutover::execute(
        ['scope' => 'STATION', 'station_id' => 1],
        static function () use (&$fixtureRecords, $operation): array {
            foreach (array_keys($fixtureRecords) as $table) $fixtureRecords[$table][] = ['operation' => $operation];
            return ['written' => true];
        },
        '1'
    );
    $assert(($accepted['allowed'] ?? false) === true
        && ($accepted['result']['written'] ?? false) === true
        && count($fixtureRecords['templates']) === count($before['templates']) + 1
        && count($fixtureRecords['segments']) === count($before['segments']) + 1
        && count($fixtureRecords['lineage']) === count($before['lineage']) + 1
        && count($fixtureRecords['audit']) === count($before['audit']) + 1,
        "explicitly opened {$operation} request changes only its isolated fixture records");
}

$global = ShiftTemplateStationWriteCutover::execute(['scope' => 'GLOBAL'], static function () use (&$mutations): array {
    $mutations++;
    return ['written' => true];
}, '0');
$assert(($global['allowed'] ?? false) === true && ($global['result']['written'] ?? false) === true && $mutations === 1,
    'closed station gate leaves authorized global template administration operational');
$unknownScope = ShiftTemplateStationWriteCutover::execute([], static function () use (&$mutations): array {
    $mutations++;
    return ['written' => true];
}, '1');
$assert(($unknownScope['allowed'] ?? true) === false && $mutations === 1,
    'missing server authorization scope denies without invoking mutation');

$method = static function (string $name) use ($controller): string {
    $start = strpos($controller, 'public function ' . $name . '(');
    if ($start === false) return '';
    $end = strpos($controller, '\n    public function ', $start + 1);
    return substr($controller, $start, $end === false ? null : $end - $start);
};
$create = $method('handleCreateTemplate');
$version = $method('handleCreateTemplateVersion');
$firstCreateAuth = strpos($create, '$this->authorizeTemplateMutation($payload)');
$firstCreateGate = strpos($create, 'ShiftTemplateStationWriteCutover::execute($authInfo');
$customizeWrite = strpos($create, '$this->templateService->createStationCustomization(');
$secondCreateGate = $firstCreateGate === false ? false : strpos($create, 'ShiftTemplateStationWriteCutover::execute($authInfo', $firstCreateGate + 1);
$templateWrite = strpos($create, '$this->templateService->createTemplate(');
$versionAuth = strpos($version, '$this->authorizeTemplateMutation($payload)');
$versionGate = strpos($version, 'ShiftTemplateStationWriteCutover::execute($authInfo');
$versionWrite = strpos($version, '$this->templateService->createNewVersion(');
$assert($firstCreateAuth !== false && $firstCreateGate !== false && $firstCreateAuth < $firstCreateGate
    && $customizeWrite !== false && $customizeWrite > $firstCreateGate
    && $secondCreateGate !== false && $templateWrite !== false && $templateWrite > $secondCreateGate,
    'create and global-to-station customization authenticate and resolve scope before their gated mutation callbacks');
$assert($versionAuth !== false && $versionGate !== false && $versionWrite !== false
    && $versionAuth < $versionGate && $versionWrite > $versionGate,
    'version append authenticates and resolves scope before its gated mutation callback');
$assert(str_contains($create, "ShiftTemplateStationWriteCutover::ERROR_CODE") === false
    && str_contains($create, "'La escritura administrativa de plantillas de estación está cerrada durante el corte M04.'")
    && str_contains($version, "'La escritura administrativa de plantillas de estación está cerrada durante el corte M04.'"),
    'closed station writer maps to an explicit 503 response without changing validation errors');

$list = $method('handleListTemplates');
$detail = $method('handleGetTemplateVersion');
$assert(!str_contains($list, 'ShiftTemplateStationWriteCutover')
    && !str_contains($detail, 'ShiftTemplateStationWriteCutover')
    && str_contains($router, '$shiftCtrl->handleListTemplates();')
    && str_contains($router, '$shiftCtrl->handleGetTemplateVersion($clave, $version);'),
    'template GET catalog and detail paths remain available outside the station mutation gate');

$assignmentCreate = $method('handleCreateAssignment');
$assert(!str_contains($assignmentCreate, 'ShiftTemplateStationWriteCutover'),
    'M03 assignment writes and other shift API operations remain outside the M04 template gate');

echo "M04_STATION_TEMPLATE_WRITER_CUTOVER: {$passed} PASS / {$failed} FAIL" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
