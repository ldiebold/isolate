<?php

namespace Ldiebold\Isolate\Events;

use Ldiebold\Isolate\Database\DropResult;
use Ldiebold\Isolate\IsolationPlan;

/**
 * Dispatched after a per-instance database has been dropped. Carries the resolved
 * plan so listeners can clean up coupled resources for the torn-down instance,
 * e.g. flushing Redis keys for the instance's REDIS_PREFIX.
 */
class DatabaseDropped
{
    public function __construct(
        public DropResult $result,
        public IsolationPlan $plan,
    ) {}
}
