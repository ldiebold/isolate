<?php

namespace Ldiebold\Isolate\CollisionDetectors;

use Ldiebold\Isolate\Conflict;
use Ldiebold\Isolate\Contracts\CollisionDetector;
use Ldiebold\Isolate\Contracts\PortChecker;
use Ldiebold\Isolate\IsolationPlan;

/**
 * Reports a conflict for every resolved port that is already bound.
 */
class PortCollisionDetector implements CollisionDetector
{
    public function __construct(protected PortChecker $ports) {}

    public function conflicts(IsolationPlan $plan): iterable
    {
        foreach ($plan->ports as $key => $port) {
            if ($this->ports->inUse($port)) {
                yield Conflict::port($key, $port, "Port {$port} ({$key}) is already in use.");
            }
        }
    }
}
