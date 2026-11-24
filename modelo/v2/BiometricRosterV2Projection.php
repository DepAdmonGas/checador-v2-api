<?php

declare(strict_types=1);

/** Pure response projection for the proposed station biometric roster boundary. */
final class BiometricRosterV2Projection
{
    private const MAX_WORKERS = 1000;
    private const MAX_TEMPLATE_BYTES = 2048;

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    public static function project(array $rows, int $physicalStationId, string $stationName, string $timestamp): array
    {
        if ($physicalStationId < 1 || trim($stationName) === '' || trim($timestamp) === '') {
            throw new InvalidArgumentException('INVALID_ROSTER_CONTEXT');
        }

        $workers = [];
        $seenPersonalIds = [];
        $seenExternalReferences = [];
        foreach ($rows as $row) {
            $personalId = filter_var($row['id_personal'] ?? null, FILTER_VALIDATE_INT);
            $externalReference = $row['external_worker_reference'] ?? null;
            $noColaborador = filter_var($row['no_colaborador'] ?? null, FILTER_VALIDATE_INT);
            $adscriptionStation = filter_var($row['id_estacion_adscripcion'] ?? null, FILTER_VALIDATE_INT);
            $mappingCount = filter_var($row['identity_mapping_count'] ?? 1, FILTER_VALIDATE_INT);

            if ($personalId === false || $personalId < 1
                || !is_string($externalReference) || trim($externalReference) === ''
                || $noColaborador === false || $noColaborador < 0
                || $adscriptionStation === false || $adscriptionStation < 1
                || $mappingCount !== 1
                || isset($seenPersonalIds[$personalId])) {
                throw new RuntimeException('ROSTER_DATA_INCOMPLETE');
            }
            $externalReference = trim($externalReference);
            if (isset($seenExternalReferences[$externalReference])) {
                throw new RuntimeException('ROSTER_DATA_INCOMPLETE');
            }
            $seenExternalReferences[$externalReference] = true;
            $seenPersonalIds[$personalId] = true;

            $name = $row['nombre'] ?? null;
            $position = $row['puesto'] ?? '';
            if (!is_string($name) || trim($name) === '' || !is_string($position)) {
                throw new RuntimeException('ROSTER_DATA_INCOMPLETE');
            }

            $template = $row['biometric_template'] ?? null;
            if ($template !== null && !is_string($template)) {
                throw new RuntimeException('ROSTER_DATA_INCOMPLETE');
            }
            if ($template === '') $template = null;
            if ($template !== null) {
                $decodedTemplate = base64_decode($template, true);
                if ($decodedTemplate === false || strlen($decodedTemplate) > self::MAX_TEMPLATE_BYTES) {
                    throw new RuntimeException('ROSTER_DATA_INCOMPLETE');
                }
            }

            $workers[] = [
                'external_worker_reference' => trim($externalReference),
                'id_personal' => (int) $personalId,
                'no_colaborador' => (int) $noColaborador,
                'nombre' => $name,
                'puesto' => $position,
                'id_estacion_adscripcion' => (int) $adscriptionStation,
                'es_comodin' => false,
                'has_biometric_template' => $template !== null,
                'biometric_template' => $template,
            ];
            if (count($workers) > self::MAX_WORKERS) {
                throw new RuntimeException('ROSTER_LIMIT_EXCEEDED');
            }
        }

        return [
            'code' => 200,
            'status' => 'OK',
            // Physical tb_estaciones.id, consistent with /api/v2/devices/context.
            'id_estacion' => $physicalStationId,
            'station_name' => $stationName,
            'total_workers' => count($workers),
            'workers_with_biometrics' => count(array_filter(
                $workers,
                static fn(array $worker): bool => $worker['has_biometric_template']
            )),
            'timestamp' => $timestamp,
            'workers' => $workers,
        ];
    }
}
