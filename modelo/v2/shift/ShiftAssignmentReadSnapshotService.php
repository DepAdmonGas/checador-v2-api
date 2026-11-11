<?php

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';
require_once __DIR__ . '/ShiftWorkerMembershipService.php';

final class ShiftAssignmentReadSnapshotException extends RuntimeException
{
    public function __construct(public readonly string $apiCode, public readonly int $httpStatus, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($apiCode);
    }
}

/** Short-lived, immutable M03 assignment pages shared across API PHP workers. */
final class ShiftAssignmentReadSnapshotService
{
    private const TOKEN_HEADER = 'X-Read-Snapshot';
    private const TTL_SECONDS = 300;
    private const MAX_PAGE_SIZE = 200;
    private const MAX_PAGES = 50;
    private const MAX_RECORDS = 10000;
    private const MAX_SNAPSHOT_BYTES = 4194304;
    private const MAX_USER_SNAPSHOTS = 100;
    private const MAX_GLOBAL_SNAPSHOTS = 5000;
    private const MAX_GLOBAL_MEMBERS = 1000000;
    private const MAX_GLOBAL_BYTES = 536870912;
    private const CAPACITY_LOCK = 'checador-v2-shift-read-snapshot-capacity';

    public function __construct(private readonly mysqli $db)
    {
    }

    /** @param list<int> $authorizedStations @param list<int> $queryStations */
    public function start(
        int $actorId,
        array $authorizedStations,
        int $workerId,
        ?int $requestedStation,
        ?string $referenceDate,
        array $queryStations,
        int $page,
        int $perPage
    ): array {
        if ($page !== 1 || $actorId < 1 || $workerId < 1 || $perPage < 1 || $perPage > self::MAX_PAGE_SIZE) {
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
        }
        $filters = $this->canonicalFilters($workerId, $requestedStation, $referenceDate, $queryStations, $perPage);
        $scope = $this->canonicalScope($authorizedStations);

        $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        if (!$this->db->begin_transaction()) {
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
        }
        $lockAcquired = false;
        try {
            $membershipFingerprint = $this->membershipFingerprint($workerId);
            $items = $this->readAllAssignments($workerId, $queryStations, $referenceDate);
            $membership = new ShiftWorkerMembershipService($this->db);
            foreach ($items as $assignment) {
                if ($referenceDate !== null) {
                    $membership->assertLocalDateMembership($workerId, (int) $assignment['id_estacion'], $referenceDate);
                } elseif ((int) $assignment['es_activo'] === 0
                    && $assignment['valido_hasta'] !== null
                    && (string) $assignment['valido_hasta'] === (string) $assignment['valido_desde']) {
                    $membership->assertHistoryWithinScope($workerId, (int) $assignment['id_estacion']);
                } else {
                    $membership->assertHistoryIntervalWithinScope(
                        $workerId,
                        (int) $assignment['id_estacion'],
                        (string) $assignment['valido_desde'],
                        $assignment['valido_hasta'] !== null ? (string) $assignment['valido_hasta'] : null
                    );
                }
            }

            $count = count($items);
            if ($count > self::MAX_RECORDS) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_TOO_LARGE', 413);
            }
            $totalPages = $count === 0 ? 0 : (int) ceil($count / $perPage);
            if ($totalPages > self::MAX_PAGES) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_TOO_LARGE', 413);
            }

            $encodedItems = [];
            $payloadBytes = 0;
            foreach ($items as $item) {
                $json = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $bytes = strlen($json);
                $payloadBytes += $bytes;
                if ($payloadBytes > self::MAX_SNAPSHOT_BYTES) {
                    throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_TOO_LARGE', 413);
                }
                $encodedItems[] = [$item, $json, $bytes];
            }

            $lockResult = $this->db->query("SELECT GET_LOCK('" . self::CAPACITY_LOCK . "', 2) AS acquired");
            $lockAcquired = $lockResult && (int) ($lockResult->fetch_assoc()['acquired'] ?? 0) === 1;
            if (!$lockAcquired) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
            }
            $this->boundedPurge();
            $this->assertCapacity($actorId, $count, $payloadBytes);

            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $digest = hash('sha256', $token);
            $filtersJson = json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $scopeJson = json_encode($scope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $filterFingerprint = hash('sha256', $filtersJson);
            $scopeFingerprint = hash('sha256', $scopeJson);
            $stmt = $this->db->prepare(
                'INSERT INTO op_rh_v2_shift_assignment_read_snapshots '
                . '(token_sha256, actor_user_id, scope_fingerprint, filter_fingerprint, canonical_filters_json, membership_fingerprint, per_page, total_records, total_pages, payload_bytes, last_page_read, created_at_utc, expires_at_utc) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(6), TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6)))'
            );
            if (!$stmt) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
            }
            $ttlSeconds = self::TTL_SECONDS;
            $stmt->bind_param('sissssiiiii', $digest, $actorId, $scopeFingerprint, $filterFingerprint, $filtersJson, $membershipFingerprint, $perPage, $count, $totalPages, $payloadBytes, $ttlSeconds);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
            }
            $snapshotId = (int) $this->db->insert_id;
            $stmt->close();

            if ($encodedItems !== []) {
                $memberStmt = $this->db->prepare(
                    'INSERT INTO op_rh_v2_shift_assignment_read_snapshot_members (snapshot_id, member_order, assignment_id, payload_json, payload_bytes) VALUES (?, ?, ?, ?, ?)'
                );
                if (!$memberStmt) {
                    throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
                }
                foreach ($encodedItems as $index => [$item, $json, $bytes]) {
                    $order = $index + 1;
                    $assignmentId = (int) $item['id'];
                    $memberStmt->bind_param('iiisi', $snapshotId, $order, $assignmentId, $json, $bytes);
                    if (!$memberStmt->execute()) {
                        $memberStmt->close();
                        throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
                    }
                }
                $memberStmt->close();
            }

            $expiresResult = $this->db->query('SELECT expires_at_utc FROM op_rh_v2_shift_assignment_read_snapshots WHERE id = ' . $snapshotId . ' LIMIT 1');
            $expires = $expiresResult ? $expiresResult->fetch_assoc() : null;
            if (!$expires || empty($expires['expires_at_utc'])) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
            }
            $response = $this->readPageRows($snapshotId, 1, $perPage, $count, $totalPages, $token, (string) $expires['expires_at_utc']);
            $this->db->commit();
            return $response;
        } catch (ShiftAssignmentReadSnapshotException $exception) {
            $this->db->rollback();
            throw $exception;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
        } finally {
            if ($lockAcquired) {
                $this->db->query("SELECT RELEASE_LOCK('" . self::CAPACITY_LOCK . "')");
            }
        }
    }

    /** @param list<int> $authorizedStations */
    public function continue(
        string $token,
        int $actorId,
        array $authorizedStations,
        int $workerId,
        ?int $requestedStation,
        ?string $referenceDate,
        int $page,
        int $perPage
    ): array {
        if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) || $actorId < 1 || $page < 1 || $perPage < 1 || $perPage > self::MAX_PAGE_SIZE) {
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
        }
        $scopeJson = json_encode($this->canonicalScope($authorizedStations), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $filtersRequest = [
            'worker_id' => $workerId,
            'requested_workplace_id' => $requestedStation,
            'reference_date' => $referenceDate,
            'per_page' => $perPage,
        ];

        $this->db->begin_transaction();
        try {
            $digest = hash('sha256', $token);
            $stmt = $this->db->prepare(
                'SELECT id, actor_user_id, scope_fingerprint, filter_fingerprint, canonical_filters_json, membership_fingerprint, state, per_page, total_records, total_pages, last_page_read, expires_at_utc, (expires_at_utc <= UTC_TIMESTAMP(6)) AS is_expired '
                . 'FROM op_rh_v2_shift_assignment_read_snapshots WHERE token_sha256 = ? LIMIT 1 FOR UPDATE'
            );
            if (!$stmt) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
            }
            $stmt->bind_param('s', $digest);
            $stmt->execute();
            $snapshot = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$snapshot || (string) $snapshot['state'] !== 'READY' || (int) $snapshot['is_expired'] === 1 || (int) $snapshot['actor_user_id'] !== $actorId) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
            }
            if (!hash_equals((string) $snapshot['scope_fingerprint'], hash('sha256', $scopeJson))) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_SCOPE_CHANGED', 403);
            }
            $storedFilters = json_decode((string) $snapshot['canonical_filters_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($storedFilters)) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
            }
            $storedFingerprint = hash('sha256', json_encode($storedFilters, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if (!hash_equals((string) $snapshot['filter_fingerprint'], $storedFingerprint)
                || array_intersect_key($storedFilters, $filtersRequest) !== $filtersRequest) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
            }

            $currentMembership = $this->membershipFingerprint($workerId);
            if (!hash_equals((string) $snapshot['membership_fingerprint'], $currentMembership)) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_MEMBERSHIP_CHANGED', 409);
            }

            $lastPage = (int) $snapshot['last_page_read'];
            if ($page !== $lastPage && $page !== $lastPage + 1) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
            }
            $totalRecords = (int) $snapshot['total_records'];
            $totalPages = (int) $snapshot['total_pages'];
            if ($page > max(1, $totalPages)) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
            }
            $pageSize = (int) $snapshot['per_page'];
            if ($pageSize !== $perPage) {
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
            }
            $response = $this->readPageRows((int) $snapshot['id'], $page, $pageSize, $totalRecords, $totalPages, $token, (string) $snapshot['expires_at_utc']);
            if ($page === $lastPage + 1) {
                $update = $this->db->prepare('UPDATE op_rh_v2_shift_assignment_read_snapshots SET last_page_read = ? WHERE id = ? AND last_page_read = ?');
                $update->bind_param('iii', $page, $snapshot['id'], $lastPage);
                if (!$update->execute() || $update->affected_rows !== 1) {
                    $update->close();
                    throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
                }
                $update->close();
            }
            $this->db->commit();
            return $response;
        } catch (ShiftAssignmentReadSnapshotException $exception) {
            $this->db->rollback();
            throw $exception;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
        }
    }

    private function readAllAssignments(int $workerId, array $queryStations, ?string $referenceDate): array
    {
        if ($queryStations === [] || !array_is_list($queryStations)) {
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
        }
        $stations = array_values(array_unique(array_map('intval', $queryStations)));
        sort($stations, SORT_NUMERIC);
        if (count($stations) !== count($queryStations) || min($stations) < 1) {
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
        }
        $marks = implode(',', array_fill(0, count($stations), '?'));
        $sql = 'SELECT id, id_personal, id_estacion, id_plantilla, tipo_asignacion, dia_semana, valido_desde, valido_hasta, es_activo, registrado_at_utc '
            . 'FROM op_rh_v2_turnos_asignaciones WHERE id_personal = ? AND id_estacion IN (' . $marks . ')';
        $types = 'i' . str_repeat('i', count($stations));
        $values = array_merge([$workerId], $stations);
        if ($referenceDate !== null) {
            $sql .= ' AND valido_desde <= ? AND ((es_activo = 1 AND (valido_hasta > ? OR valido_hasta IS NULL)) OR (es_activo = 0 AND valido_hasta IS NOT NULL AND valido_hasta > valido_desde AND valido_hasta > ?))';
            $types .= 'sss';
            array_push($values, $referenceDate, $referenceDate, $referenceDate);
        }
        $sql .= ' ORDER BY valido_desde DESC, id DESC LIMIT 10001';
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
        }
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $row['id_personal'] = (int) $row['id_personal'];
            $row['id_estacion'] = (int) $row['id_estacion'];
            $row['id_plantilla'] = $row['id_plantilla'] === null ? null : (int) $row['id_plantilla'];
            $row['tipo_asignacion'] = (string) ($row['tipo_asignacion'] ?? 'HABITUAL');
            $row['es_activo'] = (int) $row['es_activo'];
            $items[] = $row;
        }
        $stmt->close();
        if (count($items) > self::MAX_RECORDS) {
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_TOO_LARGE', 413);
        }
        return $items;
    }

    private function membershipFingerprint(int $workerId): string
    {
        $stmt = $this->db->prepare(
            'SELECT id, worker_id, workplace_id, valid_from_utc, valid_to_utc, supersedes_id, recorded_at_utc '
            . 'FROM op_rh_personal_localidad_version WHERE worker_id = ? AND recorded_at_utc <= UTC_TIMESTAMP(6) ORDER BY id ASC'
        );
        if (!$stmt) {
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
        }
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function boundedPurge(): void
    {
        $idsResult = $this->db->query(
            'SELECT m.id FROM op_rh_v2_shift_assignment_read_snapshot_members m '
            . 'JOIN op_rh_v2_shift_assignment_read_snapshots s ON s.id = m.snapshot_id '
            . 'WHERE s.expires_at_utc <= UTC_TIMESTAMP(6) ORDER BY s.expires_at_utc, m.id LIMIT 1000'
        );
        if (!$idsResult) {
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
        }
        $ids = [];
        while ($row = $idsResult->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        if ($ids !== []) {
            $this->db->query('DELETE FROM op_rh_v2_shift_assignment_read_snapshot_members WHERE id IN (' . implode(',', $ids) . ')');
        }
        $this->db->query(
            'DELETE s FROM op_rh_v2_shift_assignment_read_snapshots s '
            . 'WHERE s.expires_at_utc <= UTC_TIMESTAMP(6) '
            . 'AND NOT EXISTS (SELECT 1 FROM op_rh_v2_shift_assignment_read_snapshot_members m WHERE m.snapshot_id = s.id) '
            . 'ORDER BY s.expires_at_utc LIMIT 100'
        );
    }

    private function assertCapacity(int $actorId, int $members, int $bytes): void
    {
        $live = $this->db->query('SELECT COUNT(*) AS n FROM op_rh_v2_shift_assignment_read_snapshots WHERE expires_at_utc > UTC_TIMESTAMP(6)')->fetch_assoc();
        $actor = $this->db->prepare('SELECT COUNT(*) AS n FROM op_rh_v2_shift_assignment_read_snapshots WHERE actor_user_id = ? AND expires_at_utc > UTC_TIMESTAMP(6)');
        $actor->bind_param('i', $actorId);
        $actor->execute();
        $actorCount = $actor->get_result()->fetch_assoc();
        $actor->close();
        if ((int) ($actorCount['n'] ?? 0) >= self::MAX_USER_SNAPSHOTS) {
            $oldest = $this->db->prepare('SELECT GREATEST(1, CEIL(TIMESTAMPDIFF(MICROSECOND, UTC_TIMESTAMP(6), MIN(expires_at_utc)) / 1000000)) AS retry_after FROM op_rh_v2_shift_assignment_read_snapshots WHERE actor_user_id = ? AND expires_at_utc > UTC_TIMESTAMP(6)');
            $oldest->bind_param('i', $actorId);
            $oldest->execute();
            $retryAfter = (int) ($oldest->get_result()->fetch_assoc()['retry_after'] ?? 1);
            $oldest->close();
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_LIMIT_REACHED', 429, max(1, min(self::TTL_SECONDS, $retryAfter)));
        }
        if ((int) ($live['n'] ?? 0) >= self::MAX_GLOBAL_SNAPSHOTS) {
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_CAPACITY_REACHED', 429);
        }
        $usage = $this->db->query(
            'SELECT COUNT(*) AS snapshot_count, COALESCE(SUM(payload_bytes),0) AS payload_bytes '
            . 'FROM op_rh_v2_shift_assignment_read_snapshots'
        )->fetch_assoc();
        $memberCount = $this->db->query('SELECT COUNT(*) AS n FROM op_rh_v2_shift_assignment_read_snapshot_members')->fetch_assoc();
        if ((int) ($memberCount['n'] ?? 0) + $members > self::MAX_GLOBAL_MEMBERS
            || (int) ($usage['payload_bytes'] ?? 0) + $bytes > self::MAX_GLOBAL_BYTES) {
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_CAPACITY', 503);
        }
    }

    private function readPageRows(int $snapshotId, int $page, int $perPage, int $totalRecords, int $totalPages, string $token, string $expiresAt): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(
            'SELECT member_order, assignment_id, payload_json FROM op_rh_v2_shift_assignment_read_snapshot_members '
            . 'WHERE snapshot_id = ? ORDER BY member_order LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('iii', $snapshotId, $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $item = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($item) || (int) ($item['id'] ?? 0) !== (int) $row['assignment_id']) {
                $stmt->close();
                throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
            }
            $items[] = $item;
        }
        $stmt->close();
        $expected = $totalRecords === 0 ? 0 : min($perPage, max(0, $totalRecords - $offset));
        if (count($items) !== $expected) {
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_STORAGE_UNAVAILABLE', 503);
        }
        $expires = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));
        return [
            'page' => $page,
            'per_page' => $perPage,
            'items' => $items,
            'pagination' => [
                'total_records' => $totalRecords,
                'total_pages' => $totalPages,
                'has_more' => $page < $totalPages,
                'read_snapshot' => $token,
                'expires_at_utc' => $expires->format('Y-m-d\TH:i:s.u\Z'),
            ],
        ];
    }

    private function canonicalFilters(int $workerId, ?int $stationId, ?string $date, array $queryStations, int $perPage): array
    {
        $stations = array_values(array_unique(array_map('intval', $queryStations)));
        sort($stations, SORT_NUMERIC);
        return [
            'worker_id' => $workerId,
            'requested_workplace_id' => $stationId,
            'reference_date' => $date,
            'query_workplace_ids' => $stations,
            'per_page' => $perPage,
        ];
    }

    private function canonicalScope(array $authorizedStations): array
    {
        $scope = array_values(array_unique(array_map('intval', $authorizedStations)));
        sort($scope, SORT_NUMERIC);
        if ($scope === [] || min($scope) < 1 || count($scope) !== count($authorizedStations)) {
            throw new ShiftAssignmentReadSnapshotException('READ_SNAPSHOT_INVALID_OR_EXPIRED', 409);
        }
        return $scope;
    }
}
