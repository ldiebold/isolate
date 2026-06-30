<?php

namespace Ldiebold\Isolate\CollisionDetectors;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Conflict;
use Ldiebold\Isolate\Contracts\CollisionDetector;
use Ldiebold\Isolate\Database\DatabaseInspector;
use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\SideEffect;
use Ldiebold\Isolate\SideEffectKind;

/**
 * Reports a conflict when a candidate's database already exists. A failed probe
 * yields no conflict, since it cannot confirm one.
 */
class DatabaseCollisionDetector implements CollisionDetector
{
    protected DatabaseInspector $inspector;

    public function __construct(
        Repository $config,
        ConnectionResolverInterface $connections,
        Filesystem $files,
        string $databasePath,
    ) {
        $this->inspector = new DatabaseInspector($config, $connections, $files, $databasePath);
    }

    public function conflicts(IsolationPlan $plan): iterable
    {
        foreach ($plan->sideEffects as $effect) {
            $conflict = match ($effect->kind) {
                SideEffectKind::CreateDatabase => $this->databaseConflict($effect),
            };

            if ($conflict !== null) {
                yield $conflict;
            }
        }
    }

    protected function databaseConflict(SideEffect $effect): ?Conflict
    {
        $connection = (string) $effect->get('connection');
        $database = (string) $effect->get('database');

        if (! $this->inspector->exists($connection, $database)) {
            return null;
        }

        return Conflict::database(
            (string) $effect->get('env'),
            $database,
            "Database [{$database}] already exists for connection [{$connection}]."
        );
    }
}
