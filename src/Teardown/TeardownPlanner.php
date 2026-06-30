<?php

namespace Ldiebold\Isolate\Teardown;

use Ldiebold\Isolate\Database\DatabaseInspector;
use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\Resolver;
use Ldiebold\Isolate\SideEffect;
use Ldiebold\Isolate\SideEffectKind;

/**
 * Discovers what teardown should act on and applies the safety guards, returning
 * typed targets without mutating anything. The package keeps no registry of
 * created instances, so candidates are enumerated and probed (like isolate:list).
 * All teardown policy lives here; the destroyers stay dumb executors.
 */
class TeardownPlanner
{
    /**
     * @var array<string, string>|null
     */
    protected ?array $vanillaDatabases = null;

    public function __construct(
        protected Resolver $resolver,
        protected DatabaseInspector $inspector,
        protected ?int $currentNumber,
        protected int $maxInstances,
    ) {}

    /**
     * @return array<int, TeardownTarget>
     */
    public function plan(TeardownRequest $request): array
    {
        return $request->all
            ? $this->planAll($request)
            : $this->planSingle($request);
    }

    /**
     * Redis keyspace siblings for the given database targets: one per active
     * redis-keyspace env key (REDIS_PREFIX, HORIZON_PREFIX) per target, carrying
     * the same instance, plan and per-instance guard decision. A flush is still
     * scheduled when the database is missing, so orphaned keys left behind by an
     * already-dropped instance are cleaned up.
     *
     * @param  array<int, TeardownTarget>  $databaseTargets
     * @return array<int, RedisPrefixTarget>
     */
    public function planRedis(array $databaseTargets): array
    {
        $envKeys = $this->resolver->redisKeyspaceEnvKeys();

        if ($envKeys === []) {
            return [];
        }

        $targets = [];

        foreach ($databaseTargets as $target) {
            $status = $this->redisStatus($target->status);

            foreach ($envKeys as $env) {
                $prefix = $target->plan->get($env);

                if ($prefix === null || $prefix === '') {
                    continue;
                }

                $targets[] = new RedisPrefixTarget($target->number, $env, $prefix, $status, $target->plan);
            }
        }

        return $targets;
    }

    /**
     * @return array<int, TeardownTarget>
     */
    protected function planSingle(TeardownRequest $request): array
    {
        $n = $request->number ?? 0;
        $plan = $this->resolver->resolve($n);

        $targets = [];

        foreach ($this->databaseEffects($plan) as $effect) {
            $targets[] = $this->classify($n, $effect, $plan, $request->force);
        }

        return $targets;
    }

    /**
     * @return array<int, TeardownTarget>
     */
    protected function planAll(TeardownRequest $request): array
    {
        $limit = min($request->limit ?? $this->maxInstances, $this->maxInstances);

        $targets = [];

        for ($n = 1; $n < $limit; $n++) {
            $plan = $this->resolver->resolve($n);

            foreach ($this->databaseEffects($plan) as $effect) {
                $connection = (string) $effect->get('connection');
                $database = (string) $effect->get('database');

                if ($this->isVanilla($n, $effect) || ! $this->inspector->exists($connection, $database)) {
                    continue;
                }

                $status = $this->isActive($n) ? TeardownStatus::ActiveProtected : TeardownStatus::WillDrop;

                $targets[] = new TeardownTarget($n, $connection, $database, $status, $plan);
            }
        }

        return $targets;
    }

    protected function classify(int $n, SideEffect $effect, IsolationPlan $plan, bool $force): TeardownTarget
    {
        $connection = (string) $effect->get('connection');
        $database = (string) $effect->get('database');

        $status = match (true) {
            $this->isVanilla($n, $effect) => TeardownStatus::Vanilla,
            $this->isActive($n) => $force ? TeardownStatus::WillDrop : TeardownStatus::ActiveProtected,
            $this->inspector->exists($connection, $database) => TeardownStatus::WillDrop,
            default => TeardownStatus::Missing,
        };

        return new TeardownTarget($n, $connection, $database, $status, $plan);
    }

    /**
     * Map a database target's decision onto its Redis sibling. The vanilla and
     * active guards carry across unchanged; a missing database still warrants a
     * flush (orphaned keys), so it maps to WillDrop.
     */
    protected function redisStatus(TeardownStatus $status): TeardownStatus
    {
        return match ($status) {
            TeardownStatus::Vanilla => TeardownStatus::Vanilla,
            TeardownStatus::ActiveProtected => TeardownStatus::ActiveProtected,
            TeardownStatus::WillDrop, TeardownStatus::Missing => TeardownStatus::WillDrop,
        };
    }

    protected function isActive(int $n): bool
    {
        return $this->currentNumber !== null && $n === $this->currentNumber;
    }

    protected function isVanilla(int $n, SideEffect $effect): bool
    {
        if ($n === 0) {
            return true;
        }

        $env = (string) $effect->get('env');

        return ($this->vanillaDatabases()[$env] ?? null) === (string) $effect->get('database');
    }

    /**
     * The vanilla (instance 0) database name per env key, used as a second guard
     * so a name that resolves to the base value is never dropped.
     *
     * @return array<string, string>
     */
    protected function vanillaDatabases(): array
    {
        if ($this->vanillaDatabases !== null) {
            return $this->vanillaDatabases;
        }

        $map = [];

        foreach ($this->databaseEffects($this->resolver->resolve(0)) as $effect) {
            $map[(string) $effect->get('env')] = (string) $effect->get('database');
        }

        return $this->vanillaDatabases = $map;
    }

    /**
     * @return array<int, SideEffect>
     */
    protected function databaseEffects(IsolationPlan $plan): array
    {
        return array_values(array_filter(
            $plan->sideEffects,
            static fn (SideEffect $effect): bool => match ($effect->kind) {
                SideEffectKind::CreateDatabase => true,
            },
        ));
    }
}
