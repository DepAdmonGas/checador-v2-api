<?php
/**
 * Checador V2 — Workplace Timezone Read Controller.
 *
 * Implements GET /api/v2/workplaces/{workplace_id}/timezone?date=YYYY-MM-DD
 * strictly according to docs/v2/WORKPLACE_TIMEZONE_READ_API_CONTRACT_V1.0.md.
 */

declare(strict_types=1);

include_once __DIR__ . "/../BD.php";
include_once __DIR__ . "/Portal3JwtAuthHelper.php";
include_once __DIR__ . "/shift/ShiftSchedulingAuthorizationService.php";
include_once __DIR__ . "/shift/JourneyReadAuthorizationService.php";

class WorkplaceTimezoneReadV2Controller
{
    private const BIOMETRICOS_MODULE = 'biometricos';

    public function consultarHusoHorario(string $rawWorkplaceId): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        // 1. Strict validation of path parameter {workplace_id}
        if (!ctype_digit($rawWorkplaceId) || (int)$rawWorkplaceId <= 0) {
            http_response_code(400);
            echo json_encode([
                "code" => 400,
                "status" => "INVALID_WORKPLACE_ID",
                "mensaje" => "Identificador de localidad inválido."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        $workplaceId = (int)$rawWorkplaceId;

        // 2. Strict validation of query parameter ?date=YYYY-MM-DD
        if (!isset($_GET['date']) || !is_string($_GET['date'])) {
            http_response_code(400);
            echo json_encode([
                "code" => 400,
                "status" => "BAD_DATE",
                "mensaje" => "Fecha local inválida. Formato estricto requerido: YYYY-MM-DD."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $dateParam = trim($_GET['date']);
        if (!$this->isValidCivilDate($dateParam)) {
            http_response_code(400);
            echo json_encode([
                "code" => 400,
                "status" => "BAD_DATE",
                "mensaje" => "Fecha local inválida. Formato estricto requerido: YYYY-MM-DD."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Note: Any client parameter attempting to override timezone (e.g., tz, timezone)
        // is strictly ignored per contract TZR11 and §4.3. Authoritative DB timezone is resolved.

        // 3. Reject Device Auth headers immediately (401 UNAUTHORIZED)
        if (isset($_SERVER['HTTP_X_DEVICE_ID']) || isset($_SERVER['HTTP_X_CREDENTIAL_KEY'])) {
            http_response_code(401);
            echo json_encode([
                "code" => 401,
                "status" => "UNAUTHORIZED",
                "mensaje" => "Token de autenticación Portal3 inválido o ausente."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (empty($authHeader) && function_exists('apache_request_headers')) {
            $reqHeaders = apache_request_headers();
            $authHeader = $reqHeaders['Authorization'] ?? $reqHeaders['authorization'] ?? '';
        }

        $classBD = new BD();
        try {
            $conexion = $classBD->conectarBD();
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                "code" => 500,
                "status" => "INTERNAL_ERROR",
                "mensaje" => "Error interno al resolver huso horario del centro de trabajo."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 4. Authenticate Portal3 JWT
        $auth = Portal3JwtAuthHelper::validateToken($conexion, $authHeader);
        if (!$auth['valid']) {
            $classBD->desconectarBD($conexion);
            http_response_code($auth['code']);
            echo json_encode([
                "code" => $auth['code'],
                "status" => $auth['status'],
                "mensaje" => "Token de autenticación Portal3 inválido o ausente."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 5. Authorize functional permission 'leer' on 'biometricos' module and resolve station scope
        $authz = $this->autorizarAccesoBiometricos($conexion, $auth, 'leer');
        // M03 scheduling readers use their narrow capability and must not need
        // the broader biometricos ACL merely to resolve the same station's
        // timezone. Other biometricos clients keep their existing gate.
        if (!$authz['success'] && (int)($authz['code'] ?? 0) === 403) {
            $authz = ShiftSchedulingAuthorizationService::authorize(
                $conexion,
                $auth,
                ShiftSchedulingAuthorizationService::READ,
                true
            );
        }
        // M05 uses the same read-only timezone metadata but never gains access
        // to scheduling assignments or any write/evaluation operation.
        if (!$authz['success'] && (int)($authz['code'] ?? 0) === 403) {
            $authz = JourneyReadAuthorizationService::authorize($conexion, $auth);
        }
        if (!$authz['success']) {
            $classBD->desconectarBD($conexion);
            http_response_code($authz['code']);
            echo json_encode([
                "code" => $authz['code'],
                "status" => $authz['status'],
                "mensaje" => $authz['error']
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $authorizedStations = $authz['authorized_stations']; // Array of IDs in op_rh_localidades

        // 6. Verify workplace scope (Fail-Closed)
        if (!in_array($workplaceId, $authorizedStations, true)) {
            $classBD->desconectarBD($conexion);
            http_response_code(403);
            echo json_encode([
                "code" => 403,
                "status" => "WORKPLACE_SCOPE_DENIED",
                "mensaje" => "Acceso denegado: el centro de trabajo solicitado está fuera de su alcance."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 7. Verify workplace existence in op_rh_localidades
        try {
            $stmtLoc = $conexion->prepare('SELECT id FROM op_rh_localidades WHERE id = ? LIMIT 1');
            $stmtLoc->bind_param('i', $workplaceId);
            $stmtLoc->execute();
            $locExists = $stmtLoc->get_result()->fetch_assoc() !== null;
            $stmtLoc->close();
        } catch (Throwable $e) {
            $classBD->desconectarBD($conexion);
            http_response_code(500);
            echo json_encode([
                "code" => 500,
                "status" => "INTERNAL_ERROR",
                "mensaje" => "Error interno al resolver huso horario del centro de trabajo."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!$locExists) {
            $classBD->desconectarBD($conexion);
            http_response_code(404);
            echo json_encode([
                "code" => 404,
                "status" => "WORKPLACE_NOT_FOUND",
                "mensaje" => "El centro de trabajo especificado no existe."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 8. Resolve effective-dated timezone from op_rh_v2_localidades_zonas_horarias
        try {
            $stmtTz = $conexion->prepare(
                'SELECT timezone_iana, valido_desde, valido_hasta
                 FROM op_rh_v2_localidades_zonas_horarias
                 WHERE id_localidad = ?
                   AND valido_desde <= ?
                   AND (valido_hasta IS NULL OR ? < valido_hasta)
                 ORDER BY valido_desde ASC'
            );
            $stmtTz->bind_param('iss', $workplaceId, $dateParam, $dateParam);
            $stmtTz->execute();
            $resTz = $stmtTz->get_result();
            $rows = [];
            while ($row = $resTz->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmtTz->close();
        } catch (Throwable $e) {
            $classBD->desconectarBD($conexion);
            http_response_code(500);
            echo json_encode([
                "code" => 500,
                "status" => "INTERNAL_ERROR",
                "mensaje" => "Error interno al resolver huso horario del centro de trabajo."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $classBD->desconectarBD($conexion);

        // Cardinality handling
        if (count($rows) === 0) {
            http_response_code(404);
            echo json_encode([
                "code" => 404,
                "status" => "WORKPLACE_TIMEZONE_UNRESOLVED",
                "mensaje" => "Sin huso horario oficial configurado para el centro de trabajo en la fecha solicitada."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (count($rows) > 1) {
            http_response_code(409);
            echo json_encode([
                "code" => 409,
                "status" => "WORKPLACE_TIMEZONE_AMBIGUOUS",
                "mensaje" => "Configuración de huso horario ambigua o corrupta para el centro de trabajo."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $timezoneIana = (string)$rows[0]['timezone_iana'];

        // 9. IANA identifier validation
        if (!$this->isCanonicalIana($timezoneIana)) {
            http_response_code(500);
            echo json_encode([
                "code" => 500,
                "status" => "WORKPLACE_TIMEZONE_INVALID",
                "mensaje" => "Error interno: identificador de huso horario almacenado no es un estándar IANA válido."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 10. Local civil day boundary conversion to UTC
        try {
            $tzObj = new DateTimeZone($timezoneIana);
            $startLocal = new DateTimeImmutable("{$dateParam} 00:00:00", $tzObj);
            $endLocal = new DateTimeImmutable("{$dateParam} 00:00:00 +1 day", $tzObj);

            $utcTz = new DateTimeZone('UTC');
            $fromUtc = $startLocal->setTimezone($utcTz)->format('Y-m-d\TH:i:s\Z');
            $toUtc = $endLocal->setTimezone($utcTz)->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                "code" => 500,
                "status" => "INTERNAL_ERROR",
                "mensaje" => "Error interno al resolver huso horario del centro de trabajo."
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // 11. Success Response (HTTP 200 OK)
        http_response_code(200);
        echo json_encode([
            "code" => 200,
            "status" => "OK",
            "data" => [
                "workplace_id" => $workplaceId,
                "local_date" => $dateParam,
                "timezone" => $timezoneIana,
                "from_utc" => $fromUtc,
                "to_utc" => $toUtc
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    private function isValidCivilDate(string $value): bool
    {
        if (strlen($value) !== 10) {
            return false;
        }
        $parts = explode('-', $value);
        if (count($parts) !== 3) {
            return false;
        }
        if (!ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
            return false;
        }
        $year = (int)$parts[0];
        $month = (int)$parts[1];
        $day = (int)$parts[2];
        return checkdate($month, $day, $year);
    }

    private function isCanonicalIana(string $timezone): bool
    {
        return $timezone !== ''
            && $timezone === trim($timezone)
            && in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    private function autorizarAccesoBiometricos(mysqli $conexion, array $auth, string $requiredPermission): array
    {
        try {
            $stmtMod = $conexion->prepare('SELECT id, activo FROM modulos_dpto_operativo WHERE clave = ? LIMIT 1');
            $modKey = self::BIOMETRICOS_MODULE;
            $stmtMod->bind_param('s', $modKey);
            $stmtMod->execute();
            $module = $stmtMod->get_result()->fetch_assoc();
            $stmtMod->close();

            if (!$module || (int)($module['activo'] ?? 0) !== 1) {
                return ['success' => false, 'code' => 403, 'status' => 'FUNCTION_PERMISSION_DENIED', 'error' => 'Permiso funcional insuficiente para consultar huso horario.'];
            }

            $userId = (int)($auth['user_id'] ?? 0);
            if ($userId <= 0) {
                return ['success' => false, 'code' => 401, 'status' => 'UNAUTHORIZED', 'error' => 'Token de autenticación Portal3 inválido o ausente.'];
            }

            // Direct grant global precedence
            $stmtDirect = $conexion->prepare('SELECT id FROM modulos_usuarios_do WHERE id_usuario = ? LIMIT 1');
            $stmtDirect->bind_param('i', $userId);
            $stmtDirect->execute();
            $hasDirect = $stmtDirect->get_result()->fetch_assoc() !== null;
            $stmtDirect->close();

            if ($hasDirect) {
                $stmtGrant = $conexion->prepare('SELECT leer, crear, editar, eliminar, descargar FROM modulos_usuarios_do WHERE id_modulo = ? AND id_usuario = ? LIMIT 1');
                $modId = (int)$module['id'];
                $stmtGrant->bind_param('ii', $modId, $userId);
                $stmtGrant->execute();
                $grant = $stmtGrant->get_result()->fetch_assoc();
                $stmtGrant->close();
            } else {
                $puestoId = (int)($auth['id_puesto'] ?? 0);
                if ($puestoId > 0) {
                    $stmtGrant = $conexion->prepare('SELECT leer, crear, editar, eliminar, descargar FROM modulos_puestos_do WHERE id_modulo = ? AND id_puesto = ? LIMIT 1');
                    $modId = (int)$module['id'];
                    $stmtGrant->bind_param('ii', $modId, $puestoId);
                    $stmtGrant->execute();
                    $grant = $stmtGrant->get_result()->fetch_assoc();
                    $stmtGrant->close();
                } else {
                    $grant = null;
                }
            }

            if (!$grant || (int)($grant[$requiredPermission] ?? 0) !== 1) {
                return ['success' => false, 'code' => 403, 'status' => 'FUNCTION_PERMISSION_DENIED', 'error' => 'Permiso funcional insuficiente para consultar huso horario.'];
            }

            // Module station scope configuration
            $stmtCfg = $conexion->prepare('SELECT activo, estaciones_soportadas, tipo_departamento FROM tb_modulos_config WHERE modulo_key = ? LIMIT 1');
            $stmtCfg->bind_param('s', $modKey);
            $stmtCfg->execute();
            $moduleConfig = $stmtCfg->get_result()->fetch_assoc();
            $stmtCfg->close();

            if (!$moduleConfig || (int)($moduleConfig['activo'] ?? 0) !== 1 || ($moduleConfig['tipo_departamento'] ?? null) !== 'localidades') {
                return ['success' => false, 'code' => 403, 'status' => 'WORKPLACE_SCOPE_DENIED', 'error' => 'Acceso denegado: el centro de trabajo solicitado está fuera de su alcance.'];
            }

            $supportedStations = json_decode($moduleConfig['estaciones_soportadas'] ?? '[]', true);
            if (!is_array($supportedStations) || empty($supportedStations)) {
                return ['success' => false, 'code' => 403, 'status' => 'WORKPLACE_SCOPE_DENIED', 'error' => 'Acceso denegado: el centro de trabajo solicitado está fuera de su alcance.'];
            }

            $reflector = new ReflectionClass('Portal3JwtAuthHelper');
            $method = $reflector->getMethod('resolveStationScope');
            $method->setAccessible(true);
            $authorizedStations = $method->invoke(null, $conexion, $auth, $supportedStations);

            if (empty($authorizedStations)) {
                return ['success' => false, 'code' => 403, 'status' => 'WORKPLACE_SCOPE_DENIED', 'error' => 'Acceso denegado: el centro de trabajo solicitado está fuera de su alcance.'];
            }

            return ['success' => true, 'code' => 200, 'status' => 'AUTHORIZED', 'authorized_stations' => $authorizedStations];
        } catch (Throwable $e) {
            return ['success' => false, 'code' => 500, 'status' => 'INTERNAL_ERROR', 'error' => 'Error interno al resolver huso horario del centro de trabajo.'];
        }
    }
}
