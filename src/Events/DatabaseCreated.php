<?php

namespace Ldiebold\Isolate\Events;

use Ldiebold\Isolate\Database\CreateResult;
use Ldiebold\Isolate\IsolationPlan;

/**
 * Dispatched after a per-instance database has been created.
 */
class DatabaseCreated
{
    public function __construct(
        public CreateResult $result,
        public IsolationPlan $plan,
    ) {}
}
