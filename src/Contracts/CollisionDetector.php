<?php

namespace Ldiebold\Isolate\Contracts;

use Ldiebold\Isolate\Conflict;
use Ldiebold\Isolate\IsolationPlan;

interface CollisionDetector
{
    /**
     * Resource conflicts for this resolved candidate plan.
     *
     * @return iterable<Conflict>
     */
    public function conflicts(IsolationPlan $plan): iterable;
}
