<?php
declare(strict_types=1);

/** Test-only guard. Never included by production runtime code. */
final class TestMariaDbIsolationGuard
{
    public const SCHEMA = 'checador_batch_isolated_v1';
    private const SOCKET_PATTERN = '~\A/tmp/checador-batch-validation-[A-Za-z0-9]+/mysql\.sock\z~D';

    public static function requireExplicitAuthorization(?string $socket = null): string
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('TEST_GUARD_CLI_REQUIRED');
        if (getenv('CHECADOR_DESTRUCTIVE_TESTS') !== '1') throw new RuntimeException('TEST_GUARD_EXPLICIT_AUTH_REQUIRED');
        $socket ??= getenv('JOURNEY_BATCH_TEST_SOCKET') ?: null;
        if (!is_string($socket) || !preg_match(self::SOCKET_PATTERN, $socket) || is_link($socket)) throw new RuntimeException('TEST_GUARD_SOCKET_REQUIRED');
        $root = realpath(dirname($socket));
        if ($root === false || $root !== dirname($socket) || !str_starts_with($root, '/tmp/checador-batch-validation-')) throw new RuntimeException('TEST_GUARD_ROOT_INVALID');
        $st = stat($root);
        if (!$st || $st['uid'] !== posix_geteuid() || (($st['mode'] & 0077) !== 0) || filetype($socket) !== 'socket' || fileowner($socket) !== posix_geteuid()) throw new RuntimeException('TEST_GUARD_ROOT_NOT_PRIVATE');
        return $socket;
    }

    public static function assertConnection(mysqli $db, string $socket, string $expectedSchema = self::SCHEMA): array
    {
        $socket = self::requireExplicitAuthorization($socket);
        if (!str_contains(strtolower($db->host_info), 'socket')) throw new RuntimeException('TEST_GUARD_TRANSPORT_INVALID');
        $r = $db->query('SELECT DATABASE() db,@@datadir datadir,@@socket socket,@@port port,@@pid_file pid_file,@@server_id server_id,@@hostname hostname')->fetch_assoc();
        return self::assertServerIdentity($r, $socket, $expectedSchema);
    }

    /** Validate an observed identity snapshot; exposed for harmless negative-fixture assertions. */
    public static function assertServerIdentity(array $r, string $socket, string $expectedSchema = self::SCHEMA): array
    {
        $socket = self::requireExplicitAuthorization($socket);
        $root = realpath(dirname($socket));
        $datadir = realpath((string)($r['datadir'] ?? ''));
        if (($r['db'] ?? null) !== $expectedSchema || !preg_match('/\A(?:checador_batch_isolated_v1|bd_admongas_v2_test_20261002)\z/D', $expectedSchema) || $root === false || $datadir !== $root.'/data' || ($r['socket'] ?? null) !== $socket || (int)($r['port'] ?? -1) !== 0 || (int)($r['server_id'] ?? 0) < 1 || !is_string($r['hostname'] ?? null) || $r['hostname'] === '') throw new RuntimeException('TEST_GUARD_INSTANCE_IDENTITY_INVALID');
        $pidFile = (string)($r['pid_file'] ?? '');
        if ($pidFile === '' || realpath(dirname($pidFile)) !== $root || !is_file($pidFile)) throw new RuntimeException('TEST_GUARD_PID_FILE_INVALID');
        $pid = (int)trim((string)file_get_contents($pidFile));
        if ($pid < 2 || !is_dir('/proc/'.$pid)) throw new RuntimeException('TEST_GUARD_SERVER_PROCESS_MISSING');
        $cmd = str_replace("\0", ' ', (string)@file_get_contents('/proc/'.$pid.'/cmdline'));
        if ($cmd === '' || !str_contains($cmd, $datadir) || !str_contains($cmd, $socket) || !preg_match('/(?:^|\s)--skip-networking(?:=1)?(?:\s|$)/', $cmd)) throw new RuntimeException('TEST_GUARD_SERVER_PROCESS_MISMATCH');
        return ['schema'=>$r['db'],'datadir'=>$datadir,'socket'=>$r['socket'],'port'=>(int)$r['port'],'pid'=>$pid,'server_id'=>(int)$r['server_id'],'hostname'=>$r['hostname']];
    }

    /** Kill only a connection whose own session identity is verified on this exact instance. */
    public static function killVerifiedConnection(mysqli $controller, mysqli $target, string $socket): void
    {
        self::assertConnection($controller, $socket);
        self::assertConnection($target, $socket);
        $id = (int)$target->query('SELECT CONNECTION_ID() id')->fetch_assoc()['id'];
        if ($id < 1) throw new RuntimeException('TEST_GUARD_CONNECTION_ID_INVALID');
        $controller->query('KILL CONNECTION '.$id);
    }

    /** Signal only a child process created by this runner and carrying this fixture identity. */
    public static function assertOwnedPhpProcess(int $pid, string $runnerFile, string $socket): void
    {
        self::requireExplicitAuthorization($socket);
        $runner = realpath($runnerFile);
        $cwd = realpath('/proc/'.$pid.'/cwd');
        $cmd = str_replace("\0", ' ', (string)@file_get_contents('/proc/'.$pid.'/cmdline'));
        $env = str_replace("\0", "\n", (string)@file_get_contents('/proc/'.$pid.'/environ'));
        if ($pid < 2 || $runner === false || $cwd !== realpath(dirname(__DIR__)) || !str_contains($cmd, $runner) || !str_contains($env, 'JOURNEY_BATCH_TEST_SOCKET='.$socket) || !str_contains($env, 'CHECADOR_DESTRUCTIVE_TESTS=1')) throw new RuntimeException('TEST_GUARD_PROCESS_IDENTITY_INVALID');
    }
}
