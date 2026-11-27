<?php

declare(strict_types=1);

/** Read-only M11 projection for the device gatekeeper, independent of J1/J2. */
final class DeviceEntryPolicyV2
{
    public function __construct(private mysqli $db) {}

    public function resolve(int $workplace, string $date): array
    {
        $stmt = $this->db->prepare('SELECT id, policy_version, early_entry_allowed_minutes, duplicate_punch_window_minutes, exit_priority_window_minutes FROM op_rh_v2_attendance_policy_versions WHERE workplace_id = ? AND effective_from_local_date <= ? ORDER BY effective_from_local_date DESC, policy_version DESC LIMIT 1');
        if (!$stmt) throw new RuntimeException('ENTRY_POLICY_READ_FAILED');
        try {
            if (!$stmt->bind_param('is', $workplace, $date) || !$stmt->execute() || !($result = $stmt->get_result())) {
                throw new RuntimeException('ENTRY_POLICY_READ_FAILED');
            }
            return self::normalize($result->fetch_assoc());
        } finally {
            $stmt->close();
        }
    }

    /** No row is not proof of a valid historical policy. */
    public static function normalize(?array $row): array
    {
        $fields = ['early_entry_allowed_minutes', 'duplicate_punch_window_minutes', 'exit_priority_window_minutes'];
        if ($row === null) throw new RuntimeException('ENTRY_POLICY_UNRESOLVED');
        foreach (array_merge(['id', 'policy_version'], $fields) as $field) {
            if (!array_key_exists($field, $row)) throw new RuntimeException('ENTRY_POLICY_INVALID');
        }
        $integer = static function (mixed $value, int $min, int $max): int {
            if ((!is_int($value) && !(is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)))
                || (int)$value < $min || (int)$value > $max) throw new RuntimeException('ENTRY_POLICY_INVALID');
            return (int)$value;
        };
        $integer($row['id'], 1, PHP_INT_MAX);
        $version = $integer($row['policy_version'], 1, PHP_INT_MAX);
        $values = array_map(static fn($field) => $row[$field], $fields);
        if ($values === [null, null, null]) {
            return ['policy_version' => $version, 'early_entry_allowed_minutes' => 10, 'duplicate_punch_window_minutes' => null, 'exit_priority_minutes' => null];
        }
        $early = $integer($values[0], 0, 10);
        // A partially populated V1.1 row is invalid under the same M11 ranges as J1.
        $repeat = $integer($values[1], 1, 10);
        $exitPriority = $integer($values[2], 30, 120);
        return ['policy_version' => $version, 'early_entry_allowed_minutes' => $early, 'duplicate_punch_window_minutes' => $repeat, 'exit_priority_minutes' => $exitPriority];
    }
}
