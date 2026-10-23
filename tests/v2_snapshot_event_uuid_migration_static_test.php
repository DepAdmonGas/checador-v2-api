<?php

declare(strict_types=1);

/**
 * Static, non-database contract test for the Level 1 event_uuid migration.
 * This test checks the authored SQL text only; it does not prove MariaDB runtime syntax.
 */

$root = dirname(__DIR__);
$upPath = $root . '/migraciones/v2/2026_10_09_000001_widen_snapshot_event_uuid.sql';
$downPath = $root . '/migraciones/v2/2026_10_09_000001_rollback_widen_snapshot_event_uuid.sql';

function assert_migration(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
    echo "[PASS] {$message}\n";
}

function sql_statements(string $sql): array
{
    // These migration files intentionally use simple SQL with line comments only.
    $withoutComments = preg_replace('/--[^\r\n]*/', '', $sql);
    if ($withoutComments === null) {
        return [];
    }

    return array_values(array_filter(
        array_map('trim', explode(';', $withoutComments)),
        static fn (string $statement): bool => $statement !== ''
    ));
}

$up = file_get_contents($upPath);
$down = file_get_contents($downPath);
assert_migration($up !== false, 'UP migration exists and is readable');
assert_migration($down !== false, 'rollback policy file exists and is readable');

$up = (string) $up;
$down = (string) $down;
$upStatements = sql_statements($up);
$downStatements = sql_statements($down);

assert_migration(count($upStatements) === 1, 'UP contains exactly one executable SQL statement');
assert_migration(
    preg_match('/^ALTER\s+TABLE\s+`op_rh_v2_read_snapshot_members`\s+MODIFY\s+COLUMN\s+`event_uuid`\s+VARCHAR\s*\(\s*64\s*\)\s+CHARACTER\s+SET\s+utf8mb4\s+COLLATE\s+utf8mb4_unicode_ci\s+NOT\s+NULL$/i', $upStatements[0] ?? '') === 1,
    'UP alters exactly the snapshot member event_uuid target with the frozen VARCHAR(64) definition'
);
assert_migration(
    preg_match_all('/\bALTER\s+TABLE\b/i', $up) === 1 &&
    preg_match_all('/\bMODIFY\s+COLUMN\b/i', $up) === 1,
    'UP contains exactly one table alteration and one modified column'
);
assert_migration(preg_match('/\bVARCHAR\s*\(\s*64\s*\)/i', $upStatements[0] ?? '') === 1, 'UP target width is VARCHAR(64)');
assert_migration(preg_match('/\bCHARACTER\s+SET\s+utf8mb4\b/i', $upStatements[0] ?? '') === 1, 'UP explicitly preserves utf8mb4');
assert_migration(preg_match('/\bCOLLATE\s+utf8mb4_unicode_ci\b/i', $upStatements[0] ?? '') === 1, 'UP explicitly preserves utf8mb4_unicode_ci');
assert_migration(preg_match('/\bNOT\s+NULL\b/i', $upStatements[0] ?? '') === 1, 'UP explicitly preserves NOT NULL');
assert_migration(
    preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|LOAD\s+DATA)\b/i', $up) === 0,
    'UP contains no data manipulation statement'
);
assert_migration(
    preg_match('/\b(?:CREATE|DROP|RENAME|TRUNCATE)\s+(?:TABLE|INDEX|KEY|CONSTRAINT)\b/i', $up) === 0 &&
    preg_match('/\b(?:ADD|DROP)\s+(?:INDEX|KEY|CONSTRAINT)\b/i', $up) === 0,
    'UP contains no other table, object, or index DDL'
);
assert_migration(
    preg_match('/op_rh_v2_marcaciones_crudas|op_rh_v2_durable_snapshot|op_rh_v2_read_snapshots/i', $up) === 0,
    'UP references no raw or other snapshot table'
);
assert_migration(preg_match('/telegram|op_token_telegram/i', $up . $down) === 0, 'Migration files contain no Telegram reference');
assert_migration(preg_match('/op_rh_v2_marcaciones_crudas/i', $up . $down) === 0, 'Migration files contain no raw schema reference');

assert_migration(count($downStatements) === 1, 'Rollback contains exactly one executable SQL statement');
assert_migration(preg_match('/\bALTER\s+TABLE\b/i', $down) === 0, 'Rollback performs no schema alteration');
assert_migration(preg_match('/\bVARCHAR\s*\(\s*36\s*\)/i', $down) === 0, 'Rollback has no VARCHAR(36) narrowing target');
assert_migration(preg_match('/\bCREATE\s+PROCEDURE\b/i', $down) === 0, 'Rollback creates no stored procedure');
assert_migration(preg_match('/\bDELIMITER\b/i', $down) === 0, 'Rollback uses no client delimiter directive');
assert_migration(
    preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|LOAD\s+DATA)\b/i', $down) === 0,
    'Rollback contains no data mutation'
);
assert_migration(
    preg_match('/\b(?:CREATE|DROP|RENAME|TRUNCATE)\s+(?:TABLE|INDEX|KEY|CONSTRAINT|PROCEDURE|FUNCTION|TRIGGER|EVENT)\b/i', $down) === 0,
    'Rollback creates or drops no schema object'
);
assert_migration(
    preg_match('/^SIGNAL\s+SQLSTATE\s+\'45000\'\s+SET\s+MESSAGE_TEXT\s*=\s*\'[^\']*\'$/i', $downStatements[0] ?? '') === 1,
    'Rollback is one top-level fail-closed SIGNAL statement'
);
$downLower = strtolower($down);
assert_migration(
    strpos($downLower, 'automatic schema narrowing is prohibited') !== false &&
    strpos($downLower, 'currently frozen') !== false &&
    strpos($downLower, 'explicit operator') !== false &&
    strpos($downLower, 'truncation or rewrite') !== false,
    'Rollback documents the frozen contract and authorized recovery policy'
);

echo 'Summary: all static migration assertions passed. No database was opened; MariaDB runtime syntax was not tested.' . "\n";
