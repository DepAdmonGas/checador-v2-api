<?php

declare(strict_types=1);

/** Pure rules for the administrative configuration PIN; not connected to attendance PINs. */
final class AdminPinV2Policy
{
    public const PIN_TTL_SECONDS = 300;
    public const SESSION_TTL_SECONDS = 300;
    public const SESSION_IDLE_SECONDS = 120;
    public const LIMIT_WINDOW_SECONDS = 900;
    public const MAX_FAILURES = 3;

    public static function generatePin(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function validPin($pin): bool
    {
        return is_string($pin) && preg_match('/\A[0-9]{6}\z/D', $pin) === 1;
    }

    public static function enabled(): bool
    {
        return getenv('CHECADOR_ADMIN_PIN_ENABLED') === '1' && self::sharedSecret() !== null;
    }

    /** Shared only through private server configuration; never persist this value. */
    public static function sharedSecret(): ?string
    {
        $secret = (string)(getenv('CHECADOR_ADMIN_PIN_HMAC_SECRET') ?: '');
        return strlen($secret) >= 32 ? $secret : null;
    }

    public static function verifier(string $pin, string $secret): string
    {
        if (!self::validPin($pin) || strlen($secret) < 32) throw new InvalidArgumentException('ADMIN_PIN_INVALID');
        return hash_hmac('sha256', $pin, $secret);
    }

    public static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
    }

    public static function validUuid($value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value) === 1;
    }

    public static function pinValidAt(int $issued, int $expires, int $now): bool
    {
        return $expires === $issued + self::PIN_TTL_SECONDS && $now >= $issued && $now < $expires;
    }

    public static function sessionValidAt(int $created, int $lastActivity, int $expires, int $now): bool
    {
        return $expires === $created + self::SESSION_TTL_SECONDS && $lastActivity >= $created
            && $now >= $lastActivity && $now < $expires && $now < $lastActivity + self::SESSION_IDLE_SECONDS;
    }

    /** @return array{window_start:int,failures:int,blocked_until:?int} */
    public static function recordFailure(int $windowStart, int $failures, ?int $blockedUntil, int $now): array
    {
        if ($windowStart < 0 || $failures < 0 || $now < $windowStart) throw new InvalidArgumentException('INVALID_LIMIT_STATE');
        if ($blockedUntil !== null && $now < $blockedUntil) return ['window_start'=>$windowStart,'failures'=>$failures,'blocked_until'=>$blockedUntil];
        if ($now >= $windowStart + self::LIMIT_WINDOW_SECONDS) { $windowStart=$now; $failures=0; }
        $failures++;
        return ['window_start'=>$windowStart,'failures'=>$failures,'blocked_until'=>$failures >= self::MAX_FAILURES ? $now + self::LIMIT_WINDOW_SECONDS : null];
    }
}
