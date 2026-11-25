<?php

declare(strict_types=1);

/** Prepared policy only: not wired into SEC-L03 until the addendum is approved. */
final class TemporaryAccessInheritedPolicy
{
    /** @param list<array<string,mixed>> $individualTemporaryGrants */
    public static function allows(int $position, string $action, bool $userActive, bool $stationScoped, bool $checadorSubmodule, array $parentPermissions, array $individualTemporaryGrants): bool
    {
        if (!$userActive || !$stationScoped || !$checadorSubmodule || !in_array($position, [6,13], true)
            || !in_array($action, ['leer','editar'], true) || ($action === 'editar' && $position !== 6)
            || (int)($parentPermissions['leer'] ?? 0) !== 1
            || ($action === 'editar' && (int)($parentPermissions['editar'] ?? 0) !== 1)
            || count($individualTemporaryGrants) > 1) return false;
        if (count($individualTemporaryGrants) === 1) {
            $grant = $individualTemporaryGrants[0];
            if ((int)($grant['leer'] ?? 0) !== 1 || ($action === 'editar' && (int)($grant['editar'] ?? 0) !== 1)) return false;
        }
        return true;
    }
}
