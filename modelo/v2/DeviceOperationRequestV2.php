<?php

declare(strict_types=1);

/** Versioned, domain-separated MACs for durable operation idempotency and biometric revisions. */
final class DeviceOperationRequestV2
{
    /** @return array{key_id:string,key:string} */
    public static function activeKey(): array
    {
        $id = (string)(getenv('CHECADOR_DEVICE_OPERATION_ACTIVE_KEY_ID') ?: '');
        $ring = self::keyRing();
        if ($id === '' || !isset($ring[$id])) throw new RuntimeException('DEVICE_OPERATION_KEY_UNAVAILABLE');
        return ['key_id'=>$id, 'key'=>$ring[$id]];
    }

    public static function key(string $id): string
    {
        $ring = self::keyRing();
        if (!isset($ring[$id])) throw new RuntimeException('DEVICE_OPERATION_KEY_UNAVAILABLE');
        return $ring[$id];
    }

    public static function fingerprint(string $key, array $canonicalRequest): string
    {
        $json = json_encode($canonicalRequest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return hash_hmac('sha256', "device-operation-request-v1\0" . $json, $key);
    }

    public static function revision(int $deviceId, int $workerId, int $sequence, string $templateBytes, ?string $keyId = null): string
    {
        if ($deviceId < 1 || $workerId < 1 || $sequence < 0) throw new InvalidArgumentException('INVALID_BIOMETRIC_REVISION_SCOPE');
        $key = $keyId === null ? self::activeKey() : ['key_id'=>$keyId, 'key'=>self::key($keyId)];
        $message = "biometric-revision-v1\0" . $deviceId . "\0" . $workerId . "\0" . $sequence . "\0" . $templateBytes;
        return 'b1.' . $key['key_id'] . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $message, $key['key'], true)), '+/', '-_'), '=');
    }

    public static function revisionMatches(string $candidate, int $deviceId, int $workerId, int $sequence, string $templateBytes): bool
    {
        if (preg_match('/\Ab1\.([A-Za-z0-9_-]{1,32})\.([A-Za-z0-9_-]{43})\z/D', $candidate, $m) !== 1) return false;
        $expected = self::revision($deviceId, $workerId, $sequence, $templateBytes, $m[1]);
        return hash_equals($expected, $candidate);
    }

    /** @return array<string,string> */
    private static function keyRing(): array
    {
        $raw = (string)(getenv('CHECADOR_DEVICE_OPERATION_HMAC_KEY_RING') ?: '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !$decoded) throw new RuntimeException('DEVICE_OPERATION_KEY_UNAVAILABLE');
        $ring = [];
        foreach ($decoded as $id => $encoded) {
            if (!is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $id) !== 1 || !is_string($encoded)) {
                throw new RuntimeException('DEVICE_OPERATION_KEY_UNAVAILABLE');
            }
            $key = base64_decode($encoded, true);
            if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('DEVICE_OPERATION_KEY_UNAVAILABLE');
            $ring[$id] = $key;
        }
        return $ring;
    }
}
