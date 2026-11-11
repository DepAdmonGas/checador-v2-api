<?php

declare(strict_types=1);

/** Pure contract rules for the approved but disabled workplace-coverage slice. */
final class WorkplaceCoveragePolicy
{
    public const READ = 'checador.comodines.leer';
    public const REQUEST = 'checador.comodines.solicitar';
    public const APPROVE = 'checador.comodines.aprobar';
    public const CANCEL = 'checador.comodines.cancelar';

    private const DAYS = ['LUNES', 'MARTES', 'MIERCOLES', 'JUEVES', 'VIERNES', 'SABADO', 'DOMINGO'];

    /** Strict opt-in; an unset or malformed environment value is disabled. */
    public static function featureFlagEnabled(?string $value): bool
    {
        return $value !== null && trim($value) === '1';
    }

    /** Capability-to-position ceiling; DB grants remain mandatory. */
    public static function positionMayUse(int $positionId, string $capability): bool
    {
        return match ($capability) {
            self::READ, self::REQUEST => $positionId === 13,
            self::APPROVE, self::CANCEL => $positionId === 13,
            default => false,
        };
    }

    /** Request and approval are distinct actions; the same authorized Direction user may perform both. */
    public static function approvalActorsAllowed(int $requesterId, int $approverId): bool
    {
        return $requesterId > 0 && $approverId > 0;
    }

    /** Direct user rules, including deny/revocation, take precedence over position rules. */
    public static function grantAllows(?array $direct, ?array $position): bool
    {
        $grant = $direct ?? $position;
        return $grant !== null && (int)($grant['active'] ?? 0) === 1 && (int)($grant['granted'] ?? 0) === 1;
    }

    /** Direction requests, approvals, and cancellations require every represented canonical Workplace in current scope. */
    public static function scopeAllows(
        int $positionId,
        string $capability,
        array $authorizedWorkplaces,
        ?int $homeWorkplaceId = null,
        ?int $coverageWorkplaceId = null
    ): bool {
        $scope = array_values(array_unique(array_map('intval', $authorizedWorkplaces)));
        if (!self::positionMayUse($positionId, $capability) || $scope === []) return false;
        if ($capability === self::REQUEST || $capability === self::APPROVE || $capability === self::CANCEL) {
            return $homeWorkplaceId !== null && $coverageWorkplaceId !== null
                && in_array($homeWorkplaceId, $scope, true)
                && in_array($coverageWorkplaceId, $scope, true);
        }
        if ($capability === self::READ) {
            return ($homeWorkplaceId === null || in_array($homeWorkplaceId, $scope, true))
                && ($coverageWorkplaceId === null || in_array($coverageWorkplaceId, $scope, true));
        }
        return false;
    }

    /**
     * Validate the exact seven explicit weekdays used by the approved contract.
     * WORK requires a canonical template ID and version; DAY_OFF has neither.
     * @return list<array{dia_semana:string,tipo:string,id_plantilla:?int,version_plantilla:?int}>
     */
    public static function normalizeWeeklySchedule(mixed $schedule): array
    {
        if (!is_array($schedule) || count($schedule) !== 7 || !array_is_list($schedule)) {
            throw new InvalidArgumentException('INVALID_COVERAGE_SCHEDULE');
        }
        $byDay = [];
        foreach ($schedule as $item) {
            if (!is_array($item)) throw new InvalidArgumentException('INVALID_COVERAGE_SCHEDULE');
            $day = strtoupper(trim((string)($item['dia_semana'] ?? '')));
            $type = strtoupper(trim((string)($item['tipo'] ?? '')));
            if (!in_array($day, self::DAYS, true) || isset($byDay[$day]) || !in_array($type, ['WORK', 'DAY_OFF'], true)) {
                throw new InvalidArgumentException('INVALID_COVERAGE_SCHEDULE');
            }
            if ($type === 'WORK') {
                $templateId = filter_var($item['id_plantilla'] ?? null, FILTER_VALIDATE_INT);
                $version = filter_var($item['version_plantilla'] ?? null, FILTER_VALIDATE_INT);
                if ($templateId === false || $templateId < 1 || $version === false || $version < 1) {
                    throw new InvalidArgumentException('INVALID_COVERAGE_SCHEDULE');
                }
                $byDay[$day] = ['dia_semana' => $day, 'tipo' => 'WORK', 'id_plantilla' => $templateId, 'version_plantilla' => $version];
                continue;
            }
            if (($item['id_plantilla'] ?? null) !== null || ($item['version_plantilla'] ?? null) !== null) {
                throw new InvalidArgumentException('INVALID_COVERAGE_SCHEDULE');
            }
            $byDay[$day] = ['dia_semana' => $day, 'tipo' => 'DAY_OFF', 'id_plantilla' => null, 'version_plantilla' => null];
        }
        $normalized = [];
        foreach (self::DAYS as $day) {
            if (!isset($byDay[$day])) throw new InvalidArgumentException('INVALID_COVERAGE_SCHEDULE');
            $normalized[] = $byDay[$day];
        }
        return $normalized;
    }

    /** Validate an immutable M04 segment set, including a legal cross-midnight offset. */
    public static function validTemplateSegments(array $segments): bool
    {
        if ($segments === []) return false;
        $lastEnd = null;
        $hasWork = false;
        foreach ($segments as $index => $segment) {
            $type = strtoupper((string)($segment['tipo_segmento'] ?? ''));
            $start = (string)($segment['hora_inicio_local'] ?? '');
            $end = (string)($segment['hora_fin_local'] ?? '');
            $offsetStart = filter_var($segment['dia_offset_inicio'] ?? null, FILTER_VALIDATE_INT);
            $offsetEnd = filter_var($segment['dia_offset_fin'] ?? null, FILTER_VALIDATE_INT);
            $ordinal = filter_var($segment['ordinal'] ?? null, FILTER_VALIDATE_INT);
            if (!in_array($type, ['WORK', 'BREAK'], true)
                || !preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?\z/', $start)
                || !preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?\z/', $end)
                || !in_array($offsetStart, [0, 1], true) || !in_array($offsetEnd, [0, 1], true)
                || $ordinal !== $index + 1) return false;
            $startMinute = $offsetStart * 1440 + self::minuteOfDay($start);
            $endMinute = $offsetEnd * 1440 + self::minuteOfDay($end);
            if ($endMinute <= $startMinute || ($lastEnd !== null && $startMinute < $lastEnd)) return false;
            $lastEnd = $endMinute;
            $hasWork = $hasWork || $type === 'WORK';
        }
        return $hasWork;
    }

    private static function minuteOfDay(string $time): int
    {
        $parts = array_map('intval', explode(':', $time));
        return $parts[0] * 60 + $parts[1];
    }

    /** Stable SHA-256 fingerprint; list order is retained, object keys are canonicalized. */
    public static function fingerprint(string $operation, array $payload): string
    {
        if ($operation === '') throw new InvalidArgumentException('INVALID_COVERAGE_OPERATION');
        $canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
            if (!is_array($value)) return $value;
            if (array_is_list($value)) return array_map($canonicalize, $value);
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) $value[$key] = $canonicalize($item);
            return $value;
        };
        $json = json_encode(['operation' => $operation, 'payload' => $canonicalize($payload)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }

    /**
     * Pure resolver precedence. The caller must separately validate the membership and exact canonical assignment.
     * @return array{status:string,source:string,workplace_id:?int,assignment_id:?int,day_type:?string}
     */
    public static function chooseEffectiveSource(int $workerId, string $localDate, int $homeWorkplaceId, array $coverages): array
    {
        if ($workerId < 1 || !self::isCivilDate($localDate) || $homeWorkplaceId < 1) return self::unknown();
        $matches = [];
        foreach ($coverages as $coverage) {
            if (!is_array($coverage) || (int)($coverage['id_personal'] ?? 0) !== $workerId
                || strtoupper((string)($coverage['status'] ?? '')) !== 'AUTHORIZED'
                || !self::isCivilDate((string)($coverage['fecha_desde_local'] ?? ''))
                || !self::isCivilDate((string)($coverage['fecha_hasta_local_exclusiva'] ?? ''))) continue;
            $from = (string)$coverage['fecha_desde_local'];
            $to = (string)$coverage['fecha_hasta_local_exclusiva'];
            if ($to <= $from || $localDate < $from || $localDate >= $to) continue;
            $matches[] = $coverage;
        }
        if (count($matches) > 1) return self::unknown();
        if ($matches === []) return ['status' => 'RESOLVE_HOME', 'source' => 'HABITUAL', 'workplace_id' => $homeWorkplaceId, 'assignment_id' => null, 'day_type' => null];
        $coverage = $matches[0];
        $workplaceId = (int)($coverage['id_coverage_workplace'] ?? 0);
        $revisionId = (int)($coverage['id'] ?? 0);
        $weekday = self::weekday($localDate);
        if ($workplaceId < 1 || $revisionId < 1 || $weekday === null || !is_array($coverage['schedule'] ?? null)) return self::unknown();
        $day = null;
        foreach ($coverage['schedule'] as $entry) {
            if (is_array($entry) && strtoupper((string)($entry['dia_semana'] ?? '')) === $weekday) { $day = $entry; break; }
        }
        if (!is_array($day)) return self::unknown();
        $expectedType = strtoupper((string)($day['tipo'] ?? ''));
        if (!in_array($expectedType, ['WORK', 'DAY_OFF'], true)) return self::unknown();
        $expectedTemplateId = $expectedType === 'WORK' ? (int)($day['id_plantilla'] ?? 0) : null;
        $expectedVersion = $expectedType === 'WORK' ? (int)($day['version_plantilla'] ?? 0) : null;
        if ($expectedType === 'WORK' && ($expectedTemplateId < 1 || $expectedVersion < 1)) return self::unknown();
        if ($expectedType === 'DAY_OFF' && (($day['id_plantilla'] ?? null) !== null || ($day['version_plantilla'] ?? null) !== null)) return self::unknown();
        $assignments = array_values(array_filter($coverage['assignments'] ?? [], static function ($assignment) use ($workerId, $workplaceId, $revisionId, $localDate, $expectedType, $expectedTemplateId, $expectedVersion): bool {
            return is_array($assignment)
                && (int)($assignment['id_personal'] ?? 0) === $workerId
                && (int)($assignment['id_estacion'] ?? 0) === $workplaceId
                && (int)($assignment['id_cobertura_version'] ?? 0) === $revisionId
                && strtoupper((string)($assignment['tipo_asignacion'] ?? '')) === 'TEMPORAL'
                && strtoupper((string)($assignment['dia_semana'] ?? '')) === self::weekday($localDate)
                && (string)($assignment['valido_desde'] ?? '') <= $localDate
                && (($assignment['valido_hasta'] ?? null) === null || $localDate < (string)$assignment['valido_hasta'])
                && (($expectedType === 'DAY_OFF' && ($assignment['id_plantilla'] ?? null) === null)
                    || ($expectedType === 'WORK'
                        && (int)($assignment['id_plantilla'] ?? 0) === $expectedTemplateId
                        && (int)($assignment['version_plantilla'] ?? 0) === $expectedVersion));
        }));
        if (count($assignments) !== 1) return self::unknown();
        return ['status' => $expectedType === 'DAY_OFF' ? 'DAY_OFF' : 'RESOLVE_COVERAGE', 'source' => 'TEMPORARY_COVERAGE', 'workplace_id' => $workplaceId, 'assignment_id' => (int)$assignments[0]['id'], 'day_type' => $expectedType];
    }

    private static function unknown(): array
    {
        return ['status' => 'UNKNOWN', 'source' => 'NONE', 'workplace_id' => null, 'assignment_id' => null, 'day_type' => null];
    }

    private static function weekday(string $date): ?string
    {
        if (!self::isCivilDate($date)) return null;
        $day = (int)(new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('N');
        return self::DAYS[$day - 1] ?? null;
    }

    private static function isCivilDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
