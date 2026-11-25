<?php

declare(strict_types=1);
require_once __DIR__ . '/PunchEventV2.php';
require_once __DIR__ . '/WorkplacePhysicalStationResolver.php';
require_once __DIR__ . '/shift/ShiftWorkerMembershipService.php';

/** Initial native roster identities; all identity writers must use the canonical device lock. */
final class InitialDeviceIdentityProvisioner
{
    public function __construct(private mysqli $db) {}

    public static function classify(array $history, int $deviceId, int $overlapCount): string
    {
        $current = array_values(array_filter($history, static fn($row) => (int)$row['id_dispositivo'] === $deviceId && (int)$row['es_activo'] === 1 && (int)$row['vigente'] === 1));
        if (count($current) === 1) {
            $ref = (string)$current[0]['referencia_externa_personal'];
            return $ref !== '' && $ref === trim($ref) && strlen($ref) <= 64 && $overlapCount === 0 ? 'preserve' : 'conflict';
        }
        return count($history) === 0 ? 'create' : 'conflict';
    }

    /** Validate the same base64 template bounds used by the read-only biometric roster without returning template data. */
    public static function hasCompatibleBiometricTemplate(array $templates): bool
    {
        if (count($templates) !== 1 || !is_string($templates[0] ?? null) || $templates[0] === '') return false;
        $decoded = base64_decode($templates[0], true);
        return $decoded !== false && strlen($decoded) > 0 && strlen($decoded) <= 2048;
    }

    /**
     * Safely pre-provision exactly one worker on the named preproduction database.
     * The caller must have already obtained explicit operator approval; dry-run always rolls back.
     */
    public function runSelectedPreproductionWorker(bool $apply, int $deviceId, int $stationId, int $workerId, string $expectedDatabase, string $confirmation = ''): array
    {
        if ($expectedDatabase !== 'bd_admongas_v2_preprod' || $deviceId !== 2 || $stationId !== 1 || $workerId < 1) {
            throw new InvalidArgumentException('INVALID_PREPRODUCTION_SCOPE');
        }
        $expectedConfirmation = $expectedDatabase . ':' . $deviceId . ':' . $stationId . ':' . $workerId;
        if ($apply && ((string)getenv('CHECADOR_ENABLE_IDENTITY_PROVISIONING_PREPROD') !== '1' || !hash_equals($expectedConfirmation, $confirmation))) {
            throw new RuntimeException('PREPRODUCTION_APPLY_NOT_CONFIRMED');
        }
        $actual = $this->db->query('SELECT DATABASE() AS database_name')->fetch_assoc();
        if (($actual['database_name'] ?? '') !== $expectedDatabase) throw new RuntimeException('DATABASE_IDENTITY_MISMATCH');

        $db = $this->db;
        $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->begin_transaction();
        try {
            foreach ([
                'op_rh_v2_dispositivos', 'op_rh_v2_dispositivo_credenciales', 'op_rh_v2_device_biometric_grants',
                'tb_estaciones', 'op_rh_localidades', 'op_rh_personal', 'op_rh_personal_localidad_version',
                'op_rh_v2_dispositivo_identidad_personal',
            ] as $table) {
                self::requireInnoDb($db, $table);
            }

            // Match the canonical writer's first lock and serialize against all API identity writers.
            $stmt = $db->prepare('SELECT id, id_estacion, estado FROM op_rh_v2_dispositivos WHERE id=? FOR UPDATE');
            $stmt->bind_param('i', $deviceId); $stmt->execute(); $device = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$device || (int)$device['estado'] !== 1 || (int)$device['id_estacion'] !== $stationId) throw new RuntimeException('DEVICE_STATION_NOT_READY');

            $stmt = $db->prepare("SELECT id FROM op_rh_v2_dispositivo_credenciales WHERE id_dispositivo=? AND estado='ACTIVE' AND revocado_at IS NULL AND valido_desde<=UTC_TIMESTAMP(6) AND (valido_hasta IS NULL OR valido_hasta>UTC_TIMESTAMP(6)) FOR UPDATE");
            $stmt->bind_param('i', $deviceId); $stmt->execute(); $credentials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            if (count($credentials) !== 1) throw new RuntimeException('ACTIVE_DEVICE_CREDENTIAL_REQUIRED');
            $credentialId = (int)$credentials[0]['id'];

            $stmt = $db->prepare('SELECT id FROM op_rh_v2_device_biometric_grants WHERE id_dispositivo=? AND id_estacion=? AND id_credencial=? AND activo=1 AND revocado_at_utc IS NULL AND valido_desde_utc<=UTC_TIMESTAMP(6) AND (valido_hasta_utc IS NULL OR valido_hasta_utc>UTC_TIMESTAMP(6)) FOR UPDATE');
            $stmt->bind_param('iii', $deviceId, $stationId, $credentialId); $stmt->execute(); $grants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            if (count($grants) !== 1) throw new RuntimeException('ACTIVE_BIOMETRIC_GRANT_REQUIRED');

            $stmt = $db->prepare('SELECT id,numlista,nombre,estatus FROM tb_estaciones WHERE id=? FOR UPDATE');
            $stmt->bind_param('i', $stationId); $stmt->execute(); $station = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$station || (int)$station['estatus'] !== 0 || trim((string)$station['nombre']) === '' || (int)$station['numlista'] < 1) throw new RuntimeException('PHYSICAL_STATION_NOT_READY');
            $numlista = (int)$station['numlista'];
            $stmt = $db->prepare('SELECT id FROM tb_estaciones WHERE numlista=? ORDER BY id FOR UPDATE');
            $stmt->bind_param('i', $numlista); $stmt->execute(); $stationRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            $stmt = $db->prepare('SELECT id FROM op_rh_localidades WHERE numlista=? ORDER BY id FOR UPDATE');
            $stmt->bind_param('i', $numlista); $stmt->execute(); $localityRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            $workplaceId = WorkplacePhysicalStationResolver::resolveCanonicalLocality($db, $stationId);
            if (count($stationRows) !== 1 || (int)$stationRows[0]['id'] !== $stationId || count($localityRows) !== 1 || $workplaceId !== (int)$localityRows[0]['id']) throw new RuntimeException('STATION_LOCALITY_MAPPING_AMBIGUOUS');

            // This legacy station column is also part of the currently deployed roster filter.
            $stmt = $db->prepare('SELECT id FROM op_rh_personal WHERE id=? AND estado=1 AND id_estacion=? FOR UPDATE');
            $stmt->bind_param('ii', $workerId, $stationId); $stmt->execute(); $worker = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$worker) throw new RuntimeException('ACTIVE_WORKER_NOT_ON_DEVICE_STATION');

            $stmt = $db->prepare('SELECT id,worker_id,workplace_id,valid_from_utc,valid_to_utc,supersedes_id FROM op_rh_personal_localidad_version WHERE worker_id=? AND recorded_at_utc<=UTC_TIMESTAMP(6) ORDER BY id FOR UPDATE');
            $stmt->bind_param('i', $workerId); $stmt->execute(); $memberships = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            $clock = $db->query('SELECT UTC_TIMESTAMP(6) AS now_utc')->fetch_assoc();
            if (!self::hasCurrentCanonicalMembership($workerId, $workplaceId, (string)($clock['now_utc'] ?? ''), $memberships)) throw new RuntimeException('CURRENT_CANONICAL_WORKER_MEMBERSHIP_REQUIRED');

            // Read only the one template needed to validate roster compatibility; never return or log it.
            $stmt = $db->prepare('SELECT huella FROM op_rh_personal_acceso WHERE id_personal=? ORDER BY id FOR UPDATE');
            $stmt->bind_param('i', $workerId); $stmt->execute(); $templateRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            $templates = array_map(static fn(array $row): mixed => $row['huella'], $templateRows);
            if (!self::hasCompatibleBiometricTemplate($templates)) throw new RuntimeException('ONE_COMPATIBLE_BIOMETRIC_TEMPLATE_REQUIRED');

            $stmt = $db->prepare('SELECT id,id_dispositivo,referencia_externa_personal,valido_desde,valido_hasta,es_activo,(valido_desde<=UTC_TIMESTAMP(6) AND (valido_hasta IS NULL OR valido_hasta>UTC_TIMESTAMP(6))) AS vigente FROM op_rh_v2_dispositivo_identidad_personal WHERE id_personal=? ORDER BY id FOR UPDATE');
            $stmt->bind_param('i', $workerId); $stmt->execute(); $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            $current = array_values(array_filter($history, static fn(array $row): bool => (int)$row['id_dispositivo']===$deviceId && (int)$row['es_activo']===1 && (int)$row['vigente']===1));
            $overlapCount = 0;
            if (count($current) === 1) {
                $ref = (string)$current[0]['referencia_externa_personal']; $identityId = (int)$current[0]['id']; $end = $current[0]['valido_hasta']; $start = (string)$current[0]['valido_desde'];
                $stmt = $db->prepare("SELECT COUNT(*) AS n FROM op_rh_v2_dispositivo_identidad_personal WHERE id_dispositivo=? AND referencia_externa_personal=? AND id<>? AND valido_desde<COALESCE(?, '9999-12-31 23:59:59') AND COALESCE(valido_hasta,'9999-12-31 23:59:59')>?");
                $stmt->bind_param('isiss', $deviceId, $ref, $identityId, $end, $start); $stmt->execute(); $overlapCount = (int)$stmt->get_result()->fetch_assoc()['n']; $stmt->close();
            }
            $decision = self::classifySelectedWorkerHistory($history, $deviceId, $overlapCount);
            if ($decision === 'conflict') throw new RuntimeException('IDENTITY_HISTORY_CONFLICT');
            $result = ['active_workers' => 1, 'conservar' => $decision === 'preserve' ? 1 : 0, 'primera_identidad' => $decision === 'create' ? 1 : 0, 'rechazar' => 0, 'insertadas' => 0, 'worker_id' => $workerId, 'physical_station_id' => $stationId, 'canonical_workplace_id' => $workplaceId];

            if ($decision === 'create' && $apply) {
                $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
                $hex = bin2hex($bytes); $uuid = substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
                $now = (string)$db->query('SELECT UTC_TIMESTAMP(6) AS t')->fetch_assoc()['t'];
                $write = (new PunchEventV2())->registrarIdentidadPersonal($db, $deviceId, $uuid, $workerId, $now, null, true);
                if (empty($write['success'])) throw new RuntimeException('IDENTITY_INSERT_FAILED');
                $identityId = (int)($write['id'] ?? 0);
                $stmt = $db->prepare('SELECT id_personal,id_dispositivo,referencia_externa_personal,es_activo,valido_desde,valido_hasta,(valido_desde<=UTC_TIMESTAMP(6) AND (valido_hasta IS NULL OR valido_hasta>UTC_TIMESTAMP(6))) AS vigente FROM op_rh_v2_dispositivo_identidad_personal WHERE id=? FOR UPDATE');
                $stmt->bind_param('i', $identityId); $stmt->execute(); $stored = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$stored || (int)$stored['id_personal']!==$workerId || (int)$stored['id_dispositivo']!==$deviceId || $stored['referencia_externa_personal']!==$uuid || (int)$stored['es_activo']!==1 || (int)$stored['vigente']!==1 || $stored['valido_hasta']!==null) throw new RuntimeException('IDENTITY_READBACK_FAILED');
                $result['insertadas'] = 1;
            }

            if ($apply) $db->commit(); else $db->rollback();
            return $result;
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    private static function requireInnoDb(mysqli $db, string $table): void
    {
        $stmt = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->bind_param('s', $table); $stmt->execute(); $engine = $stmt->get_result()->fetch_assoc()['ENGINE'] ?? ''; $stmt->close();
        if (strtoupper((string)$engine) !== 'INNODB') throw new RuntimeException('TRANSACTIONAL_TABLE_REQUIRED');
    }

    private static function hasCurrentCanonicalMembership(int $workerId, int $workplaceId, string $nowUtc, array $rows): bool
    {
        $history = ShiftWorkerMembershipService::validateMembershipHistory($workerId, $rows);
        if (empty($history['valid']) || $workplaceId < 1) return false;
        try { $now = (new DateTimeImmutable($nowUtc, new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.u'); }
        catch (Throwable) { return false; }
        $current = [];
        foreach ($history['leaves'] as $row) {
            if ($row['valid_from_utc'] <= $now && ($row['valid_to_utc'] === null || $now < $row['valid_to_utc'])) $current[] = $row;
        }
        return count($current) === 1 && (int)$current[0]['workplace_id'] === $workplaceId;
    }

    private static function classifySelectedWorkerHistory(array $history, int $deviceId, int $overlapCount): string
    {
        $current = array_values(array_filter($history, static fn(array $row): bool => (int)$row['id_dispositivo']===$deviceId && (int)$row['es_activo']===1 && (int)$row['vigente']===1));
        if (count($current) === 1) {
            $reference = (string)$current[0]['referencia_externa_personal'];
            return $reference !== '' && $reference === trim($reference) && strlen($reference) <= 64 && $overlapCount === 0 ? 'preserve' : 'conflict';
        }
        return count($history) === 0 ? 'create' : 'conflict';
    }

    public function run(bool $apply, int $deviceId, int $stationId, bool $allowPartial = false, bool $existingTransaction = false): array
    {
        $db = $this->db;
        if ($deviceId < 1 || $stationId < 1) throw new InvalidArgumentException('INVALID_SCOPE');
        if (!$existingTransaction) {
            $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $db->begin_transaction();
        } elseif ((int)$db->query('SELECT @@in_transaction AS active')->fetch_assoc()['active'] !== 1) {
            throw new RuntimeException('TRANSACTION_REQUIRED');
        }
        try {
            foreach (['op_rh_v2_dispositivos', 'op_rh_personal', 'op_rh_v2_dispositivo_identidad_personal'] as $table) {
                $stmt = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $stmt->bind_param('s', $table);
                $stmt->execute();
                $engine = $stmt->get_result()->fetch_assoc()['ENGINE'] ?? '';
                $stmt->close();
                if (strtoupper((string)$engine) !== 'INNODB') throw new RuntimeException('TRANSACTIONAL_TABLE_REQUIRED');
            }
            $deviceStmt = $db->prepare('SELECT id, id_estacion, estado FROM op_rh_v2_dispositivos WHERE id = ? FOR UPDATE');
            $deviceStmt->bind_param('i', $deviceId); $deviceStmt->execute(); $device = $deviceStmt->get_result()->fetch_assoc(); $deviceStmt->close();
            if (!$device || (int)$device['id_estacion'] !== $stationId || (int)$device['estado'] !== 1) throw new RuntimeException('DEVICE_STATION_NOT_READY');
            $workerStmt = $db->prepare('SELECT id FROM op_rh_personal WHERE estado = 1 AND id_estacion = ? ORDER BY id FOR UPDATE');
            $workerStmt->bind_param('i', $stationId); $workerStmt->execute(); $workers = $workerStmt->get_result()->fetch_all(MYSQLI_ASSOC); $workerStmt->close();
            $counts = ['active_workers' => count($workers), 'conservar' => 0, 'primera_identidad' => 0, 'rechazar' => 0, 'insertadas' => 0];
            $history = $db->prepare('SELECT id, id_dispositivo, referencia_externa_personal, valido_desde, valido_hasta, es_activo, (valido_desde <= UTC_TIMESTAMP() AND (valido_hasta IS NULL OR valido_hasta > UTC_TIMESTAMP())) AS vigente FROM op_rh_v2_dispositivo_identidad_personal WHERE id_personal = ? ORDER BY id FOR UPDATE');
            $overlap = $db->prepare("SELECT COUNT(*) AS n FROM op_rh_v2_dispositivo_identidad_personal WHERE id_dispositivo = ? AND referencia_externa_personal = ? AND id <> ? AND valido_desde < COALESCE(?, '9999-12-31 23:59:59') AND COALESCE(valido_hasta, '9999-12-31 23:59:59') > ?");
            $writer = new PunchEventV2();
            foreach ($workers as $worker) {
                $workerId = (int)$worker['id'];
                $history->bind_param('i', $workerId); $history->execute();
                $rows = $history->get_result()->fetch_all(MYSQLI_ASSOC);
                $current = array_values(array_filter($rows, static fn($r) => (int)$r['id_dispositivo'] === $deviceId && (int)$r['es_activo'] === 1 && (int)$r['vigente'] === 1));
                $conflicts = 0;
                if (count($current) === 1) {
                    $row = $current[0];
                    $ref = (string)$row['referencia_externa_personal']; $id = (int)$row['id']; $end = $row['valido_hasta']; $start = (string)$row['valido_desde'];
                    $overlap->bind_param('isiss', $deviceId, $ref, $id, $end, $start); $overlap->execute();
                    $conflicts = (int)$overlap->get_result()->fetch_assoc()['n'];
                }
                $decision = self::classify($rows, $deviceId, $conflicts);
                if ($decision === 'preserve') { $counts['conservar']++; continue; }
                if ($decision === 'conflict') { $counts['rechazar']++; continue; }
                $counts['primera_identidad']++;
                if ($apply) {
                    $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
                    $hex = bin2hex($bytes); $uuid = substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
                    $now = (string)$db->query('SELECT UTC_TIMESTAMP() AS t')->fetch_assoc()['t'];
                    $result = $writer->registrarIdentidadPersonal($db, $deviceId, $uuid, $workerId, $now, null, true);
                    if (empty($result['success'])) throw new RuntimeException('IDENTITY_INSERT_FAILED');
                    $identityId = (int)($result['id'] ?? 0);
                    $readback = $db->prepare('SELECT id_personal, id_dispositivo, referencia_externa_personal, es_activo FROM op_rh_v2_dispositivo_identidad_personal WHERE id=?');
                    $readback->bind_param('i', $identityId); $readback->execute(); $stored = $readback->get_result()->fetch_assoc(); $readback->close();
                    if (!$stored || (int)$stored['id_personal'] !== $workerId || (int)$stored['id_dispositivo'] !== $deviceId || $stored['referencia_externa_personal'] !== $uuid || (int)$stored['es_activo'] !== 1) throw new RuntimeException('IDENTITY_READBACK_FAILED');
                    $counts['insertadas']++;
                }
            }
            if ($apply && !$allowPartial && $counts['rechazar'] !== 0) throw new RuntimeException('IDENTITY_CONFLICT');
            if (!$existingTransaction) { if ($apply) $db->commit(); else $db->rollback(); }
            return $counts;
        } catch (Throwable $e) {
            if (!$existingTransaction) $db->rollback();
            throw $e;
        }
    }
}
