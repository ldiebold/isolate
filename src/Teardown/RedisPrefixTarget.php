<?php

namespace Ldiebold\Isolate\Teardown;

use Ldiebold\Isolate\IsolationPlan;

/**
 * A single per-instance Redis keyspace the planner considered for flushing: the
 * instance number, the env key carrying the prefix (e.g. REDIS_PREFIX), the
 * resolved prefix value, the decision (status) and the resolved plan (so the
 * PrefixFlushed event/hook can expose the instance's env map).
 *
 * The sibling of TeardownTarget for the Redis side, kept as its own value object
 * so the database target stays a plain, single-purpose type rather than a
 * polymorphic union. The shared TeardownStatus carries the per-instance guards:
 * WillDrop means "this keyspace will be flushed".
 */
readonly class RedisPrefixTarget
{
    public function __construct(
        public int $number,
        public string $env,
        public string $prefix,
        public TeardownStatus $status,
        public IsolationPlan $plan,
    ) {}

    public function willFlush(): bool
    {
        return $this->status === TeardownStatus::WillDrop;
    }
}
