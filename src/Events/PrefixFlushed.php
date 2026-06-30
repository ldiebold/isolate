<?php

namespace Ldiebold\Isolate\Events;

use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\Redis\FlushResult;

/**
 * Dispatched after a per-instance Redis keyspace has been flushed (keys were
 * actually removed). Carries the resolved plan so listeners can react to the
 * torn-down instance's env map. Symmetric with DatabaseDropped.
 */
class PrefixFlushed
{
    public function __construct(
        public FlushResult $result,
        public IsolationPlan $plan,
    ) {}
}
