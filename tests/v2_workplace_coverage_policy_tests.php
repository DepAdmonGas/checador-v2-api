<?php

declare(strict_types=1);

require_once __DIR__ . '/../modelo/v2/shift/WorkplaceCoveragePolicy.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $name) use (&$passed, &$failed): void {
    if ($condition) { $passed++; echo "[{$name}] PASS\n"; }
    else { $failed++; echo "[{$name}] FAIL\n"; }
};
$throws = static function (callable $callback): bool {
    try { $callback(); return false; }
    catch (InvalidArgumentException) { return true; }
};

$assert(!WorkplaceCoveragePolicy::featureFlagEnabled(null), 'WC01 unset flag disabled');
$assert(!WorkplaceCoveragePolicy::featureFlagEnabled('true'), 'WC02 non-exact flag disabled');
$assert(WorkplaceCoveragePolicy::featureFlagEnabled('1'), 'WC03 explicit opt-in recognized');
$assert(!WorkplaceCoveragePolicy::positionMayUse(6, WorkplaceCoveragePolicy::READ), 'WC04 manager cannot read coverage');
$assert(!WorkplaceCoveragePolicy::positionMayUse(6, WorkplaceCoveragePolicy::REQUEST), 'WC05 manager cannot request');
$assert(!WorkplaceCoveragePolicy::positionMayUse(6, WorkplaceCoveragePolicy::APPROVE), 'WC06 manager cannot approve');
$assert(!WorkplaceCoveragePolicy::positionMayUse(6, WorkplaceCoveragePolicy::CANCEL), 'WC07 manager cannot cancel');
$assert(WorkplaceCoveragePolicy::positionMayUse(13, WorkplaceCoveragePolicy::APPROVE), 'WC08 direction may approve with grant');
$assert(WorkplaceCoveragePolicy::positionMayUse(13, WorkplaceCoveragePolicy::REQUEST), 'WC09 direction may request with grant');
$assert(WorkplaceCoveragePolicy::approvalActorsAllowed(52, 52), 'WC10 same Direction user may approve in a separate transition');
$assert(WorkplaceCoveragePolicy::approvalActorsAllowed(52, 71), 'WC11 another authorized Direction user may approve');
$assert(!WorkplaceCoveragePolicy::approvalActorsAllowed(0, 52), 'WC12 invalid requester identity is rejected');
$assert(WorkplaceCoveragePolicy::grantAllows(null, ['active' => 1, 'granted' => 1]), 'WC08 position grant applies without direct rule');
$assert(!WorkplaceCoveragePolicy::grantAllows(['active' => 1, 'granted' => 0], ['active' => 1, 'granted' => 1]), 'WC09 direct deny overrides position grant');
$assert(!WorkplaceCoveragePolicy::grantAllows(['active' => 0, 'granted' => 1], ['active' => 1, 'granted' => 1]), 'WC10 inactive direct grant denies');
$assert(WorkplaceCoveragePolicy::scopeAllows(13, WorkplaceCoveragePolicy::REQUEST, [2, 9], 2, 9), 'WC13 request requires both Workplaces in scope');
$assert(!WorkplaceCoveragePolicy::scopeAllows(13, WorkplaceCoveragePolicy::REQUEST, [2], 2, 9), 'WC14 request outside destination scope denied');
$assert(!WorkplaceCoveragePolicy::scopeAllows(13, WorkplaceCoveragePolicy::REQUEST, [9], 2, 9), 'WC15 request outside home scope denied');
$assert(WorkplaceCoveragePolicy::scopeAllows(13, WorkplaceCoveragePolicy::APPROVE, [2, 9], 2, 9), 'WC16 approval requires both Workplaces in scope');
$assert(!WorkplaceCoveragePolicy::scopeAllows(13, WorkplaceCoveragePolicy::APPROVE, [2], 2, 9), 'WC17 approval outside destination scope denied');
$assert(!WorkplaceCoveragePolicy::scopeAllows(6, WorkplaceCoveragePolicy::CANCEL, [2, 9], 2, 9), 'WC18 manager cannot cancel');

$days = ['LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO'];
$schedule = [];
foreach ($days as $day) {
    $schedule[] = $day === 'MARTES'
        ? ['dia_semana' => $day, 'tipo' => 'WORK', 'id_plantilla' => 7, 'version_plantilla' => 2]
        : ['dia_semana' => $day, 'tipo' => 'DAY_OFF', 'id_plantilla' => null, 'version_plantilla' => null];
}
$normalized = WorkplaceCoveragePolicy::normalizeWeeklySchedule($schedule);
$assert(count($normalized) === 7 && $normalized[1]['dia_semana'] === 'MARTES', 'WC16 exact seven explicit weekdays normalized');
$assert($throws(static fn() => WorkplaceCoveragePolicy::normalizeWeeklySchedule(array_slice($schedule, 0, 6)), 'INVALID_COVERAGE_SCHEDULE'), 'WC17 missing weekday rejected');
$duplicate = $schedule;
$duplicate[6] = $duplicate[0];
$assert($throws(static fn() => WorkplaceCoveragePolicy::normalizeWeeklySchedule($duplicate)), 'WC18 duplicate weekday rejected');
$invalidDayOff = $schedule;
$invalidDayOff[0]['id_plantilla'] = 3;
$assert($throws(static fn() => WorkplaceCoveragePolicy::normalizeWeeklySchedule($invalidDayOff)), 'WC19 day off cannot carry template');
$assert(WorkplaceCoveragePolicy::fingerprint('REQUEST', ['b' => 2, 'a' => 1]) === WorkplaceCoveragePolicy::fingerprint('REQUEST', ['a' => 1, 'b' => 2]), 'WC20 fingerprint canonicalizes object key order');
$assert(WorkplaceCoveragePolicy::fingerprint('REQUEST', ['a' => 1]) !== WorkplaceCoveragePolicy::fingerprint('REQUEST', ['a' => 2]), 'WC21 fingerprint distinguishes changed payload');

$date = '2026-11-10';
$assignment = [
    'id' => 55, 'id_personal' => 1006, 'id_estacion' => 9, 'id_cobertura_version' => 40,
    'tipo_asignacion' => 'TEMPORAL', 'dia_semana' => 'MARTES', 'valido_desde' => '2026-11-09',
    'valido_hasta' => '2026-11-16', 'id_plantilla' => 7, 'version_plantilla' => 2,
];
$coverage = [
    'id' => 40, 'id_personal' => 1006, 'status' => 'AUTHORIZED', 'fecha_desde_local' => '2026-11-09',
    'fecha_hasta_local_exclusiva' => '2026-11-16', 'id_coverage_workplace' => 9, 'schedule' => $schedule,
    'assignments' => [$assignment],
];
$choice = WorkplaceCoveragePolicy::chooseEffectiveSource(1006, $date, 2, [$coverage]);
$assert($choice['status'] === 'RESOLVE_COVERAGE' && $choice['assignment_id'] === 55 && $choice['workplace_id'] === 9, 'WC22 authorized temporary assignment wins over home');
$assert(WorkplaceCoveragePolicy::chooseEffectiveSource(1006, '2026-11-08', 2, [$coverage])['status'] === 'RESOLVE_HOME', 'WC23 before coverage resolves home schedule');
$assert(WorkplaceCoveragePolicy::chooseEffectiveSource(1006, '2026-11-16', 2, [$coverage])['status'] === 'RESOLVE_HOME', 'WC24 end date is exclusive');
$assert(WorkplaceCoveragePolicy::chooseEffectiveSource(1006, $date, 2, [])['status'] === 'RESOLVE_HOME', 'WC25 no coverage preserves habitual source');
$assert(WorkplaceCoveragePolicy::chooseEffectiveSource(1006, $date, 2, [$coverage, $coverage])['status'] === 'UNKNOWN', 'WC26 overlapping active coverage fails unknown');
$wrongAssignment = $assignment;
$wrongAssignment['id_estacion'] = 2;
$broken = $coverage; $broken['assignments'] = [$wrongAssignment];
$assert(WorkplaceCoveragePolicy::chooseEffectiveSource(1006, $date, 2, [$broken])['status'] === 'UNKNOWN', 'WC27 assignment at wrong Workplace fails unknown');
$wrongAssignment = $assignment;
$wrongAssignment['id_cobertura_version'] = 41;
$broken['assignments'] = [$wrongAssignment];
$assert(WorkplaceCoveragePolicy::chooseEffectiveSource(1006, $date, 2, [$broken])['status'] === 'UNKNOWN', 'WC28 unlinked assignment fails unknown');
$wrongAssignment = $assignment;
$wrongAssignment['version_plantilla'] = 1;
$broken['assignments'] = [$wrongAssignment];
$assert(WorkplaceCoveragePolicy::chooseEffectiveSource(1006, $date, 2, [$broken])['status'] === 'UNKNOWN', 'WC29 wrong template version fails unknown');
$wrongAssignment = $assignment;
$wrongAssignment['tipo_asignacion'] = 'HABITUAL';
$broken['assignments'] = [$wrongAssignment];
$assert(WorkplaceCoveragePolicy::chooseEffectiveSource(1006, $date, 2, [$broken])['status'] === 'UNKNOWN', 'WC30 habitual row cannot serve as coverage');
$wrongAssignment = $assignment;
$wrongAssignment['valido_hasta'] = '2026-11-10';
$broken['assignments'] = [$wrongAssignment];
$assert(WorkplaceCoveragePolicy::chooseEffectiveSource(1006, $date, 2, [$broken])['status'] === 'UNKNOWN', 'WC31 assignment end is exclusive');

$dayOffSchedule = $schedule;
$dayOffCoverage = $coverage;
$dayOffCoverage['schedule'] = $dayOffSchedule;
$dayOffAssignment = $assignment;
$dayOffAssignment['id'] = 56;
$dayOffAssignment['dia_semana'] = 'LUNES';
$dayOffAssignment['id_plantilla'] = null;
$dayOffAssignment['version_plantilla'] = null;
$dayOffCoverage['assignments'] = [$dayOffAssignment];
$dayOff = WorkplaceCoveragePolicy::chooseEffectiveSource(1006, '2026-11-09', 2, [$dayOffCoverage]);
$assert($dayOff['status'] === 'DAY_OFF' && $dayOff['assignment_id'] === 56, 'WC32 day off requires linked explicit TEMPORAL row');
$dayOffCoverage['assignments'] = [];
$assert(WorkplaceCoveragePolicy::chooseEffectiveSource(1006, '2026-11-09', 2, [$dayOffCoverage])['status'] === 'UNKNOWN', 'WC33 missing explicit day off row is unknown');

$continuous = [
    ['ordinal' => 1, 'tipo_segmento' => 'WORK', 'hora_inicio_local' => '07:00:00', 'hora_fin_local' => '16:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 0],
];
$withBreak = [
    ['ordinal' => 1, 'tipo_segmento' => 'WORK', 'hora_inicio_local' => '07:00:00', 'hora_fin_local' => '11:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 0],
    ['ordinal' => 2, 'tipo_segmento' => 'BREAK', 'hora_inicio_local' => '11:00:00', 'hora_fin_local' => '12:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 0],
    ['ordinal' => 3, 'tipo_segmento' => 'WORK', 'hora_inicio_local' => '12:00:00', 'hora_fin_local' => '16:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 0],
];
$overnight = [
    ['ordinal' => 1, 'tipo_segmento' => 'WORK', 'hora_inicio_local' => '22:00:00', 'hora_fin_local' => '02:00:00', 'dia_offset_inicio' => 0, 'dia_offset_fin' => 1],
    ['ordinal' => 2, 'tipo_segmento' => 'BREAK', 'hora_inicio_local' => '02:00:00', 'hora_fin_local' => '02:30:00', 'dia_offset_inicio' => 1, 'dia_offset_fin' => 1],
    ['ordinal' => 3, 'tipo_segmento' => 'WORK', 'hora_inicio_local' => '02:30:00', 'hora_fin_local' => '06:00:00', 'dia_offset_inicio' => 1, 'dia_offset_fin' => 1],
];
$assert(WorkplaceCoveragePolicy::validTemplateSegments($continuous), 'WC34 continuous shift template accepted');
$assert(WorkplaceCoveragePolicy::validTemplateSegments($withBreak), 'WC35 explicit break segments accepted');
$assert(WorkplaceCoveragePolicy::validTemplateSegments($overnight), 'WC36 overnight offsets accepted');
$assert(!WorkplaceCoveragePolicy::validTemplateSegments([$overnight[0], $overnight[2]]), 'WC37 incomplete segment ordinals rejected');
$onlyBreak = $withBreak;
$onlyBreak[0]['tipo_segmento'] = 'BREAK';
$onlyBreak[2]['tipo_segmento'] = 'BREAK';
$assert(!WorkplaceCoveragePolicy::validTemplateSegments($onlyBreak), 'WC38 break-only template rejected');
$overlapSegments = $withBreak;
$overlapSegments[1]['hora_inicio_local'] = '10:59:00';
$assert(!WorkplaceCoveragePolicy::validTemplateSegments($overlapSegments), 'WC39 overlapping segments rejected');
$badOffsets = $overnight;
$badOffsets[0]['dia_offset_fin'] = 2;
$assert(!WorkplaceCoveragePolicy::validTemplateSegments($badOffsets), 'WC40 unsupported day offset rejected');
$badOrdinal = $withBreak;
$badOrdinal[2]['ordinal'] = 4;
$assert(!WorkplaceCoveragePolicy::validTemplateSegments($badOrdinal), 'WC41 non-deterministic segment ordinal rejected');
$malformedFlag = ['0', 'true', '01', ' 1x'];
foreach ($malformedFlag as $value) {
    $assert(!WorkplaceCoveragePolicy::featureFlagEnabled($value), 'WC42 malformed feature flag stays disabled: ' . trim($value));
}

echo "WORKPLACE_COVERAGE_POLICY: " . ($passed + $failed) . " PASS: {$passed} FAIL: {$failed}\n";
exit($failed === 0 ? 0 : 1);
