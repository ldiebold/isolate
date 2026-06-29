<?php

namespace Ldiebold\Isolate\Events;

use Ldiebold\Isolate\ApplyResult;
use Ldiebold\Isolate\IsolationPlan;

/**
 * Dispatched after a plan has been applied (env written, side effects run).
 */
class IsolationApplied
{
    public function __construct(
        public IsolationPlan $plan,
        public ApplyResult $result,
    ) {}
}
