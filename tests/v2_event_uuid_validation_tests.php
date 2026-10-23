<?php

require_once __DIR__ . '/../modelo/v2/EventUuidLengthValidator.php';

$passed = 0;
$failed = 0;

function event_uuid_test(string $name, callable $test): void {
    global $passed, $failed;
    try {
        $test();
        echo "PASS: {$name}\n";
        $passed++;
    } catch (Throwable $exception) {
        echo "FAIL: {$name} — {$exception->getMessage()}\n";
        $failed++;
    }
}

function event_uuid_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$validAtGate = static fn(string $value): bool =>
    $value !== '' && !EventUuidLengthValidator::exceedsMaximum($value);

event_uuid_test('E01 length 1 accepted', static function () use ($validAtGate): void {
    event_uuid_assert($validAtGate('x'), 'one character should pass');
});
event_uuid_test('E02 length 36 accepted', static function () use ($validAtGate): void {
    event_uuid_assert($validAtGate(str_repeat('a', 36)), '36 characters should pass');
});
event_uuid_test('E03 length 39 accepted', static function () use ($validAtGate): void {
    event_uuid_assert($validAtGate(str_repeat('a', 39)), '39 characters should pass');
});
event_uuid_test('E04 length 64 accepted', static function () use ($validAtGate): void {
    event_uuid_assert($validAtGate(str_repeat('a', 64)), '64 characters should pass');
});
event_uuid_test('E05 length 65 rejected', static function (): void {
    event_uuid_assert(EventUuidLengthValidator::exceedsMaximum(str_repeat('a', 65)), '65 characters should fail');
});
event_uuid_test('UTF-8 character count, not byte count', static function (): void {
    event_uuid_assert(strlen(str_repeat('é', 64)) === 128, 'fixture must contain 128 bytes');
    event_uuid_assert(!EventUuidLengthValidator::exceedsMaximum(str_repeat('é', 64)), '64 UTF-8 characters should pass');
    event_uuid_assert(EventUuidLengthValidator::exceedsMaximum(str_repeat('é', 65)), '65 UTF-8 characters should fail');
});
event_uuid_test('E06 trim before length allows a value within limit', static function () use ($validAtGate): void {
    $value = EventUuidLengthValidator::normalize('  ' . str_repeat('a', 64) . '  ');
    event_uuid_assert(strlen($value) === 64 && $validAtGate($value), 'trimmed 64-character value should pass');
});
event_uuid_test('E07 trim before length rejects a value over limit', static function (): void {
    $value = EventUuidLengthValidator::normalize(' ' . str_repeat('a', 65) . ' ');
    event_uuid_assert(EventUuidLengthValidator::exceedsMaximum($value), 'trimmed 65-character value should fail');
});
event_uuid_test('E08 spaces-only remains empty after trim', static function () use ($validAtGate): void {
    $value = EventUuidLengthValidator::normalize('   ');
    event_uuid_assert($value === '' && !$validAtGate($value), 'spaces-only value should remain rejected by empty gate');
});
event_uuid_test('E09 non-RFC opaque identifier permitted', static function () use ($validAtGate): void {
    event_uuid_assert($validAtGate('UUID-PUNCH-001'), 'opaque non-RFC identifier should pass');
});
event_uuid_test('E10 canonical UUIDv4 permitted', static function () use ($validAtGate): void {
    event_uuid_assert($validAtGate('550e8400-e29b-41d4-a716-446655440000'), 'UUIDv4 should pass');
});
event_uuid_test('E11 case preserved', static function (): void {
    $value = EventUuidLengthValidator::normalize('AbC-123');
    event_uuid_assert($value === 'AbC-123', 'identifier case must not be normalized');
});
event_uuid_test('Controller preserves empty-first ordering and rejects before persistence', static function (): void {
    $source = file_get_contents(__DIR__ . '/../modelo/v2/IngestaPunchV2Controller.php');
    event_uuid_assert(is_string($source), 'controller source must be readable');
    $emptyGate = strpos($source, 'if (empty($eventUuid)');
    $lengthGate = strpos($source, 'EventUuidLengthValidator::exceedsMaximum($eventUuid)');
    $persist = strpos($source, 'registrarPunchEvent($conexion, $datosIngesta)');
    event_uuid_assert($emptyGate !== false && $lengthGate !== false && $persist !== false, 'expected validation/persistence gates missing');
    event_uuid_assert($emptyGate < $lengthGate && $lengthGate < $persist, 'required order is empty, max length, persistence');
    event_uuid_assert(str_contains($source, 'http_response_code(400);') && str_contains($source, '"code" => 400'), '400 response convention missing');
    event_uuid_assert(str_contains($source, '"status" => "SOLICITUD INCORRECTA"'), '400 status convention missing');
    event_uuid_assert(str_contains($source, 'event_uuid excede el máximo permitido de 64 caracteres.'), 'deterministic error message missing');
});

echo "\nFOCUSED_EVENT_UUID_VALIDATION: {$passed} PASS / {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
