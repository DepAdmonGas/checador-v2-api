<?php
declare(strict_types=1);

/** Shared authorized CLI identity check. PREPROD is not contractually enabled. */
final class JourneyEnvironmentProfile
{
    /** Fail closed unless this connection's effective session timezone is UTC. */
    public static function requireUtcSession(object $db): void
    {
        try {
            if (!$db->query("SET SESSION time_zone = '+00:00'")) throw new RuntimeException('UTC_SESSION_UNAVAILABLE');
            $result = $db->query('SELECT @@session.time_zone AS session_timezone');
            $row = $result ? $result->fetch_assoc() : null;
            if (!is_array($row) || ($row['session_timezone'] ?? null) !== '+00:00') throw new RuntimeException('UTC_SESSION_UNAVAILABLE');
        } catch (Throwable $e) {
            try { $db->close(); } catch (Throwable $ignored) {}
            throw new RuntimeException('UTC_SESSION_UNAVAILABLE', 0, $e);
        }
    }
    /** Recognizes the proposed profile only; it must never authorize a connection. */
    public static function isPreparedPreprod(array $config, int $uid): bool
    {
        $stations = $config['stations'] ?? null;
        if (!is_array($stations)) return false;
        $expected = [1,2,3,4,5,6,7,8,14];
        sort($stations, SORT_NUMERIC);
        return ($config['environment'] ?? null) === 'preprod'
            && ($config['service_id'] ?? null) === 'journey-preprod'
            && ($config['principal_type'] ?? null) === 'SERVICE'
            && ($config['principal_id'] ?? null) === 1
            && ($config['system_uid'] ?? null) === 10000 && $uid === 10000
            && ($config['database'] ?? null) === 'bd_admongas_v2_preprod'
            && ($config['sql_account'] ?? null) === 'checador_motor_preprod@localhost'
            && ($config['socket'] ?? null) === '/run/mysqld/mysqld.sock'
            && $stations === $expected;
    }

    /** Domain-separated identity key for a future approved PREPROD checkpoint. */
    public static function proposedPreprodCheckpointKey(array $identity): string
    {
        foreach (['environment','server_identity','database','service_id','sql_account','scope'] as $key) {
            if (!isset($identity[$key]) || !is_string($identity[$key]) || $identity[$key] === '') {
                throw new InvalidArgumentException('CHECKPOINT_IDENTITY_INCOMPLETE');
            }
        }
        if ($identity['environment'] !== 'preprod' || $identity['service_id'] !== 'journey-preprod'
            || $identity['database'] !== 'bd_admongas_v2_preprod'
            || $identity['sql_account'] !== 'checador_motor_preprod@localhost') {
            throw new InvalidArgumentException('CHECKPOINT_IDENTITY_DENIED');
        }
        return hash('sha256', json_encode(['journey-preprod-checkpoint-v1', $identity['environment'],
            $identity['server_identity'], $identity['database'], $identity['service_id'],
            $identity['sql_account'], $identity['scope']], JSON_THROW_ON_ERROR));
    }
    public static function validate(array $config, int $uid, ?int $station = null): void
    {
        if (self::isPreparedPreprod($config, $uid)) {
            if ($station !== null && !in_array($station, $config['stations'], true)) throw new RuntimeException('SERVICE_SCOPE_DENIED');
            return;
        }
        if (($config['environment'] ?? 'development') !== 'development'
            || ($config['service_id'] ?? null) !== 'journey-local'
            || ($config['system_uid'] ?? null) !== $uid
            || !is_array($config['stations'] ?? null) || !$config['stations']) {
            throw new RuntimeException('SERVICE_SCOPE_DENIED');
        }
        foreach ($config['stations'] as $id) {
            if (!is_int($id) || $id < 1) throw new RuntimeException('SERVICE_SCOPE_DENIED');
        }
        if ($station !== null && !in_array($station, $config['stations'], true)) {
            throw new RuntimeException('SERVICE_SCOPE_DENIED');
        }
    }

    /** Check every path component: no symlinks or untrusted writable directories. */
    private static function protectedPath(string $path, bool $socket = false): void
    {
        $mysql = posix_getpwnam('mysql');
        $mysqlUid = is_array($mysql) ? (int)$mysql['uid'] : -1;
        $parts = explode('/', trim($path, '/')); $current = '';
        foreach ($parts as $index => $part) {
            $current .= '/'.$part; clearstatcache(true, $current); $s = @lstat($current);
            $last = $index === count($parts)-1;
            if (!$s || is_link($current) || (($s['mode'] & 0022) !== 0 && !($socket && $last))) throw new RuntimeException('PREPROD_PATH_DENIED');
            if ($last && $socket) {
                if (($s['mode'] & 0170000) !== 0140000 || !in_array($s['uid'], [0, $mysqlUid], true)) throw new RuntimeException('PREPROD_SOCKET_DENIED');
            } elseif (!$last) {
                if (!is_dir($current) || !in_array($s['uid'], $socket ? [0, $mysqlUid] : [0], true)) throw new RuntimeException('PREPROD_PATH_DENIED');
            } elseif (!is_file($current) || $s['uid'] !== 0) throw new RuntimeException('PREPROD_CONFIG_DENIED');
        }
    }

    /** Root-controlled authorization; separate approval for profile, dry-run and writes. */
    public static function preprodPolicy(string $mode): array
    {
        $path = '/etc/checador/journey-preprod.json';
        self::protectedPath($path);
        $policy = json_decode((string)file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($policy) || ($policy['profile_authorized'] ?? false) !== true
            || ($policy[$mode.'_authorized'] ?? false) !== true
            || !is_string($policy['host_identity'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $policy['host_identity']) !== 1) throw new RuntimeException('PREPROD_AUTHORIZATION_DENIED');
        $hostPath = '/etc/checador/journey-host-identity'; self::protectedPath($hostPath);
        $identity = trim((string)file_get_contents($hostPath));
        if (!hash_equals($policy['host_identity'], $identity)) throw new RuntimeException('PREPROD_HOST_IDENTITY_DENIED');
        self::protectedPath('/run/mysqld/mysqld.sock', true);
        return $policy;
    }

    public static function preprodIdentityMatches(string $transport, array $row): bool
    {
        return stripos($transport, 'unix socket') !== false
            && ($row['db'] ?? null) === 'bd_admongas_v2_preprod'
            && ($row['account'] ?? null) === 'checador_motor_preprod@localhost'
            && ($row['hostname'] ?? null) === '82e9a5d.online-server.cloud'
            && ($row['socket'] ?? null) === '/run/mysqld/mysqld.sock'
            && ($row['datadir'] ?? null) === '/var/lib/mysql/'
            && (string)($row['server_id'] ?? '') === '1' && (string)($row['port'] ?? '') === '3306';
    }

    public static function connectPreprod(array $config, string $mode): array
    {
        $policy = self::preprodPolicy($mode);
        if (getenv('DB_HOST') !== 'localhost' || getenv('DB_NAME') !== 'bd_admongas_v2_preprod'
            || getenv('DB_USER') !== 'checador_motor_preprod' || !in_array(getenv('DB_PORT'), ['3306', false], true)
            || getenv('DB_PASS') === false) throw new RuntimeException('DATABASE_SCOPE_DENIED');
        $db = mysqli_init();
        try {
            if (!$db || !$db->real_connect('localhost', 'checador_motor_preprod', getenv('DB_PASS'), 'bd_admongas_v2_preprod', 3306, '/run/mysqld/mysqld.sock')) throw new RuntimeException('PREPROD_CONNECT_FAILED');
            $row = self::verifyPreprodConnection($db);
            return [$db, $policy['host_identity'], $row];
        } catch (Throwable $e) { if ($db) $db->close(); throw $e; }
    }

    /** Kept separate so the identity checks can run against controlled connections. */
    public static function verifyPreprodConnection(object $db): array
    {
        $r = $db->query('SELECT DATABASE() AS db, CURRENT_USER() AS account, @@hostname AS hostname, @@socket AS socket, @@datadir AS datadir, @@server_id AS server_id, @@port AS port');
        $row = $r ? $r->fetch_assoc() : null;
        if (!is_array($row) || !self::preprodIdentityMatches($db->host_info, $row) || !$db->set_charset('utf8')) throw new RuntimeException('PREPROD_IDENTITY_DENIED');
        return $row;
    }
}
