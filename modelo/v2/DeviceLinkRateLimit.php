<?php

declare(strict_types=1);

/** Shared flock-backed fixed window; directory must be private to PHP-FPM and shared across instances. */
final class DeviceLinkRateLimit
{
    public static function allow(string $directory, string $remoteAddress, ?int $clock = null): bool
    {
        $dir = realpath($directory);
        if ($directory === '' || $dir === false || !is_dir($dir) || !is_writable($dir) || !function_exists('posix_geteuid') || fileowner($dir) !== posix_geteuid()
            || ((fileperms($dir) & 0077) !== 0) || filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
        }
        $path = $dir . '/' . hash('sha256', $remoteAddress) . '.limit';
        if (is_link($path)) throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
        $file = @fopen($path, 'c+');
        if (!$file) throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
        try {
            if (!flock($file, LOCK_EX)) throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
            $now = $clock ?? time();
            $stored = stream_get_contents($file);
            $data = $stored === '' ? null : json_decode($stored, true);
            if ($stored !== '' && (!is_array($data) || !isset($data['start'], $data['count']) || !is_int($data['start']) || !is_int($data['count']))) throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
            if ($data === null || $now >= $data['start'] + 60) $data = ['start'=>$now, 'count'=>0];
            if ($now < $data['start']) throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
            if ($data['count'] >= 10) return false;
            $data['count']++;
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, json_encode($data, JSON_THROW_ON_ERROR)) === false || !fflush($file)) throw new RuntimeException('RATE_LIMIT_UNAVAILABLE');
            return true;
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
}
