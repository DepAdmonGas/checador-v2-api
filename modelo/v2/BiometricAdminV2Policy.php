<?php

declare(strict_types=1);

/** Pure request, template, CAS revision and replay rules for biometric administration. */
final class BiometricAdminV2Policy
{
    public const TEMPLATE_FORMAT = 'ZKTECO_10_BASE64';
    public const MAX_TEMPLATE_BYTES = 2048;

    public static function validateReference(string $reference): bool
    {
        return $reference !== '' && $reference === trim($reference) && strlen($reference) <= 64
            && preg_match('//u', $reference) === 1;
    }

    /** @return array{operation:string,request_id:string,expected_revision:string,template_base64:string,template_bytes:string} */
    public static function validatePayload(array $body): array
    {
        $required = ['request_id','operation','expected_revision','template_format','template_base64'];
        if (count($body) !== count($required) || array_diff($required, array_keys($body))) throw new InvalidArgumentException('BAD_REQUEST');
        if (!AdminPinV2Policy::validUuid($body['request_id']) || strtolower($body['request_id']) !== $body['request_id'] || !is_string($body['operation'])
            || !in_array($body['operation'], ['CREATE','REPLACE'], true)
            || !is_string($body['expected_revision']) || !is_string($body['template_format'])
            || $body['template_format'] !== self::TEMPLATE_FORMAT || !is_string($body['template_base64'])) {
            throw new InvalidArgumentException('BAD_REQUEST');
        }
        $bytes = base64_decode($body['template_base64'], true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_TEMPLATE_BYTES
            || base64_encode($bytes) !== $body['template_base64']) throw new DomainException('INVALID_BIOMETRIC_TEMPLATE');
        if (!self::validRevisionShape($body['expected_revision'])) throw new InvalidArgumentException('BAD_REQUEST');
        return ['operation'=>$body['operation'], 'request_id'=>$body['request_id'],
            'expected_revision'=>$body['expected_revision'], 'template_base64'=>$body['template_base64'], 'template_bytes'=>$bytes];
    }

    public static function validRevisionShape(string $revision): bool
    {
        return preg_match('/\Ab1\.[A-Za-z0-9_-]{1,32}\.[A-Za-z0-9_-]{43}\z/D', $revision) === 1;
    }

    public static function decodeStoredTemplate(?string $base64): string
    {
        if ($base64 === null || $base64 === '') return '';
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_TEMPLATE_BYTES || base64_encode($bytes) !== $base64) {
            throw new BiometricAdminV2StoredTemplateException('INVALID_STORED_BIOMETRIC_TEMPLATE');
        }
        return $bytes;
    }

    public static function replayDecision(array $stored, string $operation, string $fingerprint, int $actorUserId): string
    {
        if (($stored['operation_kind'] ?? null) !== $operation
            || !is_string($stored['request_fingerprint'] ?? null)
            || !hash_equals($stored['request_fingerprint'], $fingerprint)
            || !is_numeric($stored['actor_user_id'] ?? null)
            || (int)$stored['actor_user_id'] !== $actorUserId) return 'CONFLICT';
        return 'REPLAY';
    }

    public static function mutationAllowed(string $operation, string $storedTemplate, string $expectedRevisionMatches): bool
    {
        if (!in_array($operation, ['CREATE','REPLACE'], true) || $expectedRevisionMatches !== 'MATCH') return false;
        return $operation === 'CREATE' ? $storedTemplate === '' : $storedTemplate !== '';
    }
}

final class BiometricAdminV2StoredTemplateException extends RuntimeException {}
