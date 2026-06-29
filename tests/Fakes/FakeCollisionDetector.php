<?php

namespace Ldiebold\Isolate\Tests\Fakes;

use Ldiebold\Isolate\Conflict;
use Ldiebold\Isolate\Contracts\CollisionDetector;
use Ldiebold\Isolate\IsolationPlan;

final class FakeCollisionDetector implements CollisionDetector
{
    /**
     * @param  array<int, array<int, Conflict>>  $byNumber  conflicts keyed by instance number
     */
    public function __construct(private array $byNumber = []) {}

    public function conflicts(IsolationPlan $plan): iterable
    {
        return $this->byNumber[$plan->number] ?? [];
    }
}
