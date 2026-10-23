<?php

/**
 * Validation helpers for the opaque V2 event identifier.
 */
class EventUuidLengthValidator {
    public const MAX_LENGTH = 64;

    public static function normalize($value): string {
        return trim($value);
    }

    public static function exceedsMaximum(string $eventUuid): bool {
        return mb_strlen($eventUuid, 'UTF-8') > self::MAX_LENGTH;
    }
}
