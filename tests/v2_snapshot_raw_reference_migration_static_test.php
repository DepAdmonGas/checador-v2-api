<?php

declare(strict_types=1);

/**
 * Static contract test for the Level 1 snapshot-member raw-reference migration.
 * It validates authored SQL text only; it does not establish MariaDB runtime behavior.
 */

$root = dirname(__DIR__);
$upPath = $root . '/migraciones/v2/2026_10_09_000002_add_snapshot_member_raw_reference_integrity.sql';
$downPath = $root . '/migraciones/v2/2026_10_09_000002_rollback_snapshot_member_raw_reference_integrity.sql';

function assert_raw_reference_migration(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }

    echo "[PASS] {$message}\n";
}

function raw_reference_sql_statements(string $sql): array
{
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
assert_raw_reference_migration($up !== false, 'Forward migration exists and is readable');
assert_raw_reference_migration($down !== false, 'Rollback policy file exists and is readable');

$up = (string) $up;
$down = (string) $down;
$upStatements = raw_reference_sql_statements($up);
$downStatements = raw_reference_sql_statements($down);
$memberTable = 'op_rh_v2_read_snapshot_members';

assert_raw_reference_migration(count($upStatements) === 4, 'Forward migration contains exactly four executable ALTER statements');
$alterTargets = [];
foreach ($upStatements as $statement) {
    if (preg_match('/^ALTER\s+TABLE\s+`([^`]+)`/i', $statement, $matches) === 1) {
        $alterTargets[] = $matches[1];
    }
}
assert_raw_reference_migration(
    count($alterTargets) === 4 && array_unique($alterTargets) === [$memberTable],
    'M01: every ALTER targets only op_rh_v2_read_snapshot_members'
);

assert_raw_reference_migration(
    preg_match('/^ALTER\s+TABLE\s+`op_rh_v2_read_snapshot_members`\s+MODIFY\s+COLUMN\s+`id_raw_punch`\s+INT\s*\(\s*11\s*\)\s+NOT\s+NULL$/i', $upStatements[0] ?? '') === 1,
    'M02: id_raw_punch target is signed INT(11) NOT NULL'
);
assert_raw_reference_migration(
    preg_match('/^ALTER\s+TABLE\s+`op_rh_v2_read_snapshot_members`\s+MODIFY\s+COLUMN\s+`id_raw_punch`\b[^;]*\bUNSIGNED\b/i', $upStatements[0] ?? '') === 0,
    'M03: altered id_raw_punch definition is not UNSIGNED'
);
assert_raw_reference_migration(
    preg_match('/^ALTER\s+TABLE\s+`op_rh_v2_read_snapshot_members`\s+ADD\s+INDEX\s+`idx_snapshot_member_raw_punch`\s*\(\s*`id_raw_punch`\s*\)$/i', $upStatements[1] ?? '') === 1,
    'M04: explicit FK-support index has id_raw_punch as its first column'
);
assert_raw_reference_migration(
    preg_match('/^ALTER\s+TABLE\s+`op_rh_v2_read_snapshot_members`\s+ADD\s+CONSTRAINT\s+`fk_snapshot_member_raw_punch`\s+FOREIGN\s+KEY\s*\(\s*`id_raw_punch`\s*\)\s+REFERENCES\s+`op_rh_v2_marcaciones_crudas`\s*\(\s*`id`\s*\)\s+ON\s+DELETE\s+RESTRICT$/i', $upStatements[2] ?? '') === 1,
    'M05/M06: raw FK references raw.id with ON DELETE RESTRICT'
);
assert_raw_reference_migration(
    preg_match('/^ALTER\s+TABLE\s+`op_rh_v2_read_snapshot_members`\s+ADD\s+UNIQUE\s+KEY\s+`uk_snapshot_member_raw_punch`\s*\(\s*`id_snapshot`\s*,\s*`id_raw_punch`\s*\)$/i', $upStatements[3] ?? '') === 1,
    'M07: UNIQUE(id_snapshot, id_raw_punch) is authored with the selected name'
);
assert_raw_reference_migration(
    preg_match('/\bDROP\b/i', $up) === 0 &&
    preg_match('/`uk_snapshot_member_order`|`fk_snapshot_members_snapshot`|`idx_snapshot_cursor_order`/i', $up) === 0,
    'M08/M09/M10: existing member-order unique, snapshot FK, and cursor index are not dropped or altered'
);
assert_raw_reference_migration(
    preg_match('/\bALTER\s+TABLE\s+`[^`]+`\s+[^;]*`event_uuid`/i', $up) === 0,
    'M11: migration does not alter event_uuid'
);
assert_raw_reference_migration(
    count(array_unique($alterTargets)) === 1 && ($alterTargets[0] ?? '') === $memberTable,
    'M12: migration does not alter the raw table'
);
assert_raw_reference_migration(
    count(array_filter(
        $upStatements,
        static fn (string $statement): bool => preg_match('/^(?:INSERT|UPDATE|DELETE|REPLACE|LOAD\s+DATA)\b/i', $statement) === 1
    )) === 0,
    'M13: migration contains no DML'
);
assert_raw_reference_migration(
    preg_match('/telegram|op_token_telegram/i', $up . $down) === 0,
    'M14: migration and rollback contain no Telegram references'
);

assert_raw_reference_migration(count($downStatements) === 1, 'Rollback contains exactly one executable statement');
assert_raw_reference_migration(
    preg_match('/\b(?:DROP\s+(?:FOREIGN\s+KEY|INDEX|KEY|CONSTRAINT)|ALTER\s+TABLE)\b/i', $down) === 0,
    'M15: rollback contains no DROP constraint/index or schema alteration'
);
assert_raw_reference_migration(
    preg_match('/^SIGNAL\s+SQLSTATE\s+\'45000\'\s+SET\s+MESSAGE_TEXT\s*=\s*\'[^\']*\'$/i', $downStatements[0] ?? '') === 1,
    'M16: rollback fails closed with one top-level SIGNAL'
);
assert_raw_reference_migration(preg_match('/\b(?:CREATE|DROP)\s+PROCEDURE\b/i', $down) === 0, 'M17: rollback uses no stored procedure');
assert_raw_reference_migration(preg_match('/\bDELIMITER\b/i', $down) === 0, 'M18: rollback uses no DELIMITER directive');
assert_raw_reference_migration(
    count(array_filter(
        $downStatements,
        static fn (string $statement): bool => preg_match('/^(?:INSERT|UPDATE|DELETE|REPLACE|LOAD\s+DATA)\b/i', $statement) === 1
    )) === 0,
    'Rollback contains no data mutation'
);
assert_raw_reference_migration(
    strpos(strtolower($down), 'automatic rollback prohibited') !== false &&
    strpos(strtolower($down), 'weaken') !== false &&
    strpos(strtolower($down), 'explicit operator authorization') !== false,
    'Rollback explains why automatic constraint removal is prohibited'
);

echo 'Summary: all static raw-reference migration assertions passed. No database was opened; MariaDB runtime behavior was not tested.' . "\n";
