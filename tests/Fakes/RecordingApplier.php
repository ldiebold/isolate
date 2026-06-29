<?php

namespace Ldiebold\Isolate\Tests\Fakes;

use Ldiebold\Isolate\ApplyResult;
use Ldiebold\Isolate\Contracts\Applier;
use Ldiebold\Isolate\IsolationPlan;

final class RecordingApplier implements Applier
{
    public ?int $appliedNumber = null;

    public function apply(IsolationPlan $plan): ApplyResult
    {
        $this->appliedNumber = $plan->number;

        return (new ApplyResult)->addChange('recording applier ran for '.$plan->number);
    }
}
