<?php
/**
 * Checador V2 — canonical, effective-dated Workplace timezone authority.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../BD.php';

class WorkplaceTimezoneAuthorityService
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        if ($db !== null) {
            $this->db = $db;
        } else {
            $bdObj = new BD();
            $this->db = $bdObj->conectarBD();
        }
    }

    /**
     * Resolve exactly one authority interval for a local work date.
     * Missing, ambiguous, invalid, or non-canonical values fail closed.
     */
    public function resolve(int $idLocalidad, string $fechaLocal): string
    {
        if ($idLocalidad <= 0 || !$this->isDate($fechaLocal)) {
            throw new RuntimeException('WORKPLACE_TIMEZONE_UNRESOLVED');
        }

        $stmt = $this->db->prepare(
            'SELECT timezone_iana, valido_desde, valido_hasta
             FROM op_rh_v2_localidades_zonas_horarias
             WHERE id_localidad = ?
               AND valido_desde <= ?
               AND (valido_hasta IS NULL OR ? < valido_hasta)
             ORDER BY valido_desde ASC'
        );
        $stmt->bind_param('iss', $idLocalidad, $fechaLocal, $fechaLocal);
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }

        if (count($rows) !== 1) {
            throw new RuntimeException('WORKPLACE_TIMEZONE_UNRESOLVED');
        }

        $timezone = (string)$rows[0]['timezone_iana'];
        if (!$this->isCanonicalIana($timezone)) {
            throw new RuntimeException('WORKPLACE_TIMEZONE_UNRESOLVED');
        }

        return $timezone;
    }

    /**
     * Writer boundary for future administrative callers. It locks the
     * canonical locality row before validating all effective intervals.
     */
    public function createMapping(
        int $idLocalidad,
        string $timezoneIana,
        string $validoDesde,
        ?string $validoHasta = null
    ): int {
        if ($idLocalidad <= 0 || !$this->isDate($validoDesde)
            || ($validoHasta !== null && !$this->isDate($validoHasta))
            || ($validoHasta !== null && $validoHasta <= $validoDesde)
            || !$this->isCanonicalIana($timezoneIana)
        ) {
            throw new InvalidArgumentException('INVALID_WORKPLACE_TIMEZONE_MAPPING');
        }

        $this->db->begin_transaction();
        try {
            $stmtLocalidad = $this->db->prepare(
                'SELECT id FROM op_rh_localidades WHERE id = ? FOR UPDATE'
            );
            $stmtLocalidad->bind_param('i', $idLocalidad);
            $stmtLocalidad->execute();
            if ($stmtLocalidad->get_result()->num_rows !== 1) {
                throw new InvalidArgumentException('WORKPLACE_NOT_FOUND');
            }

            $stmtExisting = $this->db->prepare(
                'SELECT id, valido_desde, valido_hasta
                 FROM op_rh_v2_localidades_zonas_horarias
                 WHERE id_localidad = ?
                 FOR UPDATE'
            );
            $stmtExisting->bind_param('i', $idLocalidad);
            $stmtExisting->execute();
            $existing = $stmtExisting->get_result();
            while ($row = $existing->fetch_assoc()) {
                $existingStartsBeforeNewEnds = $validoHasta === null || $row['valido_desde'] < $validoHasta;
                $newStartsBeforeExistingEnds = $row['valido_hasta'] === null || $validoDesde < $row['valido_hasta'];
                if ($existingStartsBeforeNewEnds && $newStartsBeforeExistingEnds) {
                    throw new RuntimeException('WORKPLACE_TIMEZONE_OVERLAP_CONFLICT');
                }
            }

            $stmtInsert = $this->db->prepare(
                'INSERT INTO op_rh_v2_localidades_zonas_horarias
                 (id_localidad, timezone_iana, valido_desde, valido_hasta)
                 VALUES (?, ?, ?, ?)'
            );
            $stmtInsert->bind_param('isss', $idLocalidad, $timezoneIana, $validoDesde, $validoHasta);
            if (!$stmtInsert->execute()) {
                throw new RuntimeException('DB_ERROR: Could not insert Workplace timezone mapping.');
            }
            $id = (int)$this->db->insert_id;
            $this->db->commit();
            return $id;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function isCanonicalIana(string $timezone): bool
    {
        return $timezone !== ''
            && $timezone === trim($timezone)
            && in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
