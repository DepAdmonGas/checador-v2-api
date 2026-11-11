<?php

declare(strict_types=1);

require_once __DIR__ . '/../BD.php';
require_once __DIR__ . '/Portal3JwtAuthHelper.php';
require_once __DIR__ . '/shift/WorkplaceTimezoneAuthorityService.php';
require_once __DIR__ . '/AttendancePolicyAuthorizationService.php';

/** M11 versioned attendance-policy read and append-only development writer. */
final class AttendancePolicyV2Controller
{
    public function handleRead(): void
    {
        $this->setSecurityHeaders();
        $workplaceId = self::positiveInt($_GET['workplace_id'] ?? null);
        $effectiveDate = is_string($_GET['effective_date'] ?? null) ? trim($_GET['effective_date']) : '';
        if ($workplaceId === null || !self::isCivilDate($effectiveDate)) {
            $this->sendError('INVALID_PAYLOAD', 'Se requieren workplace_id y effective_date válidos.', 400);
            return;
        }

        $bd = new BD();
        $connection = $bd->conectarBD();
        try {
            $auth = $this->authenticate($connection);
            if (!($auth['valid'] ?? false)) {
                $this->sendError((string) $auth['error'], (string) $auth['error'], (int) $auth['status']);
                return;
            }
            $authz = AttendancePolicyAuthorizationService::authorize($connection, $auth, AttendancePolicyAuthorizationService::READ);
            if (!($authz['success'] ?? false)) {
                $this->sendError((string) $authz['status'], (string) $authz['error'], (int) $authz['code']);
                return;
            }
            if (!in_array($workplaceId, $authz['authorized_workplaces'] ?? [], true)) {
                $this->sendError('STATION_SCOPE_DENIED', 'La localidad solicitada está fuera del alcance autorizado.', 403);
                return;
            }

            try {
                $timezone = (new WorkplaceTimezoneAuthorityService($connection))->resolve($workplaceId, $effectiveDate);
            } catch (Throwable) {
                $this->sendError('WORKPLACE_TIMEZONE_UNRESOLVED', 'No se pudo confirmar la zona horaria local.', 503);
                return;
            }

            $policy = $this->findEffectivePolicy($connection, $workplaceId, $effectiveDate);
            $latest = $this->findLatestPolicy($connection, $workplaceId);
            $data = [
                'workplace_id' => $workplaceId,
                'effective_date' => $effectiveDate,
                'timezone_id' => $timezone,
                'current_local_date' => (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d'),
                'status' => $policy === null ? 'LEGACY_UNVERSIONED' : 'VERSIONED',
                'policy' => $policy,
                'latest_scheduled_policy' => $latest,
            ];
            $this->sendJson(200, ['status' => 'success', 'data' => $data]);
        } catch (Throwable) {
            $this->sendError('SERVER_ERROR', 'No fue posible consultar la política de asistencia.', 503);
        } finally {
            $bd->desconectarBD($connection);
        }
    }

    public function handleCreate(): void
    {
        $this->setSecurityHeaders();
        $environment = strtolower(trim((string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '')));
        if (!in_array($environment, ['dev', 'development', 'local'], true)) {
            $this->sendError('DEVELOPMENT_ONLY', 'La escritura de políticas está limitada al entorno local de desarrollo.', 403);
            return;
        }
        $idempotencyKey = $this->header('Idempotency-Key');
        if (preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/D', $idempotencyKey) !== 1) {
            $this->sendError('INVALID_PAYLOAD', "Encabezado 'Idempotency-Key' requerido o inválido.", 400);
            return;
        }
        $raw = file_get_contents('php://input');
        $body = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($body)) {
            $this->sendError('INVALID_PAYLOAD', 'Cuerpo JSON inválido.', 400);
            return;
        }
        $workplaceId = self::positiveInt($body['workplace_id'] ?? null);
        $effectiveDate = is_string($body['effective_from_local_date'] ?? null)
            ? trim($body['effective_from_local_date']) : '';
        $tolerance = self::nonNegativeInt($body['retardo_tolerance_minutes'] ?? null);
        $deadlineDays = self::nonNegativeInt($body['incidence_deadline_days'] ?? null);
        if ($workplaceId === null || !self::isCivilDate($effectiveDate)
            || $tolerance === null || $tolerance > 120
            || $deadlineDays === null || $deadlineDays > 30) {
            $this->sendError('INVALID_PAYLOAD', 'Localidad, vigencia y valores enteros dentro de los rangos autorizados son requeridos.', 400);
            return;
        }

        $bd = new BD();
        $connection = $bd->conectarBD();
        try {
            $auth = $this->authenticate($connection);
            if (!($auth['valid'] ?? false)) {
                $this->sendError((string) $auth['error'], (string) $auth['error'], (int) $auth['status']);
                return;
            }
            $authz = AttendancePolicyAuthorizationService::authorize($connection, $auth, AttendancePolicyAuthorizationService::EDIT);
            if (!($authz['success'] ?? false)) {
                $this->sendError((string) $authz['status'], (string) $authz['error'], (int) $authz['code']);
                return;
            }
            if (!in_array($workplaceId, $authz['authorized_workplaces'] ?? [], true)) {
                $this->sendError('STATION_SCOPE_DENIED', 'La localidad solicitada está fuera del alcance autorizado.', 403);
                return;
            }

            $connection->begin_transaction();
            try {
                $lock = $connection->prepare('SELECT id FROM op_rh_localidades WHERE id = ? FOR UPDATE');
                if (!$lock) throw new RuntimeException('LOCALITY_LOCK_UNAVAILABLE');
                $lock->bind_param('i', $workplaceId);
                $lock->execute();
                if ($lock->get_result()->num_rows !== 1) {
                    throw new DomainException('WORKPLACE_NOT_FOUND');
                }

                $existing = $this->findByIdempotency($connection, $workplaceId, $idempotencyKey);
                if ($existing !== null) {
                    $samePayload = (int) $existing['actor_portal_user_id'] === (int) $auth['user_id']
                        && (string) $existing['effective_from_local_date'] === $effectiveDate
                        && (int) $existing['retardo_tolerance_minutes'] === $tolerance
                        && (int) $existing['incidence_deadline_days'] === $deadlineDays;
                    if (!$samePayload) throw new DomainException('IDEMPOTENCY_CONFLICT');
                    $connection->commit();
                    $this->sendJson(200, ['status' => 'success', 'data' => $this->normalizePolicy($existing) + ['idempotent_replay' => true]]);
                    return;
                }

                try {
                    $timezone = (new WorkplaceTimezoneAuthorityService($connection))->resolve($workplaceId, $effectiveDate);
                } catch (Throwable) {
                    throw new DomainException('WORKPLACE_TIMEZONE_UNRESOLVED');
                }
                $todayLocal = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
                if ($effectiveDate <= $todayLocal) throw new DomainException('POLICY_MUST_BE_PROSPECTIVE');

                $latestStmt = $connection->prepare(
                    'SELECT policy_version, effective_from_local_date FROM op_rh_v2_attendance_policy_versions '
                    . 'WHERE workplace_id = ? ORDER BY policy_version DESC LIMIT 1 FOR UPDATE'
                );
                if (!$latestStmt) throw new RuntimeException('POLICY_READ_UNAVAILABLE');
                $latestStmt->bind_param('i', $workplaceId);
                $latestStmt->execute();
                $latest = $latestStmt->get_result()->fetch_assoc();
                if ($latest !== null && $effectiveDate <= (string) $latest['effective_from_local_date']) {
                    throw new DomainException('POLICY_VERSION_DATE_CONFLICT');
                }
                $policyVersion = $latest === null ? 1 : ((int) $latest['policy_version'] + 1);
                if ($policyVersion < 1) throw new RuntimeException('POLICY_VERSION_OVERFLOW');

                $insert = $connection->prepare(
                    'INSERT INTO op_rh_v2_attendance_policy_versions '
                    . '(workplace_id, policy_version, effective_from_local_date, timezone_id, '
                    . 'retardo_tolerance_minutes, incidence_deadline_days, actor_portal_user_id, source, idempotency_key) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, \'PORTAL3_ADMIN\', ?)'
                );
                if (!$insert) throw new RuntimeException('POLICY_WRITE_UNAVAILABLE');
                $actorId = (int) $auth['user_id'];
                $insert->bind_param('iissiiis', $workplaceId, $policyVersion, $effectiveDate, $timezone, $tolerance, $deadlineDays, $actorId, $idempotencyKey);
                if (!$insert->execute()) {
                    if ((int) $insert->errno === 1062) throw new DomainException('POLICY_VERSION_DATE_CONFLICT');
                    throw new RuntimeException('POLICY_WRITE_UNAVAILABLE');
                }
                $id = (int) $connection->insert_id;
                $readbackStmt = $connection->prepare('SELECT * FROM op_rh_v2_attendance_policy_versions WHERE id = ? AND workplace_id = ? LIMIT 1');
                if (!$readbackStmt) throw new RuntimeException('POLICY_READBACK_UNAVAILABLE');
                $readbackStmt->bind_param('ii', $id, $workplaceId);
                $readbackStmt->execute();
                $created = $readbackStmt->get_result()->fetch_assoc();
                if ($created === null || (int) $created['policy_version'] !== $policyVersion
                    || (string) $created['effective_from_local_date'] !== $effectiveDate
                    || (string) $created['timezone_id'] !== $timezone
                    || (int) $created['retardo_tolerance_minutes'] !== $tolerance
                    || (int) $created['incidence_deadline_days'] !== $deadlineDays
                    || (int) $created['actor_portal_user_id'] !== $actorId
                    || (string) $created['idempotency_key'] !== $idempotencyKey) {
                    throw new RuntimeException('POLICY_READBACK_MISMATCH');
                }
                $connection->commit();
                $this->sendJson(201, ['status' => 'success', 'data' => $this->normalizePolicy($created) + ['idempotent_replay' => false]]);
            } catch (DomainException $exception) {
                $connection->rollback();
                $status = $exception->getMessage() === 'IDEMPOTENCY_CONFLICT' ? 409
                    : (in_array($exception->getMessage(), ['POLICY_MUST_BE_PROSPECTIVE', 'POLICY_VERSION_DATE_CONFLICT'], true) ? 422
                        : ($exception->getMessage() === 'WORKPLACE_NOT_FOUND' ? 404 : 503));
                $this->sendError($exception->getMessage(), $this->domainMessage($exception->getMessage()), $status);
            } catch (Throwable) {
                $connection->rollback();
                $this->sendError('SERVER_ERROR', 'No fue posible guardar y verificar la nueva política.', 503);
            }
        } catch (Throwable) {
            $this->sendError('SERVER_ERROR', 'No fue posible procesar la política de asistencia.', 503);
        } finally {
            $bd->desconectarBD($connection);
        }
    }

    private function authenticate($connection): array
    {
        $authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $auth = Portal3JwtAuthHelper::validateToken($connection, $authHeader);
        if (!($auth['valid'] ?? false)) return $auth;
        return $auth;
    }

    private function findEffectivePolicy($connection, int $workplaceId, string $date): ?array
    {
        $stmt = $connection->prepare(
            'SELECT * FROM op_rh_v2_attendance_policy_versions '
            . 'WHERE workplace_id = ? AND effective_from_local_date <= ? '
            . 'ORDER BY effective_from_local_date DESC, policy_version DESC LIMIT 1'
        );
        if (!$stmt) throw new RuntimeException('POLICY_READ_UNAVAILABLE');
        $stmt->bind_param('is', $workplaceId, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row === null ? null : $this->normalizePolicy($row);
    }

    private function findLatestPolicy($connection, int $workplaceId): ?array
    {
        $stmt = $connection->prepare(
            'SELECT * FROM op_rh_v2_attendance_policy_versions WHERE workplace_id = ? '
            . 'ORDER BY policy_version DESC LIMIT 1'
        );
        if (!$stmt) throw new RuntimeException('POLICY_READ_UNAVAILABLE');
        $stmt->bind_param('i', $workplaceId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row === null ? null : $this->normalizePolicy($row);
    }

    private function findByIdempotency($connection, int $workplaceId, string $key): ?array
    {
        $stmt = $connection->prepare(
            'SELECT * FROM op_rh_v2_attendance_policy_versions WHERE workplace_id = ? AND idempotency_key = ? LIMIT 1 FOR UPDATE'
        );
        if (!$stmt) throw new RuntimeException('POLICY_IDEMPOTENCY_READ_UNAVAILABLE');
        $stmt->bind_param('is', $workplaceId, $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row === null ? null : $row;
    }

    private function normalizePolicy(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'workplace_id' => (int) $row['workplace_id'],
            'policy_version' => (int) $row['policy_version'],
            'effective_from_local_date' => (string) $row['effective_from_local_date'],
            'timezone_id' => (string) $row['timezone_id'],
            'retardo_tolerance_minutes' => (int) $row['retardo_tolerance_minutes'],
            'incidence_deadline_days' => (int) $row['incidence_deadline_days'],
            'actor_portal_user_id' => (int) $row['actor_portal_user_id'],
            'source' => (string) $row['source'],
            'created_at_utc' => (string) $row['created_at_utc'],
        ];
    }

    private static function positiveInt(mixed $value): ?int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value < 1) return null;
        return (int) $value;
    }

    private static function nonNegativeInt(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) return null;
        if ((string) $value !== (string) (int) $value || (int) $value < 0 || (float) $value > 4294967295) return null;
        return (int) $value;
    }

    private static function isCivilDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!empty($_SERVER[$key])) return trim((string) $_SERVER[$key]);
        foreach (function_exists('getallheaders') ? (getallheaders() ?: []) : [] as $header => $value) {
            if (strcasecmp((string) $header, $name) === 0) return trim((string) $value);
        }
        return '';
    }

    private function domainMessage(string $code): string
    {
        return match ($code) {
            'IDEMPOTENCY_CONFLICT' => 'La clave de idempotencia ya fue usada con datos diferentes.',
            'POLICY_MUST_BE_PROSPECTIVE' => 'La vigencia debe iniciar después de la fecha local actual.',
            'POLICY_VERSION_DATE_CONFLICT' => 'La vigencia debe ser posterior a la última versión programada.',
            'WORKPLACE_NOT_FOUND' => 'La localidad canónica no existe.',
            'WORKPLACE_TIMEZONE_UNRESOLVED' => 'No se pudo confirmar la zona horaria local.',
            default => 'No se pudo guardar la política.',
        };
    }

    private function setSecurityHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }

    private function sendJson(int $status, array $payload): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sendError(string $code, string $message, int $status): void
    {
        $this->sendJson($status, ['status' => 'error', 'error' => ['code' => $code, 'message' => $message]]);
    }
}
