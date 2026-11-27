<?php
declare(strict_types=1);

/** Connection-scoped, nonblocking advisory lock; fail closed on SQL errors. */
final class JourneyMariaDbLock
{
    public function __construct(private mysqli $db) {}

    public function assertOwned(int $worker, int $station, string $date): void
    {
        require_once __DIR__ . '/JourneyExecutionCoordinator.php';
        $name = substr(JourneyExecutionCoordinator::lockName($worker, $station, $date), 0, 64);
        $stmt = $this->db->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS owned');
        if (!$stmt) throw new RuntimeException('JOURNEY_LOCK_OWNERSHIP_UNVERIFIED');
        try {
            $stmt->bind_param('s', $name);
            if (!$stmt->execute()) throw new RuntimeException('JOURNEY_LOCK_OWNERSHIP_UNVERIFIED');
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if (!is_array($row) || (int)$row['owned'] !== 1) throw new RuntimeException('JOURNEY_LOCK_OWNERSHIP_UNVERIFIED');
        } finally { $stmt->close(); }
    }

    public function acquire(string $name): bool
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(?, 0) AS acquired');
        if (!$stmt) throw new RuntimeException('JOURNEY_LOCK_SQL_ERROR');
        try {
            $stmt->bind_param('s', $name);
            if (!$stmt->execute()) throw new RuntimeException('JOURNEY_LOCK_SQL_ERROR');
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if (!is_array($row) || $row['acquired'] === null) throw new RuntimeException('JOURNEY_LOCK_SQL_ERROR');
            return (int)$row['acquired'] === 1;
        } finally { $stmt->close(); }
    }

    public function release(string $name): void
    {
        $stmt = $this->db->prepare('SELECT RELEASE_LOCK(?) AS released');
        if (!$stmt) throw new RuntimeException('JOURNEY_UNLOCK_SQL_ERROR');
        try {
            $stmt->bind_param('s', $name);
            if (!$stmt->execute()) throw new RuntimeException('JOURNEY_UNLOCK_SQL_ERROR');
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if (!is_array($row) || (int)$row['released'] !== 1) throw new RuntimeException('JOURNEY_UNLOCK_SQL_ERROR');
        } finally { $stmt->close(); }
    }
}
