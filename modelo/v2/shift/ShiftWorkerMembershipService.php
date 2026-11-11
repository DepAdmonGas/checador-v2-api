<?php

declare(strict_types=1);

require_once __DIR__ . '/WorkplaceTimezoneAuthorityService.php';

/** Revalidates append-only, bitemporal worker/locality membership for scheduling writes. */
final class ShiftWorkerMembershipService
{
    private mysqli $db;
    private WorkplaceTimezoneAuthorityService $timezoneAuthority;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->timezoneAuthority = new WorkplaceTimezoneAuthorityService($db);
    }

    /** Lock an active worker and require unique membership for the whole half-open local date interval. */
    public function lockAndAssertCoverage(int $workerId, int $workplaceId, string $validFrom, ?string $validTo): void
    {
        if ($workerId < 1 || $workplaceId < 1 || !self::isCivilDate($validFrom)
            || ($validTo !== null && (!self::isCivilDate($validTo) || $validTo <= $validFrom))) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Invalid assignment interval.');
        }

        $stmt = $this->db->prepare('SELECT id FROM op_rh_personal WHERE id = ? AND estado = 1 FOR UPDATE');
        if (!$stmt) {
            throw new RuntimeException('WORKER_MEMBERSHIP_DEPENDENCY_UNAVAILABLE');
        }
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        $workerExists = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        if (!$workerExists) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Active canonical worker not found.');
        }

        [$fromUtc, $toUtc] = $this->resolveUtcInterval($workplaceId, $validFrom, $validTo);
        $rows = $this->readCurrentMembershipRows($workerId, true);
        $decision = self::evaluateCoverage($workerId, $workplaceId, $fromUtc, $toUtc, $rows);
        if (($decision['covered'] ?? false) !== true) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Membership does not uniquely cover the complete assignment validity.');
        }
    }

    /** A read request can inspect history only for a worker with recorded canonical history at that locality. */
    public function assertHistoryWithinScope(int $workerId, int $workplaceId): void
    {
        if ($workplaceId < 1 || !in_array($workplaceId, $this->verifiedWorkplaceHistory($workerId), true)) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: No verified membership history in the requested locality.');
        }
    }

    /** @return list<int> */
    public function verifiedWorkplaceHistory(int $workerId): array
    {
        if ($workerId < 1) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Invalid worker.');
        }
        $stmt = $this->db->prepare('SELECT id FROM op_rh_personal WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('WORKER_MEMBERSHIP_DEPENDENCY_UNAVAILABLE');
        }
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        if (!$exists) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Canonical worker not found.');
        }
        $rows = $this->readCurrentMembershipRows($workerId, false);
        $validated = self::validateMembershipHistory($workerId, $rows);
        if (($validated['valid'] ?? false) !== true) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Membership history is invalid or ambiguous.');
        }
        return $validated['workplaces'];
    }

    /** Read path may narrow a historical query to one local date. */
    public function assertLocalDateMembership(int $workerId, int $workplaceId, string $localDate): void
    {
        if (!self::isCivilDate($localDate)) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Invalid local date.');
        }
        $stmt = $this->db->prepare('SELECT id FROM op_rh_personal WHERE id = ? AND estado = 1 LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('WORKER_MEMBERSHIP_DEPENDENCY_UNAVAILABLE');
        }
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        $workerExists = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        if (!$workerExists) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Active canonical worker not found.');
        }
        $to = (new DateTimeImmutable($localDate, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        [$fromUtc, $toUtc] = $this->resolveUtcInterval($workplaceId, $localDate, $to);
        $decision = self::evaluateCoverage($workerId, $workplaceId, $fromUtc, $toUtc, $this->readCurrentMembershipRows($workerId, false));
        if (($decision['covered'] ?? false) !== true) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Membership does not uniquely cover the requested local date.');
        }
    }

    /** Validate an already-recorded assignment interval without taking row locks on a GET. */
    public function assertHistoryIntervalWithinScope(int $workerId, int $workplaceId, string $validFrom, ?string $validTo): void
    {
        if ($workerId < 1 || $workplaceId < 1 || !self::isCivilDate($validFrom)
            || ($validTo !== null && (!self::isCivilDate($validTo) || $validTo <= $validFrom))) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Invalid assignment interval.');
        }
        $stmt = $this->db->prepare('SELECT id FROM op_rh_personal WHERE id = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('WORKER_MEMBERSHIP_DEPENDENCY_UNAVAILABLE');
        }
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        if (!$exists) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Canonical worker not found.');
        }
        [$fromUtc, $toUtc] = $this->resolveUtcInterval($workplaceId, $validFrom, $validTo);
        $decision = self::evaluateCoverage($workerId, $workplaceId, $fromUtc, $toUtc, $this->readCurrentMembershipRows($workerId, false));
        if (($decision['covered'] ?? false) !== true) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Recorded assignment interval is not uniquely covered by canonical membership.');
        }
    }

    /** Pure bitemporal membership interval evaluator. Stored rows are the leaf versions as recorded at request time. */
    public static function evaluateCoverage(
        int $workerId,
        int $workplaceId,
        string $fromUtc,
        ?string $toUtc,
        array $rows
    ): array {
        $history = self::validateMembershipHistory($workerId, $rows);
        if (!$history['valid']) {
            return ['covered' => false, 'status' => 'DATA_INVALID'];
        }
        $from = self::normalizeUtc($fromUtc);
        $to = $toUtc === null ? null : self::normalizeUtc($toUtc);
        if ($from === null || ($toUtc !== null && ($to === null || $to <= $from))) {
            return ['covered' => false, 'status' => 'DATA_INVALID'];
        }

        $overlapping = [];
        foreach ($history['leaves'] as $row) {
            $rowFrom = $row['valid_from_utc'];
            $rowTo = $row['valid_to_utc'];
            if (self::overlaps($rowFrom, $rowTo, $from, $to)) {
                $overlapping[] = $row;
            }
        }
        if (count($overlapping) !== 1) {
            return ['covered' => false, 'status' => count($overlapping) > 1 ? 'RESOLVED_MULTIPLE' : 'RESOLVED_NONE'];
        }
        $membership = $overlapping[0];
        $coversStart = $membership['valid_from_utc'] <= $from;
        $coversEnd = $to === null
            ? $membership['valid_to_utc'] === null
            : ($membership['valid_to_utc'] === null || $to <= $membership['valid_to_utc']);
        if (!$coversStart || !$coversEnd || $membership['workplace_id'] !== $workplaceId) {
            return ['covered' => false, 'status' => 'MEMBERSHIP_INTERVAL_MISMATCH'];
        }
        return ['covered' => true, 'status' => 'RESOLVED_SINGLE', 'membership_id' => $membership['id']];
    }

    /** @return array{valid:bool,workplaces:list<int>,leaves:list<array<string,mixed>>} */
    public static function validateMembershipHistory(int $workerId, array $rows): array
    {
        if ($workerId < 1) {
            return ['valid' => false, 'workplaces' => [], 'leaves' => []];
        }
        $byId = [];
        $supersededIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return ['valid' => false, 'workplaces' => [], 'leaves' => []];
            }
            $id = (int)($row['id'] ?? 0);
            $rowWorkerId = (int)($row['worker_id'] ?? 0);
            $workplaceId = (int)($row['workplace_id'] ?? 0);
            $from = self::normalizeDatabaseUtc((string)($row['valid_from_utc'] ?? ''));
            $toRaw = $row['valid_to_utc'] ?? null;
            $to = $toRaw === null ? null : self::normalizeDatabaseUtc((string)$toRaw);
            if ($id < 1 || $rowWorkerId !== $workerId || $workplaceId < 1 || $from === null
                || ($toRaw !== null && ($to === null || $to <= $from)) || isset($byId[$id])) {
                return ['valid' => false, 'workplaces' => [], 'leaves' => []];
            }
            $supersedesValue = $row['supersedes_id'] ?? null;
            $supersedesId = $supersedesValue === null ? null : (int)$supersedesValue;
            if ($supersedesId !== null && $supersedesId < 1) {
                return ['valid' => false, 'workplaces' => [], 'leaves' => []];
            }
            $byId[$id] = [
                'id' => $id,
                'worker_id' => $workerId,
                'workplace_id' => $workplaceId,
                'valid_from_utc' => $from,
                'valid_to_utc' => $to,
                'supersedes_id' => $supersedesId,
            ];
            if ($supersedesId !== null) {
                if (isset($supersededIds[$supersedesId])) {
                    return ['valid' => false, 'workplaces' => [], 'leaves' => []];
                }
                $supersededIds[$supersedesId] = $id;
            }
        }

        foreach ($supersededIds as $targetId => $_successorId) {
            if (!isset($byId[$targetId])) {
                return ['valid' => false, 'workplaces' => [], 'leaves' => []];
            }
        }

        $leaves = [];
        $workplaces = [];
        foreach ($byId as $id => $row) {
            if (isset($supersededIds[$id])) {
                continue;
            }
            $leaves[] = $row;
            $workplaces[$row['workplace_id']] = true;
        }
        for ($i = 0, $count = count($leaves); $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($leaves[$i]['workplace_id'] === $leaves[$j]['workplace_id']
                    && self::overlaps($leaves[$i]['valid_from_utc'], $leaves[$i]['valid_to_utc'], $leaves[$j]['valid_from_utc'], $leaves[$j]['valid_to_utc'])) {
                    return ['valid' => false, 'workplaces' => [], 'leaves' => []];
                }
            }
        }
        return ['valid' => true, 'workplaces' => array_map('intval', array_keys($workplaces)), 'leaves' => $leaves];
    }

    /** Resolve one canonical timezone interval covering the entire assignment, then convert local midnights to UTC. */
    private function resolveUtcInterval(int $workplaceId, string $validFrom, ?string $validTo): array
    {
        $stmt = $this->db->prepare(
            'SELECT timezone_iana FROM op_rh_v2_localidades_zonas_horarias '
            . 'WHERE id_localidad = ? AND valido_desde <= ? '
            . 'AND (valido_hasta IS NULL OR (? IS NULL AND valido_hasta IS NULL) OR (? IS NOT NULL AND ? <= valido_hasta)) '
            . 'ORDER BY valido_desde ASC'
        );
        if (!$stmt) {
            throw new RuntimeException('WORKPLACE_TIMEZONE_UNRESOLVED');
        }
        $stmt->bind_param('issss', $workplaceId, $validFrom, $validTo, $validTo, $validTo);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        if (count($rows) !== 1) {
            throw new InvalidArgumentException('WORKER_MEMBERSHIP_UNVERIFIED: Timezone authority does not uniquely cover the full assignment interval.');
        }
        $timezone = (string)$rows[0]['timezone_iana'];
        if (!$this->timezoneAuthority->isCanonicalIana($timezone)) {
            throw new InvalidArgumentException('WORKPLACE_TIMEZONE_UNRESOLVED');
        }
        $zone = new DateTimeZone($timezone);
        $startLocal = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $validFrom . ' 00:00:00', $zone);
        if (!$startLocal || $startLocal->format('Y-m-d H:i:s') !== $validFrom . ' 00:00:00') {
            throw new InvalidArgumentException('WORKPLACE_TIMEZONE_UNRESOLVED');
        }
        $fromUtc = $startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
        $toUtc = null;
        if ($validTo !== null) {
            $endLocal = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $validTo . ' 00:00:00', $zone);
            if (!$endLocal || $endLocal->format('Y-m-d H:i:s') !== $validTo . ' 00:00:00') {
                throw new InvalidArgumentException('WORKPLACE_TIMEZONE_UNRESOLVED');
            }
            $toUtc = $endLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
        }
        return [$fromUtc, $toUtc];
    }

    /** @return list<array<string,mixed>> */
    private function readCurrentMembershipRows(int $workerId, bool $forUpdate): array
    {
        $sql = 'SELECT id, worker_id, workplace_id, valid_from_utc, valid_to_utc, supersedes_id '
            . 'FROM op_rh_personal_localidad_version WHERE worker_id = ? '
            . 'AND recorded_at_utc <= UTC_TIMESTAMP(6) ORDER BY id ASC' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('WORKER_MEMBERSHIP_DEPENDENCY_UNAVAILABLE');
        }
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    private static function normalizeDatabaseUtc(string $value): ?string
    {
        if (preg_match('/\A(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?\z/D', $value, $parts) !== 1) {
            return null;
        }
        if (!self::isCivilDate($parts[1])) {
            return null;
        }
        return $parts[1] . 'T' . $parts[2] . '.' . str_pad($parts[3] ?? '', 6, '0');
    }

    private static function normalizeUtc(string $value): ?string
    {
        if (preg_match('/\A(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?Z?\z/D', $value, $parts) !== 1
            || !self::isCivilDate($parts[1])) {
            return null;
        }
        return $parts[1] . 'T' . $parts[2] . '.' . str_pad($parts[3] ?? '', 6, '0');
    }

    private static function isCivilDate(string $value): bool
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $parts) !== 1) {
            return false;
        }
        return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
    }

    private static function overlaps(string $aFrom, ?string $aTo, string $bFrom, ?string $bTo): bool
    {
        return ($aTo === null || $bFrom < $aTo) && ($bTo === null || $aFrom < $bTo);
    }
}
