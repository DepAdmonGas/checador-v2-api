<?php
declare(strict_types=1);

/** Serializes redemption attempts for one direct peer in a private shared directory. */
final class AdminPinIpRateLimit
{
    public static function attempt(string $directory, string $address, callable $operation, ?int $clock = null): array
    {
        $dir = realpath($directory);
        if ($directory === '' || $dir === false || !is_dir($dir) || !is_writable($dir)
            || !function_exists('posix_geteuid') || fileowner($dir) !== posix_geteuid()
            || (fileperms($dir) & 0077) !== 0 || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
        }
        $path = $dir . '/admin-pin-' . hash('sha256', $address) . '.limit';
        if (is_link($path)) throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
        $file = @fopen($path, 'c+');
        if (!$file) throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
        try {
            if (!flock($file, LOCK_EX)) throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
            $now = $clock ?? time(); $stored = stream_get_contents($file);
            $data = $stored === '' ? [] : json_decode($stored, true);
            if (!is_array($data)) throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
            foreach ($data as $timestamp) if (!is_int($timestamp) || $timestamp > $now) throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
            $data = array_values(array_filter($data, static fn(int $t): bool => $t > $now - 900));
            if (count($data) >= 60) return ['status_code'=>429, 'status'=>'RATE_LIMITED'];
            // Reserve a failure before calling the operation: a crash cannot erase an attempt.
            $data[] = $now;
            self::write($file, $data);
            $result = $operation();
            if (!isset($result['status_code']) || !in_array((int)$result['status_code'], [401, 429], true)) array_pop($data);
            elseif (count($data) >= 60) $result = ['status_code'=>429, 'status'=>'RATE_LIMITED'];
            self::write($file, $data);
            return $result;
        } finally { flock($file, LOCK_UN); fclose($file); }
    }

    private static function write($file, array $data): void
    {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
    }
}
