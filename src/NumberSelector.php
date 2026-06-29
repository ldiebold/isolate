<?php

namespace Ldiebold\Isolate;

use Ldiebold\Isolate\Claims\SelfClaimProvider;
use Ldiebold\Isolate\Contracts\CollisionDetector;
use Ldiebold\Isolate\Exceptions\ConflictException;
use Ldiebold\Isolate\Exceptions\NoAvailableNumberException;
use Ldiebold\Isolate\Support\RestrictedPorts;

/**
 * Chooses an instance number, preferring a valid self claim and otherwise
 * scanning for the first candidate that is free of conflicts.
 */
class NumberSelector
{
    /**
     * @param  array<int, CollisionDetector>  $detectors
     */
    public function __construct(
        protected Resolver $resolver,
        protected RestrictedPorts $restrictedPorts,
        protected SelfClaimProvider $selfClaim,
        protected array $detectors,
        protected int $maxInstances,
        protected bool $throwOnConflict = false,
    ) {}

    public function next(): Selection
    {
        $self = $this->selfClaim->number();

        if ($self !== null && $self >= 0 && $self < $this->maxInstances) {
            return $this->selectSelf($self);
        }

        for ($n = 0; $n < $this->maxInstances; $n++) {
            if ($this->browserBlocked($n)) {
                continue;
            }

            $conflicts = $this->conflictsFor($this->resolver->resolve($n));

            if ($conflicts === []) {
                return new Selection($n, false);
            }

            if ($this->throwOnConflict) {
                throw new ConflictException($conflicts);
            }
        }

        throw new NoAvailableNumberException(
            'No free isolation number in 0..'.($this->maxInstances - 1)
            .'; reclaim a number or widen isolate.max_instances.'
        );
    }

    /**
     * Run every registered detector against a resolved plan.
     *
     * @return array<int, Conflict>
     */
    public function conflictsFor(IsolationPlan $plan): array
    {
        $conflicts = [];

        foreach ($this->detectors as $detector) {
            foreach ($detector->conflicts($plan) as $conflict) {
                $conflicts[] = $conflict;
            }
        }

        return $conflicts;
    }

    /**
     * Whether a browser-facing port for instance n lands on a restricted port,
     * which makes the number unusable for selection.
     */
    public function browserBlocked(int $n): bool
    {
        foreach ($this->resolver->browserFacingPorts($n) as $port) {
            if ($this->restrictedPorts->isRestricted($port)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A recorded self number is reused. Its resources existing is idempotent, so
     * only live port conflicts are reported (and only escalated in strict mode,
     * since ownership cannot be proven portably).
     */
    protected function selectSelf(int $self): Selection
    {
        $conflicts = $this->conflictsFor($this->resolver->resolve($self));

        $portConflicts = collect($conflicts)
            ->filter(static fn (Conflict $conflict): bool => $conflict->kind === ConflictKind::Port)
            ->values()
            ->all();

        if ($this->throwOnConflict && $portConflicts !== []) {
            throw new ConflictException(
                $portConflicts,
                "Self instance {$self} has a live port conflict."
            );
        }

        return new Selection($self, true, $portConflicts);
    }
}
