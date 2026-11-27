<?php
declare(strict_types=1);

/** Coordinates one journey without embedding scheduling or pairing rules. */
final class JourneyExecutionCoordinator
{
    /** @param callable(string):bool $acquire @param callable(string):void $release */
    public function __construct(private $acquire, private $release) {}

    public static function lockName(int $worker, int $station, string $date): string
    {
        if ($worker < 1 || $station < 1 || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $date)) {
            throw new InvalidArgumentException('INVALID_JOURNEY_SCOPE');
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        if (!checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('INVALID_JOURNEY_SCOPE');
        }
        return 'journey-worker:' . hash('sha256', (string) $worker);
    }

    /** Materialization, P1 and P2 run under the same journey lock. */
    public function execute(int $worker, int $station, string $date, callable $materialize, callable $pair, callable $calculate): array
    {
        // MariaDB lock names are limited to 64 characters on some deployments.
        $name = substr(self::lockName($worker, $station, $date), 0, 64);
        if (!(($this->acquire)($name))) {
            throw new RuntimeException('JOURNEY_LOCK_UNAVAILABLE');
        }
        try {
            $shift = $materialize();
            $pairing = $pair($shift);
            $calculation = $calculate($pairing);
            return ['shift' => $shift, 'pairing' => $pairing, 'calculation' => $calculation];
        } finally {
            ($this->release)($name);
        }
    }
}
