<?php

declare(strict_types=1);

/** Pure M06 calendar-day deadline calculation; it never evaluates attendance. */
final class PunchIncidenceDeadlineService
{
    /** @return array{cutoff_local:string,expired:bool} */
    public static function evaluate(
        string $checkDate,
        int $days,
        string $timezoneId,
        ?DateTimeImmutable $now = null
    ): array {
        if (!self::isCivilDate($checkDate) || $days < 0 || $days > 30
            || !in_array($timezoneId, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('INVALID_DEADLINE_INPUT');
        }

        $timezone = new DateTimeZone($timezoneId);
        $dayZero = DateTimeImmutable::createFromFormat('!Y-m-d', $checkDate, $timezone);
        if ($dayZero === false || $dayZero->format('Y-m-d') !== $checkDate) {
            throw new InvalidArgumentException('INVALID_DEADLINE_INPUT');
        }

        // The configured cutoff is inclusive through the full local second
        // 23:59:59; DateTime carries microseconds for the receipt instant.
        $cutoff = $dayZero->modify('+' . $days . ' days')->setTime(23, 59, 59, 999999);
        $instant = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);

        return [
            'cutoff_local' => $cutoff->format('Y-m-d H:i:s'),
            'expired' => $instant > $cutoff,
        ];
    }

    public static function isCivilDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
