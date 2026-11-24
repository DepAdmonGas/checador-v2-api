<?php

declare(strict_types=1);
require_once __DIR__ . '/PunchEventV2.php';

/** Initial native roster identities; all identity writers must use the canonical device lock. */
final class InitialDeviceIdentityProvisioner
{
    public function __construct(private mysqli $db) {}

    public function run(bool $apply): array
    {
        $db = $this->db;
        $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->begin_transaction();
        try {
            foreach (['op_rh_v2_dispositivos', 'op_rh_personal', 'op_rh_v2_dispositivo_identidad_personal'] as $table) {
                $stmt = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $stmt->bind_param('s', $table);
                $stmt->execute();
                $engine = $stmt->get_result()->fetch_assoc()['ENGINE'] ?? '';
                $stmt->close();
                if (strtoupper((string)$engine) !== 'INNODB') throw new RuntimeException('TRANSACTIONAL_TABLE_REQUIRED');
            }
            $device = $db->query('SELECT id, id_estacion, estado FROM op_rh_v2_dispositivos WHERE id = 2 FOR UPDATE')->fetch_assoc();
            if (!$device || (int)$device['id_estacion'] !== 1 || (int)$device['estado'] !== 1) throw new RuntimeException('DEVICE_STATION_NOT_READY');
            $workers = $db->query('SELECT id FROM op_rh_personal WHERE estado = 1 AND id_estacion = 1 ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
            $counts = ['active_workers' => count($workers), 'conservar' => 0, 'primera_identidad' => 0, 'rechazar' => 0, 'insertadas' => 0];
            $history = $db->prepare('SELECT id, id_dispositivo, referencia_externa_personal, valido_desde, valido_hasta, es_activo, (valido_desde <= UTC_TIMESTAMP() AND (valido_hasta IS NULL OR valido_hasta > UTC_TIMESTAMP())) AS vigente FROM op_rh_v2_dispositivo_identidad_personal WHERE id_personal = ? ORDER BY id FOR UPDATE');
            $overlap = $db->prepare("SELECT COUNT(*) AS n FROM op_rh_v2_dispositivo_identidad_personal WHERE id_dispositivo = 2 AND referencia_externa_personal = ? AND id <> ? AND valido_desde < COALESCE(?, '9999-12-31 23:59:59') AND COALESCE(valido_hasta, '9999-12-31 23:59:59') > ?");
            $writer = new PunchEventV2();
            foreach ($workers as $worker) {
                $workerId = (int)$worker['id'];
                $history->bind_param('i', $workerId); $history->execute();
                $rows = $history->get_result()->fetch_all(MYSQLI_ASSOC);
                $current = array_values(array_filter($rows, static fn($r) => (int)$r['id_dispositivo'] === 2 && (int)$r['es_activo'] === 1 && (int)$r['vigente'] === 1));
                if (count($current) === 1) {
                    $row = $current[0];
                    $ref = (string)$row['referencia_externa_personal']; $id = (int)$row['id']; $end = $row['valido_hasta']; $start = (string)$row['valido_desde'];
                    $overlap->bind_param('siss', $ref, $id, $end, $start); $overlap->execute();
                    $conflicts = (int)$overlap->get_result()->fetch_assoc()['n'];
                    if ($ref !== '' && $ref === trim($ref) && strlen($ref) <= 64 && $conflicts === 0) { $counts['conservar']++; continue; }
                }
                if (count($rows) !== 0) { $counts['rechazar']++; continue; }
                $counts['primera_identidad']++;
                if ($apply) {
                    $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
                    $hex = bin2hex($bytes); $uuid = substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
                    $now = (string)$db->query('SELECT UTC_TIMESTAMP() AS t')->fetch_assoc()['t'];
                    $result = $writer->registrarIdentidadPersonal($db, 2, $uuid, $workerId, $now, null, true);
                    if (empty($result['success'])) throw new RuntimeException('IDENTITY_INSERT_FAILED');
                    $counts['insertadas']++;
                }
            }
            if ($apply && $counts['rechazar'] !== 0) throw new RuntimeException('IDENTITY_CONFLICT');
            if ($apply) $db->commit(); else $db->rollback();
            return $counts;
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
