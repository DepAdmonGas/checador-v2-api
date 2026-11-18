<?php

declare(strict_types=1);

/** Fail-closed gate for the three API-owned M03 assignment mutation endpoints. */
final class ShiftAssignmentWriteCutover
{
    public const FLAG = 'CHECADOR_M03_API_ASSIGNMENT_WRITES_ENABLED';
    public const ERROR_CODE = 'M03_ASSIGNMENT_WRITES_DISABLED';
    public const HTTP_STATUS = 503;

    /** Writes remain disabled unless an operator explicitly enables the API writer. */
    public static function enabled(?string $configuredValue = null): bool
    {
        $value = $configuredValue ?? (string) ($_ENV[self::FLAG] ?? $_SERVER[self::FLAG] ?? getenv(self::FLAG) ?: '');
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
