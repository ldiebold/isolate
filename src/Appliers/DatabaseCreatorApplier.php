<?php

namespace Ldiebold\Isolate\Appliers;

use Ldiebold\Isolate\ApplyResult;
use Ldiebold\Isolate\Contracts\Applier;
use Ldiebold\Isolate\Database\CreateOutcome;
use Ldiebold\Isolate\Database\DatabaseCreatorManager;
use Ldiebold\Isolate\Isolate;
use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\SideEffect;
use Ldiebold\Isolate\SideEffectKind;

/**
 * Consumes create_database side effects, creating each database idempotently and
 * firing the afterDatabaseCreated hook. Failures degrade to warnings so a
 * missing grant or down server never aborts isolation.
 */
class DatabaseCreatorApplier implements Applier
{
    public function __construct(
        protected DatabaseCreatorManager $databases,
        protected Isolate $manager,
    ) {}

    public function apply(IsolationPlan $plan): ApplyResult
    {
        $result = new ApplyResult;

        foreach ($plan->sideEffects as $effect) {
            match ($effect->kind) {
                SideEffectKind::CreateDatabase => $this->createDatabase($effect, $plan, $result),
            };
        }

        return $result;
    }

    protected function createDatabase(SideEffect $effect, IsolationPlan $plan, ApplyResult $result): void
    {
        $creation = $this->databases->create(
            (string) $effect->get('connection'),
            (string) $effect->get('database'),
        );

        match ($creation->outcome) {
            CreateOutcome::Created => $result->addChange("Created database [{$creation->database}]"),
            CreateOutcome::Existed => $result->addChange("Database [{$creation->database}] already exists"),
            CreateOutcome::Skipped => $result->addWarning(
                $creation->message ?? "Skipped database [{$creation->database}]"
            ),
        };

        if ($creation->wasCreated()) {
            $this->manager->fireAfterDatabaseCreated($creation, $plan);
        }
    }
}
