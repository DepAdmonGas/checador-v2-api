<?php
/**
 * Servicio Criptográfico de Integridad y Canonicalización para Raw Punch Events V2.3
 *
 * Implementa el Addendum V1.0 de Integridad (docs/v2/PUNCH_EVENTS_INTEGRITY_ADDENDUM_V1.0.md):
 * - Canonicalización determinista (JSON V1, ksort recursivo, UTF-8, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
 * - Sellado criptográfico HMAC-SHA256
 * - Verificación determinista de sellos contra KeyRing con rotación de claves
 *
 * NOTA: Esta clase es pura y decoupled de DB/Ingesta en V2.3-G1 / G1A.
 */

class PunchEventIntegrityService {
    public const VERSION_V1 = 'v1';

    public const RESULT_VALID = 'VALID';
    public const RESULT_INVALID = 'INVALID';
    public const RESULT_UNVERIFIABLE = 'UNVERIFIABLE';
    public const RESULT_LEGACY_UNSEALED = 'LEGACY_UNSEALED';

    /**
     * Campos del Envelope V1 que forman la carga útil protegida por el sello.
     * EXCLUIDOS EXPLÍCITAMENTE: processing_status, integrity_seal, integrity_version, integrity_key_id.
     */
    private const ENVELOPE_V1_FIELDS = [
        'event_uuid',
        'id_dispositivo',
        'id_estacion_resuelta',
        'referencia_externa_personal',
        'id_personal_resuelto',
        'source_timestamp_raw',
        'source_timezone_reported',
        'effective_timezone',
        'timezone_fallback_applied',
        'ocurrido_at_utc',
        'recibido_at_utc',
        'metodo_captura',
        'external_event_id',
        'secuencia_dispositivo',
        'validation_flags',
        'metadata_json'
    ];

    /**
     * Normaliza y serializa deterministamente los campos del Envelope V1.
     *
     * @param array $data Mapa asociativo con los datos del registro/payload del evento (no muta la entrada)
     * @return string Cadena JSON canonicalizada
     * @throws InvalidArgumentException Si falta alguna clave obligatoria de la envolvente o si UTF-8 es inválido
     */
    public static function canonicalizeEnvelopeV1(array $data): string {
        $envelope = [];

        foreach (self::ENVELOPE_V1_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidArgumentException("Missing required envelope field for canonicalization: {$field}");
            }

            $val = $data[$field];

            // Validar UTF-8 en strings si aplica
            if (is_string($val) && !mb_check_encoding($val, 'UTF-8')) {
                throw new InvalidArgumentException("Invalid UTF-8 sequence detected in field: {$field}");
            }

            // Normalización específica por tipo de campo
            if ($field === 'validation_flags' || $field === 'metadata_json') {
                if (is_string($val)) {
                    $trimmed = trim($val);
                    if ($trimmed === '' || $trimmed === 'null') {
                        $val = null;
                    } else {
                        $decoded = json_decode($trimmed, true);
                        if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_null($decoded))) {
                            $val = $decoded;
                        } else {
                            $val = $trimmed;
                        }
                    }
                }
            } elseif ($field === 'timezone_fallback_applied') {
                $val = (int)(bool)$val;
            } elseif ($field === 'id_dispositivo' || $field === 'id_estacion_resuelta' || $field === 'id_personal_resuelto' || $field === 'secuencia_dispositivo') {
                if ($val !== null) {
                    $val = (int)$val;
                }
            } elseif (is_string($val)) {
                $val = (string)$val;
            }

            $envelope[$field] = $val;
        }

        // Ordenar claves del mapa principal de manera léxico-gráfica
        ksort($envelope, SORT_STRING);

        // Recursivamente ordenar subarrays/objetos (preservando orden de arreglos indexados)
        $envelope = self::sortRecursive($envelope);

        $json = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException("Failed to canonicalize envelope to JSON: " . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Ordena recursivamente las llaves de un arreglo asociativo. Preserva el orden numérico de listas indexadas.
     */
    private static function sortRecursive($data) {
        if (!is_array($data)) {
            return $data;
        }

        // Determinar si es un arreglo asociativo u objeto JSON
        $isAssoc = false;
        $keys = array_keys($data);
        foreach ($keys as $k) {
            if (is_string($k)) {
                $isAssoc = true;
                break;
            }
        }

        if ($isAssoc) {
            ksort($data, SORT_STRING);
        }

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::sortRecursive($v);
            }
        }

        return $data;
    }

    /**
     * Sella los datos del evento utilizando la versión V1 y el secreto HMAC configurado/proporcionado.
     *
     * @param array $data Datos del evento (no muta la entrada)
     * @param string $keyId Identificador de la clave usada (e.g. 'k1', '2026-v1')
     * @param string $secret Clave secreta binaria/string para HMAC-SHA256
     * @return array [ 'integrity_version' => 'v1', 'integrity_key_id' => $keyId, 'integrity_seal' => $seal, 'canonical_json' => $canonical ]
     */
    public static function seal(array $data, string $keyId, string $secret): array {
        if (empty($keyId)) {
            throw new InvalidArgumentException("Active Key ID cannot be empty or unconfigured.");
        }
        if (empty($secret)) {
            throw new InvalidArgumentException("Secret key for active Key ID [{$keyId}] cannot be empty or unconfigured.");
        }

        $canonical = self::canonicalizeEnvelopeV1($data);
        $seal = hash_hmac('sha256', $canonical, $secret);

        return [
            'integrity_version' => self::VERSION_V1,
            'integrity_key_id' => $keyId,
            'integrity_seal' => $seal,
            'canonical_json' => $canonical
        ];
    }

    /**
     * Sella los datos del evento utilizando una clave activa desde el entorno o un keyring provisto.
     * Si no se proveen argumentos explícitos de clave, se obtiene RAW_PUNCH_ACTIVE_KEY_ID y RAW_PUNCH_KEY_RING del entorno.
     *
     * @param array $data Datos del evento
     * @param string|null $activeKeyId Key ID opcional
     * @param array|null $keyRing KeyRing opcional [ key_id => secret ]
     * @return array Sello y metadatos
     */
    public static function sealWithConfig(array $data, ?string $activeKeyId = null, ?array $keyRing = null): array {
        if ($activeKeyId === null) {
            $activeKeyId = getenv('RAW_PUNCH_ACTIVE_KEY_ID') ?: null;
        }

        if (empty($activeKeyId)) {
            throw new InvalidArgumentException("RAW_PUNCH_ACTIVE_KEY_ID is missing or not configured.");
        }

        if ($keyRing === null) {
            $keyRingJson = getenv('RAW_PUNCH_KEY_RING');
            if ($keyRingJson) {
                $decoded = json_decode($keyRingJson, true);
                if (is_array($decoded)) {
                    $keyRing = $decoded;
                }
            }
        }

        if (empty($keyRing) || !isset($keyRing[$activeKeyId]) || empty($keyRing[$activeKeyId])) {
            throw new InvalidArgumentException("Active Key ID [{$activeKeyId}] missing or empty in Key Ring configuration.");
        }

        return self::seal($data, $activeKeyId, $keyRing[$activeKeyId]);
    }

    /**
     * Verifica la integridad de una fila o registro de marcación cruda (no muta la entrada).
     *
     * @param array $row Registro de la base de datos o payload
     * @param array $keyRing Mapa [ key_id => secret_string ]
     * @return string Estado de verificación: VALID | INVALID | UNVERIFIABLE | LEGACY_UNSEALED
     */
    public static function verify(array $row, array $keyRing): string {
        $version = $row['integrity_version'] ?? null;
        $keyId = $row['integrity_key_id'] ?? null;
        $seal = $row['integrity_seal'] ?? null;

        // Evaluar estado LEGACY_UNSEALED: todos deben ser estrictamente NULL
        if ($version === null && $keyId === null && $seal === null) {
            return self::RESULT_LEGACY_UNSEALED;
        }

        // Si es parcialmente nulo o versión distinta de v1 -> UNVERIFIABLE
        if ($version !== self::VERSION_V1 || empty($keyId) || empty($seal)) {
            return self::RESULT_UNVERIFIABLE;
        }

        // Verificar si la clave existe en el keyring -> UNVERIFIABLE
        if (!array_key_exists($keyId, $keyRing) || empty($keyRing[$keyId])) {
            return self::RESULT_UNVERIFIABLE;
        }

        $secret = $keyRing[$keyId];

        try {
            $expectedCanonical = self::canonicalizeEnvelopeV1($row);
            $expectedSeal = hash_hmac('sha256', $expectedCanonical, $secret);

            // Comparación constante utilizando hash_equals()
            if (hash_equals($expectedSeal, strtolower($seal))) {
                return self::RESULT_VALID;
            } else {
                return self::RESULT_INVALID;
            }
        } catch (Exception $e) {
            return self::RESULT_INVALID;
        }
    }
}
