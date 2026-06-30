<?php

namespace Ldiebold\Isolate\Teardown;

use Ldiebold\Isolate\IsolationPlan;

/**
 * A single database the planner considered for teardown: its instance number,
 * the connection and resolved database name, the decision (status) and the
 * resolved plan (so the DatabaseDropped event/hook can expose the instance's
 * env map, e.g. REDIS_PREFIX, for coupled-resource cleanup).
 */
readonly class TeardownTarget
{
    public function __construct(
        public int $number,
        public string $connection,
        public string $database,
        public TeardownStatus $status,
        public IsolationPlan $plan,
    ) {}

    public function willDrop(): bool
    {
        return $this->status === TeardownStatus::WillDrop;
    }
}
