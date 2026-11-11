<?php

declare(strict_types=1);

require_once __DIR__ . '/WorkplaceCoveragePolicy.php';
require_once __DIR__ . '/WorkplaceCoverageFeatureGate.php';
require_once __DIR__ . '/ShiftWorkerMembershipService.php';
require_once __DIR__ . '/WorkplaceTimezoneAuthorityService.php';
require_once __DIR__ . '/WorkplaceCoverageAuthorizationService.php';
require_once __DIR__ . '/ScheduleResolutionService.php';

/**
 * API V2 read/resolution boundary for the approved Comodines coverage contract.
 * Administrative mutations are permanently denied here after the V1.2 writer cut.
 */
final class WorkplaceCoverageService
{
    private mysqli $db;
    private ShiftWorkerMembershipService $memberships;
    private WorkplaceTimezoneAuthorityService $timezones;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->memberships = new ShiftWorkerMembershipService($db);
        $this->timezones = new WorkplaceTimezoneAuthorityService($db);
    }

    public function createRequest(int $actorId, array $payload, string $idempotencyKey): array
    {
        throw new RuntimeException('COVERAGE_WRITER_PORTAL3_ONLY');
        $request = $this->normalizeRequest($payload);
        $keyHash = $this->keyHash($idempotencyKey);
        $fingerprint = WorkplaceCoveragePolicy::fingerprint('REQUEST', [
            'actor_user_id' => $actorId,
            'request' => $request,
        ]);

        $this->db->begin_transaction();
        try {
            $this->lockWorker($request['id_personal']);
            $prior = $this->findIdempotency($keyHash);
            if ($prior !== null) {
                $result = $this->assertIdempotentReplay($prior, $fingerprint, $actorId);
                $this->db->commit();
                return $result;
            }

            $this->assertCanonicalWorkplace($request['id_coverage_workplace']);
            $this->assertTargetTimezoneRange(
                $request['id_coverage_workplace'],
                $request['fecha_desde_local'],
                $request['fecha_hasta_local_exclusiva'],
                $request['timezone_iana']
            );
            $membershipId = $this->assertHomeMembership(
                $request['id_personal'],
                $request['id_home_workplace'],
                $request['fecha_desde_local'],
                $request['fecha_hasta_local_exclusiva'],
                $request['id_home_membership_version']
            );
            $request['id_home_membership_version'] = $membershipId;
            $this->assertScheduleTemplates($request['id_coverage_workplace'], $request['proposed_weekly_schedule']);
            $this->assertNoCoverageOverlap(
                $request['id_personal'],
                $request['fecha_desde_local'],
                $request['fecha_hasta_local_exclusiva']
            );

            $uuid = self::uuidV4();
            $json = json_encode($request['proposed_weekly_schedule'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $stmt = $this->db->prepare(
                "INSERT INTO op_rh_v2_worker_workplace_coverage_versions
                 (coverage_uuid, revision_no, supersedes_id, id_personal, id_home_workplace,
                  id_home_membership_version, id_coverage_workplace, id_home_physical_station,
                  id_coverage_physical_station, fecha_desde_local, fecha_hasta_local_exclusiva,
                  timezone_iana, proposed_schedule_json, status, requested_by_usuario_id,
                  requested_at_utc, approved_by_usuario_id, approved_at_utc, transition_actor_usuario_id,
                  transition_action, motivo_solicitud, motivo_transicion, idempotency_key_hash,
                  payload_fingerprint, recorded_at_utc)
                 VALUES (?, 1, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'REQUESTED', ?, UTC_TIMESTAMP(6),
                         NULL, NULL, ?, 'REQUEST', ?, NULL, ?, ?, UTC_TIMESTAMP(6))"
            );
            $requestTypes = 's' . str_repeat('i', 6) . str_repeat('s', 4) . str_repeat('i', 2) . str_repeat('s', 3);
            $stmt->bind_param(
                $requestTypes,
                $uuid,
                $request['id_personal'],
                $request['id_home_workplace'],
                $request['id_home_membership_version'],
                $request['id_coverage_workplace'],
                $request['id_home_physical_station'],
                $request['id_coverage_physical_station'],
                $request['fecha_desde_local'],
                $request['fecha_hasta_local_exclusiva'],
                $request['timezone_iana'],
                $json,
                $actorId,
                $actorId,
                $request['motivo_solicitud'],
                $keyHash,
                $fingerprint
            );
            if (!$stmt->execute()) throw new RuntimeException('COVERAGE_WRITE_FAILED');
            $id = (int)$this->db->insert_id;
            $stmt->close();
            $this->writeCoverageAudit($id, $actorId, 'REQUEST', $request, ['coverage_uuid' => $uuid, 'revision_no' => 1]);
            $result = $this->readById($id);
            if ($result === null) throw new RuntimeException('COVERAGE_READBACK_FAILED');
            $this->db->commit();
            return ['created' => true, 'coverage' => $result];
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) {
                $replay = $this->findIdempotency($keyHash);
                if ($replay !== null) return $this->assertIdempotentReplay($replay, $fingerprint, $actorId);
                throw new RuntimeException('COVERAGE_CONFLICT');
            }
            throw $e;
        }
    }

    /** Stable keyset read. The first response's max ID bounds every subsequent page. */
    public function listVisible(int $actorId, int $positionId, array $scope, int $limit = 50, ?int $snapshotId = null, ?int $beforeId = null): array
    {
        $this->assertReady();
        if ($limit < 1 || $limit > 50 || ($snapshotId !== null && $snapshotId < 1) || ($beforeId !== null && $beforeId < 1)) {
            throw new InvalidArgumentException('INVALID_COVERAGE_PAGE');
        }
        $scope = array_values(array_unique(array_map('intval', $scope)));
        if ($scope === []) throw new RuntimeException('COVERAGE_SCOPE_DENIED');
        if ($snapshotId === null) {
            $row = $this->db->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM op_rh_v2_worker_workplace_coverage_versions')->fetch_assoc();
            $snapshotId = (int)($row['max_id'] ?? 0);
        }
        $placeholders = implode(',', array_fill(0, count($scope), '?'));
        $types = 'i' . str_repeat('i', count($scope));
        $params = [$snapshotId, ...$scope];
        $scopeSql = "c.id_home_workplace IN ({$placeholders})";
        if ($positionId === 13) {
            $scopeSql .= " AND c.id_coverage_workplace IN ({$placeholders})";
            $types .= str_repeat('i', count($scope));
            array_push($params, ...$scope);
        } elseif ($positionId === 6) {
            $scopeSql .= ' AND c.requested_by_usuario_id = ?';
            $types .= 'i';
            $params[] = $actorId;
        } else {
            throw new RuntimeException('COVERAGE_SCOPE_DENIED');
        }
        $cursorSql = '';
        if ($beforeId !== null) {
            $cursorSql = ' AND c.id < ?';
            $types .= 'i';
            $params[] = $beforeId;
        }
        $types .= 'i';
        $params[] = $limit + 1;
        $sql = "SELECT c.* FROM op_rh_v2_worker_workplace_coverage_versions c
                JOIN (SELECT coverage_uuid, MAX(id) AS id FROM op_rh_v2_worker_workplace_coverage_versions
                      WHERE id <= ? GROUP BY coverage_uuid) latest ON latest.id = c.id
                WHERE {$scopeSql}{$cursorSql}
                ORDER BY c.id DESC LIMIT ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('COVERAGE_READ_FAILED');
        self::bindDynamic($stmt, $types, $params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        foreach ($rows as &$row) $row = $this->normalizeRow($row);
        unset($row);
        $last = $rows === [] ? null : (int)$rows[count($rows) - 1]['id'];
        return [
            'items' => $rows,
            'snapshot_id' => $snapshotId,
            'next_before_id' => $hasMore ? $last : null,
            'has_more' => $hasMore,
        ];
    }

    public function getVisible(string $coverageUuid, int $actorId, int $positionId, array $scope): ?array
    {
        $this->assertReady();
        if (!self::isUuid($coverageUuid)) throw new InvalidArgumentException('INVALID_COVERAGE_UUID');
        $stmt = $this->db->prepare(
            'SELECT c.* FROM op_rh_v2_worker_workplace_coverage_versions c
             JOIN (SELECT MAX(id) AS id FROM op_rh_v2_worker_workplace_coverage_versions
                   WHERE coverage_uuid = ?) latest ON latest.id = c.id'
        );
        $stmt->bind_param('s', $coverageUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        if (!WorkplaceCoveragePolicy::scopeAllows(
            $positionId,
            WorkplaceCoveragePolicy::READ,
            $scope,
            (int)$row['id_home_workplace'],
            (int)$row['id_coverage_workplace']
        )) throw new RuntimeException('COVERAGE_SCOPE_DENIED');
        if ($positionId === 6 && ((int)$row['requested_by_usuario_id'] !== $actorId)) {
            throw new RuntimeException('COVERAGE_SCOPE_DENIED');
        }
        $this->assertHomeMembership(
            (int)$row['id_personal'],
            (int)$row['id_home_workplace'],
            (string)$row['fecha_desde_local'],
            (string)$row['fecha_hasta_local_exclusiva'],
            (int)$row['id_home_membership_version']
        );
        $historyStmt = $this->db->prepare(
            'SELECT * FROM op_rh_v2_worker_workplace_coverage_versions WHERE coverage_uuid = ? ORDER BY revision_no ASC'
        );
        if (!$historyStmt) throw new RuntimeException('COVERAGE_READ_FAILED');
        $historyStmt->bind_param('s', $coverageUuid);
        $historyStmt->execute();
        $historyRows = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $historyStmt->close();
        foreach ($historyRows as &$historyRow) {
            $historyRow = $this->normalizeRow($historyRow);
            $auditStmt = $this->db->prepare(
                "SELECT id, operacion, id_usuario_actor, id_estacion, id_personal, valido_desde,
                        valido_hasta, id_plantilla, version_plantilla, detalle_json, recorded_at_utc
                 FROM op_rh_v2_turnos_audit_log
                 WHERE entity_type = 'WORKPLACE_COVERAGE' AND entity_id = ? ORDER BY id ASC"
            );
            if (!$auditStmt) throw new RuntimeException('COVERAGE_READ_FAILED');
            $revisionId = (int)$historyRow['id'];
            $auditStmt->bind_param('i', $revisionId);
            $auditStmt->execute();
            $historyRow['audit_events'] = $auditStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $auditStmt->close();
        }
        unset($historyRow);
        $row = $this->normalizeRow($row);
        $row['revision_history'] = $historyRows;
        return $row;
    }

    /** Resolve minimum row context only after the caller has authenticated and checked the action grant. */
    public function transitionScope(string $coverageUuid): ?array
    {
        $this->assertReady();
        if (!self::isUuid($coverageUuid)) throw new InvalidArgumentException('INVALID_COVERAGE_UUID');
        $row = $this->readLatestByUuid($coverageUuid, false);
        if ($row === null) return null;
        return [
            'id_home_workplace' => (int)$row['id_home_workplace'],
            'id_coverage_workplace' => (int)$row['id_coverage_workplace'],
            'status' => (string)$row['status'],
        ];
    }

    public function transition(
        string $coverageUuid,
        string $action,
        int $actorId,
        string $idempotencyKey,
        string $reason = ''
    ): array {
        throw new RuntimeException('COVERAGE_WRITER_PORTAL3_ONLY');
        $action = strtoupper(trim($action));
        if (!in_array($action, ['AUTHORIZE', 'REJECT', 'CANCEL'], true)) throw new InvalidArgumentException('INVALID_COVERAGE_TRANSITION');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) throw new InvalidArgumentException('COVERAGE_REASON_REQUIRED');
        $keyHash = $this->keyHash($idempotencyKey);

        $this->db->begin_transaction();
        try {
            $current = $this->readLatestByUuid($coverageUuid, true);
            if ($current === null) throw new RuntimeException('COVERAGE_NOT_FOUND');
            $this->lockWorker((int)$current['id_personal']);
            $prior = $this->findIdempotency($keyHash);
            if ($prior !== null) {
                $fingerprint = WorkplaceCoveragePolicy::fingerprint($action, [
                    'actor_user_id' => $actorId,
                    'coverage_uuid' => $coverageUuid,
                    'source_revision_id' => (int)($prior['supersedes_id'] ?? 0),
                    'reason' => $reason,
                ]);
                $result = $this->assertIdempotentReplay($prior, $fingerprint, $actorId);
                $this->db->commit();
                return $result;
            }
            $fingerprint = WorkplaceCoveragePolicy::fingerprint($action, [
                'actor_user_id' => $actorId,
                'coverage_uuid' => $coverageUuid,
                'source_revision_id' => (int)$current['id'],
                'reason' => $reason,
            ]);
            $expectedStatus = $action === 'AUTHORIZE' || $action === 'REJECT' ? 'REQUESTED' : 'AUTHORIZED';
            if ((string)$current['status'] !== $expectedStatus) throw new RuntimeException('COVERAGE_STATE_CONFLICT');
            $this->assertHomeMembership(
                (int)$current['id_personal'],
                (int)$current['id_home_workplace'],
                (string)$current['fecha_desde_local'],
                (string)$current['fecha_hasta_local_exclusiva'],
                (int)$current['id_home_membership_version']
            );
            $to = (string)$current['fecha_hasta_local_exclusiva'];
            $assignmentCloseAt = null;
            if ($action === 'AUTHORIZE') {
                $this->assertTargetTimezoneRange(
                    (int)$current['id_coverage_workplace'],
                    (string)$current['fecha_desde_local'],
                    $to,
                    (string)$current['timezone_iana']
                );
                $today = $this->workplaceToday((int)$current['id_coverage_workplace']);
                if ((string)$current['fecha_desde_local'] <= $today) throw new RuntimeException('COVERAGE_NOT_FUTURE');
                $this->assertScheduleTemplates((int)$current['id_coverage_workplace'], $this->decodeSchedule((string)$current['proposed_schedule_json']));
                $this->assertNoCoverageOverlap(
                    (int)$current['id_personal'],
                    (string)$current['fecha_desde_local'],
                    $to,
                    (string)$current['coverage_uuid']
                );
                $this->assertNoTargetTemporalConflict(
                    (int)$current['id_personal'],
                    (int)$current['id_coverage_workplace'],
                    (string)$current['fecha_desde_local'],
                    $to
                );
                $this->assertNoMaterializedSchedules(
                    (int)$current['id_personal'],
                    (string)$current['fecha_desde_local'],
                    $to
                );
            } elseif ($action === 'CANCEL') {
                $today = $this->workplaceToday((int)$current['id_coverage_workplace']);
                if ((string)$current['fecha_desde_local'] <= $today) {
                    $cutoff = (new DateTimeImmutable($today, new DateTimeZone((string)$current['timezone_iana'])))->modify('+1 day')->format('Y-m-d');
                    if ($cutoff >= $to) throw new RuntimeException('COVERAGE_NO_FUTURE_SEGMENT');
                    $to = $cutoff;
                    $assignmentCloseAt = $cutoff;
                } else {
                    $assignmentCloseAt = (string)$current['fecha_desde_local'];
                }
                // Materialized dates are immutable. Closing only future assignment rows must not
                // leave an already materialized or evaluated schedule pointing at a cancelled source.
                $this->assertNoMaterializedSchedules(
                    (int)$current['id_personal'],
                    (string)$assignmentCloseAt,
                    (string)$current['fecha_hasta_local_exclusiva']
                );
            }

            if ($action === 'AUTHORIZE' && !WorkplaceCoveragePolicy::approvalActorsAllowed(
                (int)($current['requested_by_usuario_id'] ?? 0),
                $actorId
            )) {
                throw new RuntimeException('COVERAGE_STATE_CONFLICT');
            }

            $newStatus = match ($action) {
                'AUTHORIZE' => 'AUTHORIZED',
                'REJECT' => 'REJECTED',
                'CANCEL' => 'CANCELLED',
            };
            $newId = $this->insertRevision($current, $newStatus, $action, $actorId, $reason, $keyHash, $fingerprint, $to);
            if ($action === 'AUTHORIZE') {
                $this->createLinkedAssignments($newId, $current, $actorId);
            } elseif ($action === 'CANCEL') {
                $this->closeLinkedAssignments(
                    (int)$current['id'],
                    $newId,
                    $current,
                    (string)$assignmentCloseAt,
                    $actorId
                );
            }
            $this->writeCoverageAudit($newId, $actorId, $action, $current, [
                'coverage_uuid' => $coverageUuid,
                'source_revision_id' => (int)$current['id'],
                'revision_no' => (int)$current['revision_no'] + 1,
                'motivo' => $reason,
                'effective_to_local_exclusive' => $to,
                'cancel_effective_from_local' => $action === 'CANCEL' ? $assignmentCloseAt : null,
            ]);
            $result = $this->readById($newId);
            if ($result === null) throw new RuntimeException('COVERAGE_READBACK_FAILED');
            $this->db->commit();
            return ['created' => true, 'coverage' => $result];
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) {
                $prior = $this->findIdempotency($keyHash);
                if ($prior !== null) {
                    $fingerprint = WorkplaceCoveragePolicy::fingerprint($action, [
                        'actor_user_id' => $actorId,
                        'coverage_uuid' => $coverageUuid,
                        'source_revision_id' => (int)($prior['supersedes_id'] ?? 0),
                        'reason' => $reason,
                    ]);
                    return $this->assertIdempotentReplay($prior, $fingerprint, $actorId);
                }
                throw new RuntimeException('COVERAGE_CONFLICT');
            }
            throw $e;
        }
    }

    public function resolveLatestForDate(int $workerId, string $localDate): array
    {
        $this->assertReady();
        if ($workerId < 1 || !self::isDate($localDate)) return ['status' => 'UNKNOWN'];
        $stmt = $this->db->prepare(
            'SELECT current.* FROM op_rh_v2_worker_workplace_coverage_versions current
             JOIN (SELECT coverage_uuid, MAX(id) AS id FROM op_rh_v2_worker_workplace_coverage_versions GROUP BY coverage_uuid) latest
               ON latest.id = current.id
             WHERE current.id_personal = ? AND current.fecha_desde_local <= ?
               AND current.fecha_hasta_local_exclusiva > ?
               AND current.status IN (\'AUTHORIZED\', \'CANCELLED\')'
        );
        $stmt->bind_param('iss', $workerId, $localDate, $localDate);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $effective = [];
        foreach ($rows as $row) {
            if ((string)$row['status'] === 'AUTHORIZED') {
                $effective[] = $row;
                continue;
            }
            $cut = $this->readCancellationEffectiveFrom((int)$row['id']);
            if ($cut === null || $localDate >= $cut) continue;
            $prior = $this->readById((int)$row['supersedes_id']);
            if (is_array($prior) && (string)$prior['status'] === 'AUTHORIZED') $effective[] = $prior;
        }
        if (count($effective) > 1) return ['status' => 'UNKNOWN', 'reason' => 'COVERAGE_CONFLICT'];
        if ($effective === []) return ['status' => 'NO_COVERAGE'];
        $coverage = $this->normalizeRow($effective[0]);
        $coverage['assignments'] = $this->readLinkedAssignments((int)$coverage['id'], $localDate);
        return ['status' => 'AUTHORIZED', 'coverage' => $coverage];
    }

    /** Addendum opt-in resolution; ordinary ScheduleResolutionService is not changed. */
    public function resolveWorkerEffective(int $workerId, string $localDate, array $authenticatedUser): array
    {
        $this->assertReady();
        if ($workerId < 1 || !self::isDate($localDate)) throw new InvalidArgumentException('INVALID_RESOLUTION_SPECIFICATION');
        $home = $this->resolveHomeMembershipAtDate($workerId, $localDate);
        if (($home['status'] ?? '') !== 'RESOLVED') return [
            'id_personal' => $workerId,
            'fecha_local' => $localDate,
            'estado_jornada' => 'UNKNOWN',
            'reason' => 'WORKPLACE_MEMBERSHIP_UNVERIFIED',
        ];
        $homeId = (int)$home['workplace_id'];
        $membershipId = (int)$home['membership_id'];
        $coverageResult = $this->resolveLatestForDate($workerId, $localDate);
        if (($coverageResult['status'] ?? '') === 'UNKNOWN') return [
            'id_personal' => $workerId,
            'id_estacion' => $homeId,
            'fecha_local' => $localDate,
            'estado_jornada' => 'UNKNOWN',
            'reason' => (string)($coverageResult['reason'] ?? 'COVERAGE_DATA_INCOMPLETE'),
        ];
        if (($coverageResult['status'] ?? '') === 'NO_COVERAGE') {
            $this->memberships->assertLocalDateMembership($workerId, $homeId, $localDate);
            $base = (new ScheduleResolutionService($this->db))->resolveSchedule($workerId, $homeId, $localDate);
            $base['resolution_context'] = 'WORKER_EFFECTIVE';
            $base['resolution_source'] = 'HOME_ASSIGNMENT';
            $base['home_workplace_id'] = $homeId;
            $base['effective_workplace_id'] = $homeId;
            $base['home_membership_version_id'] = $membershipId;
            return $base;
        }

        $coverage = $coverageResult['coverage'];
        if ((int)$coverage['id_home_workplace'] !== $homeId || (int)$coverage['id_home_membership_version'] !== $membershipId) {
            return ['id_personal' => $workerId, 'fecha_local' => $localDate, 'estado_jornada' => 'UNKNOWN', 'reason' => 'COVERAGE_HOME_MEMBERSHIP_MISMATCH'];
        }
        $authz = WorkplaceCoverageAuthorizationService::authorize(
            $this->db,
            $authenticatedUser,
            WorkplaceCoveragePolicy::READ,
            $homeId,
            (int)$coverage['id_coverage_workplace']
        );
        if (empty($authz['success'])) throw new RuntimeException((string)$authz['status']);
        $dateTimezone = $this->timezones->resolve((int)$coverage['id_coverage_workplace'], $localDate);
        if ($dateTimezone !== (string)$coverage['timezone_iana']) return [
            'id_personal' => $workerId,
            'fecha_local' => $localDate,
            'estado_jornada' => 'UNKNOWN',
            'reason' => 'COVERAGE_TIMEZONE_MISMATCH',
        ];
        $choice = WorkplaceCoveragePolicy::chooseEffectiveSource(
            $workerId,
            $localDate,
            $homeId,
            [$coverage]
        );
        if (($choice['status'] ?? '') === 'UNKNOWN') return [
            'id_personal' => $workerId,
            'fecha_local' => $localDate,
            'estado_jornada' => 'UNKNOWN',
            'reason' => 'COVERAGE_ASSIGNMENT_MISMATCH',
        ];
        $provenance = [
            'coverage_uuid' => (string)$coverage['coverage_uuid'],
            'coverage_revision_id' => (int)$coverage['id'],
            'coverage_revision_no' => (int)$coverage['revision_no'],
            'status' => (string)$coverage['status'],
            'approved_by_usuario_id' => $coverage['approved_by_usuario_id'],
            'home_workplace_id' => $homeId,
            'effective_workplace_id' => (int)$coverage['id_coverage_workplace'],
            'home_membership_version_id' => $membershipId,
            'assignment_id' => $choice['assignment_id'],
            'fecha_local' => $localDate,
            'timezone_iana' => $dateTimezone,
        ];
        if (($choice['status'] ?? '') === 'DAY_OFF') return [
            'id_personal' => $workerId,
            'id_estacion' => (int)$coverage['id_coverage_workplace'],
            'fecha_local' => $localDate,
            'estado_jornada' => 'DAY_OFF',
            'origen' => ['id_asignacion' => $choice['assignment_id'], 'tipo_asignacion' => 'TEMPORAL', 'id_plantilla' => null],
            'resolution_context' => 'WORKER_EFFECTIVE',
            'resolution_source' => 'TEMPORARY_COVERAGE',
            'coverage' => $provenance,
        ];
        $resolved = (new ScheduleResolutionService($this->db))->resolveSchedule($workerId, (int)$coverage['id_coverage_workplace'], $localDate);
        if (($resolved['estado_jornada'] ?? '') === 'UNKNOWN'
            || (int)($resolved['origen']['id_asignacion'] ?? 0) !== (int)$choice['assignment_id']) {
            return ['id_personal' => $workerId, 'id_estacion' => (int)$coverage['id_coverage_workplace'], 'fecha_local' => $localDate, 'estado_jornada' => 'UNKNOWN', 'reason' => 'COVERAGE_RESOLUTION_MISMATCH'];
        }
        $resolved['resolution_context'] = 'WORKER_EFFECTIVE';
        $resolved['resolution_source'] = 'TEMPORARY_COVERAGE';
        $resolved['home_workplace_id'] = $homeId;
        $resolved['effective_workplace_id'] = (int)$coverage['id_coverage_workplace'];
        $resolved['home_membership_version_id'] = $membershipId;
        $resolved['coverage'] = $provenance;
        return $resolved;
    }

    private function createLinkedAssignments(int $coverageRevisionId, array $coverage, int $actorId): void
    {
        $schedule = $this->decodeSchedule((string)$coverage['proposed_schedule_json']);
        foreach ($schedule as $entry) {
            $templateId = $entry['id_plantilla'];
            $templateVersion = $entry['version_plantilla'];
            $day = $entry['dia_semana'];
            $reason = 'WORKPLACE_COVERAGE:' . (string)$coverage['coverage_uuid'] . ':' . ((int)$coverage['revision_no'] + 1);
            $stmt = $this->db->prepare(
                'SELECT id FROM op_rh_v2_turnos_asignaciones
                 WHERE id_personal = ? AND id_estacion = ? AND dia_semana = ? AND tipo_asignacion = \'TEMPORAL\'
                   AND es_activo = 1 AND valido_desde < ? AND (valido_hasta IS NULL OR valido_hasta > ?)
                 FOR UPDATE'
            );
            $workerId = (int)$coverage['id_personal'];
            $workplaceId = (int)$coverage['id_coverage_workplace'];
            $from = (string)$coverage['fecha_desde_local'];
            $to = (string)$coverage['fecha_hasta_local_exclusiva'];
            $stmt->bind_param('iisss', $workerId, $workplaceId, $day, $to, $from);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt->close();
                throw new RuntimeException('COVERAGE_ASSIGNMENT_CONFLICT');
            }
            $stmt->close();
            $templateIdForDb = $templateId;
            $assignmentType = 'TEMPORAL';
            $stmt = $this->db->prepare(
                'INSERT INTO op_rh_v2_turnos_asignaciones
                 (id_personal, id_estacion, id_plantilla, tipo_asignacion, dia_semana, valido_desde,
                  valido_hasta, es_activo, registrado_at_utc, creado_por_usuario_id, motivo, id_cobertura_version)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), ?, ?, ?)'
            );
            $stmt->bind_param(
                'iiissssisi',
                $workerId,
                $workplaceId,
                $templateIdForDb,
                $assignmentType,
                $day,
                $from,
                $to,
                $actorId,
                $reason,
                $coverageRevisionId
            );
            if (!$stmt->execute()) throw new RuntimeException('COVERAGE_ASSIGNMENT_WRITE_FAILED');
            $assignmentId = (int)$this->db->insert_id;
            $stmt->close();
            $this->writeAssignmentAudit($assignmentId, $coverageRevisionId, $coverage, $actorId, 'COVERAGE_ASSIGN');
            if ($entry['tipo'] === 'WORK') {
                $this->assertAssignmentTemplateVersion($assignmentId, (int)$templateId, (int)$templateVersion);
            }
        }
        $this->assertLinkedAssignmentReadback($coverageRevisionId);
    }

    private function assertAssignmentTemplateVersion(int $assignmentId, int $templateId, int $version): void
    {
        $stmt = $this->db->prepare(
            'SELECT a.id FROM op_rh_v2_turnos_asignaciones a
             JOIN op_rh_v2_turnos_plantillas p ON p.id = a.id_plantilla
             WHERE a.id = ? AND p.id = ? AND p.version = ? LIMIT 1'
        );
        $stmt->bind_param('iii', $assignmentId, $templateId, $version);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        if (!$exists) throw new RuntimeException('COVERAGE_ASSIGNMENT_READBACK_FAILED');
    }

    private function closeLinkedAssignments(int $authorizedRevisionId, int $cancellationRevisionId, array $coverage, string $closeAt, int $actorId): void
    {
        $select = $this->db->prepare(
            'SELECT id FROM op_rh_v2_turnos_asignaciones WHERE id_cobertura_version = ? AND es_activo = 1 FOR UPDATE'
        );
        $select->bind_param('i', $authorizedRevisionId);
        $select->execute();
        $assignmentIds = array_map('intval', array_column($select->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
        $select->close();
        if (count($assignmentIds) !== 7) throw new RuntimeException('COVERAGE_ASSIGNMENT_READBACK_FAILED');
        $stmt = $this->db->prepare(
            'UPDATE op_rh_v2_turnos_asignaciones SET es_activo = 0, valido_hasta = ?, actualizado_por_usuario_id = ?,
                motivo = CONCAT(COALESCE(motivo, \'\'), \' | COVERAGE_CANCELLED\')
             WHERE id_cobertura_version = ? AND es_activo = 1'
        );
        // Pre-start cancellations close at the assignment start; after-start cancellations preserve the past.
        $stmt->bind_param('sii', $closeAt, $actorId, $authorizedRevisionId);
        if (!$stmt->execute() || $stmt->affected_rows !== count($assignmentIds)) {
            $stmt->close();
            throw new RuntimeException('COVERAGE_ASSIGNMENT_CLOSE_FAILED');
        }
        $stmt->close();
        foreach ($assignmentIds as $assignmentId) {
            $this->writeAssignmentAudit($assignmentId, $cancellationRevisionId, $coverage, $actorId, 'COVERAGE_CANCEL');
        }
    }

    private function writeAssignmentAudit(int $assignmentId, int $coverageId, array $coverage, int $actorId, string $operation): void
    {
        $details = json_encode([
            'coverage_revision_id' => $coverageId,
            'coverage_uuid' => (string)$coverage['coverage_uuid'],
            'assignment_id' => $assignmentId,
            'source' => 'WORKPLACE_COVERAGE',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare(
            "INSERT INTO op_rh_v2_turnos_audit_log
             (entity_type, entity_id, operacion, id_usuario_actor, id_estacion, id_personal,
              tipo_asignacion, valido_desde, valido_hasta, id_plantilla, version_plantilla, detalle_json, recorded_at_utc)
             SELECT 'WORKPLACE_COVERAGE', ?, ?, ?, ?, ?, 'TEMPORAL', a.valido_desde, a.valido_hasta,
                    a.id_plantilla, p.version, ?, UTC_TIMESTAMP()
             FROM op_rh_v2_turnos_asignaciones a
             LEFT JOIN op_rh_v2_turnos_plantillas p ON p.id = a.id_plantilla WHERE a.id = ?"
        );
        $stmt->bind_param(
            'isiiisi',
            $coverageId,
            $operation,
            $actorId,
            $coverage['id_coverage_workplace'],
            $coverage['id_personal'],
            $details,
            $assignmentId
        );
        if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new RuntimeException('COVERAGE_AUDIT_WRITE_FAILED');
        $stmt->close();
    }

    private function writeCoverageAudit(int $coverageId, int $actorId, string $operation, array $coverage, array $details): void
    {
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $this->db->prepare(
            "INSERT INTO op_rh_v2_turnos_audit_log
             (entity_type, entity_id, operacion, id_usuario_actor, id_estacion, id_personal,
              valido_desde, valido_hasta, detalle_json, recorded_at_utc)
             VALUES ('WORKPLACE_COVERAGE', ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        $stmt->bind_param(
            'isiiisss',
            $coverageId,
            $operation,
            $actorId,
            $coverage['id_coverage_workplace'],
            $coverage['id_personal'],
            $coverage['fecha_desde_local'],
            $coverage['fecha_hasta_local_exclusiva'],
            $json
        );
        if (!$stmt->execute()) throw new RuntimeException('COVERAGE_AUDIT_WRITE_FAILED');
        $stmt->close();
    }

    private function insertRevision(array $current, string $status, string $action, int $actorId, string $reason, string $keyHash, string $fingerprint, string $effectiveTo): int
    {
        $revisionNo = (int)$current['revision_no'] + 1;
        $approvedActor = $status === 'AUTHORIZED' ? $actorId : ($current['approved_by_usuario_id'] !== null ? (int)$current['approved_by_usuario_id'] : null);
        $approvedAt = $status === 'AUTHORIZED' ? gmdate('Y-m-d H:i:s') : ($current['approved_at_utc'] ?? null);
        $stmt = $this->db->prepare(
            'INSERT INTO op_rh_v2_worker_workplace_coverage_versions
             (coverage_uuid, revision_no, supersedes_id, id_personal, id_home_workplace,
              id_home_membership_version, id_coverage_workplace, id_home_physical_station,
              id_coverage_physical_station, fecha_desde_local, fecha_hasta_local_exclusiva,
              timezone_iana, proposed_schedule_json, status, requested_by_usuario_id,
              requested_at_utc, approved_by_usuario_id, approved_at_utc, transition_actor_usuario_id,
              transition_action, motivo_solicitud, motivo_transicion, idempotency_key_hash,
              payload_fingerprint, recorded_at_utc)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))'
        );
        $uuid = (string)$current['coverage_uuid'];
        $sourceId = (int)$current['id'];
        $worker = (int)$current['id_personal'];
        $home = (int)$current['id_home_workplace'];
        $membership = (int)$current['id_home_membership_version'];
        $destination = (int)$current['id_coverage_workplace'];
        $homePhysical = $current['id_home_physical_station'] !== null ? (int)$current['id_home_physical_station'] : null;
        $destinationPhysical = $current['id_coverage_physical_station'] !== null ? (int)$current['id_coverage_physical_station'] : null;
        $from = (string)$current['fecha_desde_local'];
        $timezone = (string)$current['timezone_iana'];
        $scheduleJson = is_string($current['proposed_schedule_json']) ? $current['proposed_schedule_json'] : json_encode($current['proposed_weekly_schedule']);
        $requestedBy = (int)$current['requested_by_usuario_id'];
        $requestedAt = (string)$current['requested_at_utc'];
        $reasonRequest = (string)$current['motivo_solicitud'];
        $transitionTypes = 's' . str_repeat('i', 8) . str_repeat('s', 5) . 'i' . 's' . 'i' . 's' . 'i' . str_repeat('s', 5);
        $stmt->bind_param(
            $transitionTypes,
            $uuid,
            $revisionNo,
            $sourceId,
            $worker,
            $home,
            $membership,
            $destination,
            $homePhysical,
            $destinationPhysical,
            $from,
            $effectiveTo,
            $timezone,
            $scheduleJson,
            $status,
            $requestedBy,
            $requestedAt,
            $approvedActor,
            $approvedAt,
            $actorId,
            $action,
            $reasonRequest,
            $reason,
            $keyHash,
            $fingerprint
        );
        if (!$stmt->execute()) throw new RuntimeException('COVERAGE_WRITE_FAILED');
        $id = (int)$this->db->insert_id;
        $stmt->close();
        return $id;
    }

    private function normalizeRequest(array $payload): array
    {
        $worker = filter_var($payload['id_personal'] ?? null, FILTER_VALIDATE_INT);
        $home = filter_var($payload['id_home_workplace'] ?? null, FILTER_VALIDATE_INT);
        $membership = filter_var($payload['id_home_membership_version'] ?? null, FILTER_VALIDATE_INT);
        $target = filter_var($payload['id_coverage_workplace'] ?? null, FILTER_VALIDATE_INT);
        $from = trim((string)($payload['fecha_desde_local'] ?? ''));
        $to = trim((string)($payload['fecha_hasta_local_exclusiva'] ?? ''));
        $timezone = trim((string)($payload['timezone_iana'] ?? ''));
        $reason = trim((string)($payload['motivo_solicitud'] ?? ''));
        if ($worker === false || $worker < 1 || $home === false || $home < 1 || $membership === false || $membership < 1
            || $target === false || $target < 1 || $home === $target || !self::isDate($from) || !self::isDate($to) || $to <= $from
            || $reason === '' || mb_strlen($reason) > 500 || $timezone !== 'America/Mexico_City') {
            throw new InvalidArgumentException('INVALID_COVERAGE_REQUEST');
        }
        $schedule = WorkplaceCoveragePolicy::normalizeWeeklySchedule($payload['proposed_weekly_schedule'] ?? null);
        return [
            'id_personal' => (int)$worker,
            'id_home_workplace' => (int)$home,
            'id_home_membership_version' => (int)$membership,
            'id_coverage_workplace' => (int)$target,
            // Physical-station IDs are intentionally not accepted from the client. Null is
            // valid in the approved schema; a later authoritative resolver may populate them.
            'id_home_physical_station' => null,
            'id_coverage_physical_station' => null,
            'fecha_desde_local' => $from,
            'fecha_hasta_local_exclusiva' => $to,
            'timezone_iana' => $timezone,
            'proposed_weekly_schedule' => $schedule,
            'motivo_solicitud' => $reason,
        ];
    }

    private function assertScheduleTemplates(int $workplaceId, array $schedule): void
    {
        foreach ($schedule as $entry) {
            if ($entry['tipo'] !== 'WORK') continue;
            $templateId = (int)$entry['id_plantilla'];
            $version = (int)$entry['version_plantilla'];
            $stmt = $this->db->prepare(
                "SELECT id, version, scope, id_estacion, es_activo FROM op_rh_v2_turnos_plantillas
                 WHERE id = ? AND version = ? AND es_activo = 1 AND
                   (scope = 'GLOBAL' OR (scope = 'STATION' AND id_estacion = ?)) LIMIT 1 FOR UPDATE"
            );
            $stmt->bind_param('iii', $templateId, $version, $workplaceId);
            $stmt->execute();
            $template = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$template) throw new RuntimeException('COVERAGE_TEMPLATE_UNAVAILABLE');
            $segmentStmt = $this->db->prepare(
                'SELECT ordinal, tipo_segmento, hora_inicio_local, hora_fin_local, dia_offset_inicio, dia_offset_fin
                 FROM op_rh_v2_turnos_segmentos WHERE id_plantilla = ? ORDER BY ordinal'
            );
            $segmentStmt->bind_param('i', $templateId);
            $segmentStmt->execute();
            $segments = $segmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $segmentStmt->close();
            if (!WorkplaceCoveragePolicy::validTemplateSegments($segments)) throw new RuntimeException('COVERAGE_TEMPLATE_UNAVAILABLE');
        }
    }

    private function assertHomeMembership(int $workerId, int $homeId, string $from, string $to, ?int $expectedMembershipId): int
    {
        $this->memberships->lockAndAssertCoverage($workerId, $homeId, $from, $to);
        $timezoneFrom = $this->timezones->resolve($homeId, $from);
        $lastDate = (new DateTimeImmutable($to, new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
        $timezoneTo = $this->timezones->resolve($homeId, $lastDate);
        if ($timezoneFrom !== $timezoneTo) throw new RuntimeException('WORKER_MEMBERSHIP_UNVERIFIED');
        $fromUtc = self::localBoundaryUtc($from, $timezoneFrom);
        $toUtc = self::localBoundaryUtc($to, $timezoneTo);
        $stmt = $this->db->prepare(
            'SELECT id, worker_id, workplace_id, valid_from_utc, valid_to_utc, supersedes_id
             FROM op_rh_personal_localidad_version WHERE worker_id = ? AND recorded_at_utc <= UTC_TIMESTAMP(6) ORDER BY id'
        );
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $history = ShiftWorkerMembershipService::validateMembershipHistory($workerId, $rows);
        if (empty($history['valid'])) throw new RuntimeException('WORKER_MEMBERSHIP_UNVERIFIED');
        $decision = ShiftWorkerMembershipService::evaluateCoverage($workerId, $homeId, $fromUtc, $toUtc, $rows);
        if (empty($decision['covered'])) throw new RuntimeException('WORKER_MEMBERSHIP_UNVERIFIED');
        $membershipId = (int)$decision['membership_id'];
        if ($expectedMembershipId !== null && $membershipId !== $expectedMembershipId) {
            throw new RuntimeException('WORKER_MEMBERSHIP_VERSION_MISMATCH');
        }
        return $membershipId;
    }

    private function resolveHomeMembershipAtDate(int $workerId, string $localDate): array
    {
        $worker = $this->db->prepare('SELECT id FROM op_rh_personal WHERE id = ? AND estado = 1 LIMIT 1');
        $worker->bind_param('i', $workerId);
        $worker->execute();
        $active = $worker->get_result()->num_rows === 1;
        $worker->close();
        if (!$active) return ['status' => 'UNKNOWN'];
        $stmt = $this->db->prepare(
            'SELECT id, worker_id, workplace_id, valid_from_utc, valid_to_utc, supersedes_id
             FROM op_rh_personal_localidad_version WHERE worker_id = ? AND recorded_at_utc <= UTC_TIMESTAMP(6) ORDER BY id'
        );
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $history = ShiftWorkerMembershipService::validateMembershipHistory($workerId, $rows);
        if (empty($history['valid'])) return ['status' => 'UNKNOWN'];
        $nextDate = (new DateTimeImmutable($localDate, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        $matches = [];
        foreach ($history['leaves'] as $membership) {
            $workplaceId = (int)$membership['workplace_id'];
            try {
                $timezone = $this->timezones->resolve($workplaceId, $localDate);
                $nextTimezone = $this->timezones->resolve($workplaceId, $nextDate);
            } catch (Throwable) {
                continue;
            }
            if ($timezone !== $nextTimezone) continue;
            $fromUtc = self::localBoundaryUtc($localDate, $timezone);
            $toUtc = self::localBoundaryUtc($nextDate, $timezone);
            $validFrom = str_replace(' ', 'T', (string)$membership['valid_from_utc']);
            $validTo = $membership['valid_to_utc'] === null ? null : str_replace(' ', 'T', (string)$membership['valid_to_utc']);
            if ($validFrom <= $fromUtc && ($validTo === null || $validTo >= $toUtc)) {
                $matches[] = ['workplace_id' => $workplaceId, 'membership_id' => (int)$membership['id']];
            }
        }
        if (count($matches) !== 1) return ['status' => 'UNKNOWN'];
        return ['status' => 'RESOLVED', ...$matches[0]];
    }

    private function readCancellationEffectiveFrom(int $coverageRevisionId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT detalle_json FROM op_rh_v2_turnos_audit_log
             WHERE entity_type = 'WORKPLACE_COVERAGE' AND entity_id = ? AND operacion = 'CANCEL'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('i', $coverageRevisionId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) return null;
        $details = json_decode((string)($row['detalle_json'] ?? ''), true);
        $effectiveFrom = is_array($details) ? (string)($details['cancel_effective_from_local'] ?? '') : '';
        return self::isDate($effectiveFrom) ? $effectiveFrom : null;
    }

    private function assertTargetTimezoneRange(int $workplaceId, string $from, string $to, string $expected): void
    {
        $stmt = $this->db->prepare(
            'SELECT timezone_iana, valido_desde, valido_hasta FROM op_rh_v2_localidades_zonas_horarias
             WHERE id_localidad = ? AND valido_desde < ? AND (valido_hasta IS NULL OR valido_hasta > ?)
             ORDER BY valido_desde'
        );
        $stmt->bind_param('iss', $workplaceId, $to, $from);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $cursor = $from;
        $sawRange = false;
        foreach ($rows as $row) {
            $rowFrom = (string)$row['valido_desde'];
            $rowTo = $row['valido_hasta'] === null ? null : (string)$row['valido_hasta'];
            if ($rowFrom > $cursor || ($sawRange && $rowFrom < $cursor) || (string)$row['timezone_iana'] !== $expected) {
                throw new RuntimeException('WORKPLACE_TIMEZONE_UNRESOLVED');
            }
            $sawRange = true;
            if ($rowTo === null || $rowTo >= $to) {
                $cursor = $to;
                break;
            }
            if ($rowTo <= $cursor) throw new RuntimeException('WORKPLACE_TIMEZONE_UNRESOLVED');
            $cursor = $rowTo;
        }
        if (!$sawRange || $cursor < $to || $expected !== 'America/Mexico_City') throw new RuntimeException('WORKPLACE_TIMEZONE_UNRESOLVED');
    }

    private function assertCanonicalWorkplace(int $workplaceId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM op_rh_localidades WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $workplaceId);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        if (!$found) throw new RuntimeException('WORKPLACE_NOT_FOUND');
    }

    private function assertNoCoverageOverlap(int $workerId, string $from, string $to, ?string $exceptUuid = null): void
    {
        $sql = "SELECT c.id FROM op_rh_v2_worker_workplace_coverage_versions c
                JOIN (SELECT coverage_uuid, MAX(id) AS id FROM op_rh_v2_worker_workplace_coverage_versions GROUP BY coverage_uuid) latest
                  ON latest.id = c.id
                WHERE c.id_personal = ? AND c.status IN ('REQUESTED','AUTHORIZED')
                  AND c.fecha_desde_local < ? AND c.fecha_hasta_local_exclusiva > ?";
        $types = 'iss'; $params = [$workerId, $to, $from];
        if ($exceptUuid !== null) { $sql .= ' AND c.coverage_uuid <> ?'; $types .= 's'; $params[] = $exceptUuid; }
        $sql .= ' FOR UPDATE';
        $stmt = $this->db->prepare($sql);
        self::bindDynamic($stmt, $types, $params);
        $stmt->execute();
        $conflict = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($conflict) throw new RuntimeException('COVERAGE_OVERLAP_CONFLICT');
    }

    private function assertNoTargetTemporalConflict(int $workerId, int $workplaceId, string $from, string $to): void
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM op_rh_v2_turnos_asignaciones
             WHERE id_personal = ? AND id_estacion = ? AND tipo_asignacion = 'TEMPORAL' AND es_activo = 1
               AND valido_desde < ? AND (valido_hasta IS NULL OR valido_hasta > ?) FOR UPDATE"
        );
        $stmt->bind_param('iiss', $workerId, $workplaceId, $to, $from);
        $stmt->execute();
        $conflict = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($conflict) throw new RuntimeException('COVERAGE_ASSIGNMENT_CONFLICT');
    }

    private function assertNoMaterializedSchedules(int $workerId, string $from, string $to): void
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM op_rh_v2_jornadas_programadas WHERE id_personal = ? AND fecha_jornada_local >= ? AND fecha_jornada_local < ? LIMIT 1 FOR UPDATE'
        );
        $stmt->bind_param('iss', $workerId, $from, $to);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($exists) throw new RuntimeException('COVERAGE_CONSOLIDATED_DATE_CONFLICT');
    }

    private function readLinkedAssignments(int $coverageRevisionId, string $date): array
    {
        $weekday = self::weekday($date);
        $stmt = $this->db->prepare(
            'SELECT a.id, a.id_personal, a.id_estacion, a.id_plantilla, a.tipo_asignacion,
                    a.dia_semana, a.valido_desde, a.valido_hasta, a.es_activo,
                    p.version AS version_plantilla
             FROM op_rh_v2_turnos_asignaciones a
             LEFT JOIN op_rh_v2_turnos_plantillas p ON p.id = a.id_plantilla
             WHERE a.id_cobertura_version = ? AND a.dia_semana = ?
               AND a.valido_desde <= ? AND (a.valido_hasta IS NULL OR ? < a.valido_hasta)'
        );
        $stmt->bind_param('isss', $coverageRevisionId, $weekday, $date, $date);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function assertLinkedAssignmentReadback(int $revisionId): void
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS n FROM op_rh_v2_turnos_asignaciones WHERE id_cobertura_version = ? AND tipo_asignacion = \'TEMPORAL\' AND es_activo = 1');
        $stmt->bind_param('i', $revisionId);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        if ($count !== 7) throw new RuntimeException('COVERAGE_ASSIGNMENT_READBACK_FAILED');
    }

    private function readLatestByUuid(string $uuid, bool $forUpdate): ?array
    {
        $sql = 'SELECT * FROM op_rh_v2_worker_workplace_coverage_versions WHERE coverage_uuid = ? ORDER BY revision_no DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('s', $uuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function readById(int $id): ?array
    {
        if ($id < 1) return null;
        $stmt = $this->db->prepare('SELECT * FROM op_rh_v2_worker_workplace_coverage_versions WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row === null ? null : $this->normalizeRow($row);
    }

    private function findIdempotency(string $keyHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM op_rh_v2_worker_workplace_coverage_versions WHERE idempotency_key_hash = ? LIMIT 1'
        );
        $stmt->bind_param('s', $keyHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function assertIdempotentReplay(array $row, string $fingerprint, int $actorId): array
    {
        if (!hash_equals((string)$row['payload_fingerprint'], $fingerprint)
            || (int)$row['transition_actor_usuario_id'] !== $actorId) {
            throw new RuntimeException('IDEMPOTENCY_KEY_CONFLICT');
        }
        $latest = $this->readLatestByUuid((string)$row['coverage_uuid'], false);
        if ($latest === null) throw new RuntimeException('COVERAGE_READBACK_FAILED');
        return ['created' => false, 'coverage' => $this->normalizeRow($latest)];
    }

    private function normalizeRow(array $row): array
    {
        foreach (['id','revision_no','supersedes_id','id_personal','id_home_workplace','id_home_membership_version',
            'id_coverage_workplace','id_home_physical_station','id_coverage_physical_station',
            'requested_by_usuario_id','approved_by_usuario_id','transition_actor_usuario_id'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) $row[$field] = (int)$row[$field];
        }
        $row['proposed_weekly_schedule'] = $this->decodeSchedule((string)$row['proposed_schedule_json']);
        return $row;
    }

    private function decodeSchedule(string $json): array
    {
        $value = json_decode($json, true);
        return WorkplaceCoveragePolicy::normalizeWeeklySchedule($value);
    }

    private function lockWorker(int $workerId): void
    {
        $stmt = $this->db->prepare('SELECT id FROM op_rh_personal WHERE id = ? AND estado = 1 FOR UPDATE');
        $stmt->bind_param('i', $workerId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        if (!$exists) throw new RuntimeException('WORKER_NOT_ACTIVE');
    }

    private function assertReady(): void
    {
        WorkplaceCoverageFeatureGate::assertReady($this->db);
    }

    private function workplaceToday(int $workplaceId): string
    {
        $utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $lookupDate = $utcNow->format('Y-m-d');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $timezone = $this->timezones->resolve($workplaceId, $lookupDate);
            $localDate = $utcNow->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
            if ($localDate === $lookupDate) return $localDate;
            $lookupDate = $localDate;
        }
        throw new RuntimeException('WORKPLACE_TIMEZONE_UNRESOLVED');
    }

    private function keyHash(string $key): string
    {
        if ($key === '' || strlen($key) > 200) throw new InvalidArgumentException('IDEMPOTENCY_KEY_REQUIRED');
        return hash('sha256', $key);
    }

    private static function localBoundaryUtc(string $date, string $timezone): string
    {
        $dt = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone($timezone));
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u');
    }

    private static function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/i', $value) === 1;
    }

    private static function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function weekday(string $date): string
    {
        $index = (int)(new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('N');
        return ['LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO'][$index - 1];
    }

    private static function bindDynamic(mysqli_stmt $stmt, string $types, array &$values): void
    {
        $args = [$types];
        foreach ($values as &$value) $args[] = &$value;
        if (!call_user_func_array([$stmt, 'bind_param'], $args)) {
            throw new RuntimeException('COVERAGE_READ_FAILED');
        }
    }
}
