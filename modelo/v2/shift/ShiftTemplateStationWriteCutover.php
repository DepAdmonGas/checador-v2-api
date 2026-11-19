<?php

declare(strict_types=1);

/** Scope-specific M04 API writer gate; unset preserves existing behavior until an operator closes the cut. */
final class ShiftTemplateStationWriteCutover
{
    public const FLAG = 'CHECADOR_M04_API_STATION_TEMPLATE_WRITES_ENABLED';
    public const ERROR_CODE = 'M04_STATION_TEMPLATE_WRITES_DISABLED';
    public const HTTP_STATUS = 503;

    /** An absent flag keeps the current API behavior; malformed explicit values fail closed. */
    public static function stationWritesEnabled(?string $configuredValue = null): bool
    {
        if ($configuredValue === null) {
            $raw = $_ENV[self::FLAG] ?? $_SERVER[self::FLAG] ?? getenv(self::FLAG);
            if ($raw === false || $raw === null || trim((string) $raw) === '') return true;
            $configuredValue = (string) $raw;
        } elseif (trim($configuredValue) === '') {
            return true;
        }

        return in_array(strtolower(trim($configuredValue)), ['1', 'true', 'yes', 'on'], true);
    }

    /** Invoke the writer only after valid server authorization and only when the station cut is open. */
    public static function execute(array $authInfo, callable $mutation, ?string $configuredValue = null): array
    {
        $scope = strtoupper(trim((string) ($authInfo['scope'] ?? '')));
        if (!in_array($scope, ['GLOBAL', 'STATION'], true)) {
            return ['allowed' => false, 'code' => 'TEMPLATE_SCOPE_DENIED', 'status' => 403];
        }
        if ($scope === 'STATION' && !self::stationWritesEnabled($configuredValue)) {
            return ['allowed' => false, 'code' => self::ERROR_CODE, 'status' => self::HTTP_STATUS];
        }
        return ['allowed' => true, 'result' => $mutation()];
    }
}
