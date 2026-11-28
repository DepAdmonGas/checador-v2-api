<?php
declare(strict_types=1);
/** Test-only mysqli adapter: the genuine INSERT executes and commits before the observer runs. */
final class JourneyHarnessMysqli extends mysqli {
    public ?Closure $afterLevel2Insert = null;
    public ?Closure $afterCommit = null;
    public function commit(int $flags=0, ?string $name=null): bool { $ok=parent::commit($flags,$name); if($ok && $this->afterCommit!==null) ($this->afterCommit)(); return $ok; }
    public function prepare(string $query): mysqli_stmt|false {
        return new JourneyHarnessStmt($this, $query);
    }
}
final class JourneyHarnessStmt extends mysqli_stmt {
    private JourneyHarnessMysqli $connection;
    private bool $observe;
    public function __construct(JourneyHarnessMysqli $connection, string $query) {
        $this->connection=$connection;
        $this->observe=(bool)preg_match('/^\\s*INSERT\\s+INTO\\s+op_rh_v2_level2_window_evaluations\\b/i',$query);
        parent::__construct($connection,$query);
    }
    public function execute(?array $params=null): bool {
        $ok=parent::execute($params);
        if($ok && $this->observe && $this->connection->afterLevel2Insert!==null) {
            $callback=$this->connection->afterLevel2Insert;
            $this->connection->afterLevel2Insert=null;
            $callback();
        }
        return $ok;
    }
}
