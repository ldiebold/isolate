<?php

namespace Ldiebold\Isolate\Contracts;

use Ldiebold\Isolate\ApplyResult;
use Ldiebold\Isolate\IsolationPlan;

interface Applier
{
    /**
     * Apply the resolved plan, collecting applied changes and warnings.
     */
    public function apply(IsolationPlan $plan): ApplyResult;
}
