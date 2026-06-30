<?php

namespace Ldiebold\Isolate\Console;

use Illuminate\Console\Command;
use Ldiebold\Isolate\Database\DropOutcome;
use Ldiebold\Isolate\Database\DropResult;
use Ldiebold\Isolate\Isolate;
use Ldiebold\Isolate\IsolationRequest;
use Ldiebold\Isolate\Redis\FlushOutcome;
use Ldiebold\Isolate\Redis\FlushResult;
use Ldiebold\Isolate\Redis\KeyspaceFlusherManager;
use Ldiebold\Isolate\Support\ConfigCacheGuard;
use Ldiebold\Isolate\Teardown\RedisPrefixTarget;
use Ldiebold\Isolate\Teardown\TeardownRequest;
use Ldiebold\Isolate\Teardown\TeardownStatus;
use Ldiebold\Isolate\Teardown\TeardownTarget;

class TeardownCommand extends Command
{
    protected $signature = 'isolate:teardown
        {number? : Instance whose database(s) and Redis keyspace(s) to tear down}
        {--all : Tear down every existing instance except vanilla (0) and the active one}
        {--force : Skip confirmation; and (only with a named number) permit tearing down the active instance}
        {--dry-run : Show what would be torn down without changing anything}
        {--limit= : Highest instance number to scan with --all (defaults to max_instances)}
        {--keep-env : When tearing down the active instance, do not reset .env to vanilla}
        {--keep-redis : Do not flush the per-instance Redis keyspace(s)}';

    protected $description = 'Tear down per-instance resources created by isolate: drops the database(s) and flushes the Redis keyspace(s). Redis flushing is skipped with --keep-redis; .env is left alone unless the active instance is torn down with --force.';

    public function handle(Isolate $isolate, ConfigCacheGuard $guard): int
    {
        if (($code = $guard->freshen()) !== null) {
            return $code;
        }

        if (! $this->argumentsAreValid()) {
            return self::FAILURE;
        }

        $request = $this->request();
        $planner = $isolate->teardownPlanner();
        $targets = $planner->plan($request);
        $redisTargets = $this->option('keep-redis') ? [] : $planner->planRedis($targets);
        $flusher = $isolate->keyspaceFlusherManager();

        if ($this->option('dry-run')) {
            return $this->dryRun($flusher, $targets, $redisTargets);
        }

        $this->reportProtected($request, $targets);

        $droppable = array_values(array_filter($targets, static fn (TeardownTarget $t): bool => $t->willDrop()));
        $flushable = array_values(array_filter($redisTargets, static fn (RedisPrefixTarget $t): bool => $t->willFlush()));

        if ($droppable === [] && $flushable === []) {
            $this->info('Nothing to tear down.');

            return self::SUCCESS;
        }

        if (! $this->confirmTargets($flusher, $droppable, $flushable)) {
            $this->info('Teardown aborted.');

            return self::SUCCESS;
        }

        $this->dropAll($isolate, $droppable);
        $this->flushAll($isolate, $flusher, $flushable);
        $this->resetActiveIfNeeded($isolate, $request, $this->actedNumbers($droppable, $flushable));

        return self::SUCCESS;
    }

    protected function argumentsAreValid(): bool
    {
        $number = $this->argument('number');
        $all = (bool) $this->option('all');

        if ($number === null && ! $all) {
            $this->error('Specify an instance number to tear down, or pass --all.');

            return false;
        }

        if ($number !== null && $all) {
            $this->error('Pass either a number or --all, not both.');

            return false;
        }

        if ($number !== null && ! ctype_digit((string) $number)) {
            $this->error('The instance number must be a non-negative integer.');

            return false;
        }

        return true;
    }

    protected function request(): TeardownRequest
    {
        $number = $this->argument('number');
        $limit = $this->option('limit');

        return new TeardownRequest(
            $number !== null ? (int) $number : null,
            (bool) $this->option('all'),
            (bool) $this->option('force'),
            is_string($limit) && ctype_digit($limit) ? (int) $limit : null,
        );
    }

    /**
     * @param  array<int, TeardownTarget>  $targets
     * @param  array<int, RedisPrefixTarget>  $redisTargets
     */
    protected function dryRun(KeyspaceFlusherManager $flusher, array $targets, array $redisTargets): int
    {
        if ($targets === [] && $redisTargets === []) {
            $this->info('Nothing to tear down.');

            return self::SUCCESS;
        }

        if ($targets !== []) {
            $this->table(
                ['#', 'Database', 'Connection', 'Action'],
                array_map(static fn (TeardownTarget $t): array => [
                    $t->number,
                    $t->database,
                    $t->connection,
                    match ($t->status) {
                        TeardownStatus::WillDrop => 'drop',
                        TeardownStatus::Vanilla => 'protected (vanilla)',
                        TeardownStatus::ActiveProtected => 'protected (active)',
                        TeardownStatus::Missing => 'missing',
                    },
                ], $targets),
            );
        }

        if ($redisTargets !== []) {
            $this->table(
                ['#', 'Redis prefix', 'Keys', 'Action'],
                array_map(fn (RedisPrefixTarget $t): array => [
                    $t->number,
                    $t->prefix,
                    $t->willFlush() ? $this->keyCountLabel($flusher->count($t->prefix)) : '—',
                    match ($t->status) {
                        TeardownStatus::WillDrop => 'flush',
                        TeardownStatus::Vanilla => 'protected (vanilla)',
                        TeardownStatus::ActiveProtected => 'protected (active)',
                        TeardownStatus::Missing => 'flush',
                    },
                ], $redisTargets),
            );
        }

        $this->newLine();
        $this->line('Dry run: no databases were dropped and no Redis keys were flushed.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, TeardownTarget>  $targets
     */
    protected function reportProtected(TeardownRequest $request, array $targets): void
    {
        foreach ($targets as $target) {
            if ($target->status === TeardownStatus::Vanilla) {
                $this->warn("Refusing to tear down instance {$target->number}: vanilla database [{$target->database}] is protected.");
            } elseif ($target->status === TeardownStatus::ActiveProtected) {
                $this->warn($request->all
                    ? "Skipping active instance {$target->number}."
                    : "Refusing to tear down active instance {$target->number}. Re-run with --force to drop it and reset .env.");
            } elseif ($target->status === TeardownStatus::Missing) {
                $this->info("Instance {$target->number}: database [{$target->database}] does not exist (nothing to drop).");
            }
        }
    }

    /**
     * @param  array<int, TeardownTarget>  $droppable
     * @param  array<int, RedisPrefixTarget>  $flushable
     */
    protected function confirmTargets(KeyspaceFlusherManager $flusher, array $droppable, array $flushable): bool
    {
        if ($this->option('force') || $this->option('no-interaction')) {
            return true;
        }

        if ($droppable !== []) {
            $this->warn('About to permanently drop the following databases:');

            foreach ($droppable as $target) {
                $this->line("  • instance {$target->number}: {$target->database} ({$target->connection})");
            }
        }

        if ($flushable !== []) {
            $this->warn('About to flush the following Redis keyspaces:');

            foreach ($flushable as $target) {
                $count = $this->keyCountLabel($flusher->count($target->prefix));
                $this->line("  • instance {$target->number}: {$target->prefix} ({$count})");
            }
        }

        return $this->confirm('This deletes the data for good. Continue?', false);
    }

    /**
     * @param  array<int, TeardownTarget>  $droppable
     */
    protected function dropAll(Isolate $isolate, array $droppable): void
    {
        $manager = $isolate->databaseDestroyerManager();

        foreach ($droppable as $target) {
            $result = $manager->destroy($target->connection, $target->database);

            $this->reportDrop($target, $result);

            if ($result->wasDropped()) {
                $isolate->fireAfterDatabaseDropped($result, $target->plan);
            }
        }
    }

    /**
     * @param  array<int, RedisPrefixTarget>  $flushable
     */
    protected function flushAll(Isolate $isolate, KeyspaceFlusherManager $flusher, array $flushable): void
    {
        foreach ($flushable as $target) {
            $result = $flusher->flush($target->prefix);

            $this->reportFlush($target, $result);

            if ($result->wasFlushed()) {
                $isolate->fireAfterPrefixFlushed($result, $target->plan);
            }
        }
    }

    protected function reportDrop(TeardownTarget $target, DropResult $result): void
    {
        match ($result->outcome) {
            DropOutcome::Dropped => $this->line("  • Dropped database [{$result->database}] (instance {$target->number})"),
            DropOutcome::Missing => $this->line("  • Database [{$result->database}] was already gone (instance {$target->number})"),
            DropOutcome::Skipped => $this->warn('  ! '.($result->message ?? "Skipped database [{$result->database}]")),
        };
    }

    protected function reportFlush(RedisPrefixTarget $target, FlushResult $result): void
    {
        match ($result->outcome) {
            FlushOutcome::Flushed => $this->line("  • Flushed {$result->keyCount} Redis key(s) for [{$result->prefix}] (instance {$target->number})"),
            FlushOutcome::Empty => $this->line("  • No Redis keys to flush for [{$result->prefix}] (instance {$target->number})"),
            FlushOutcome::Skipped => $this->warn('  ! '.($result->message ?? "Skipped Redis prefix [{$result->prefix}]")),
        };
    }

    protected function keyCountLabel(?int $count): string
    {
        if ($count === null) {
            return 'count unavailable';
        }

        return $count.' key'.($count === 1 ? '' : 's');
    }

    /**
     * Instance numbers acted on in either dimension (database drop or Redis
     * flush), used to decide whether the active instance's .env must be reset.
     *
     * @param  array<int, TeardownTarget>  $droppable
     * @param  array<int, RedisPrefixTarget>  $flushable
     * @return array<int, int>
     */
    protected function actedNumbers(array $droppable, array $flushable): array
    {
        return array_values(array_unique(array_merge(
            array_map(static fn (TeardownTarget $t): int => $t->number, $droppable),
            array_map(static fn (RedisPrefixTarget $t): int => $t->number, $flushable),
        )));
    }

    /**
     * Reset .env to vanilla when the active instance was the one torn down, so the
     * app is never left pointing at a dropped database. Runs even if the drop
     * degraded to a warning.
     *
     * @param  array<int, int>  $actedNumbers
     */
    protected function resetActiveIfNeeded(Isolate $isolate, TeardownRequest $request, array $actedNumbers): void
    {
        if ($this->option('keep-env')) {
            return;
        }

        $current = $isolate->currentNumber();

        if ($current === null || $request->number !== $current) {
            return;
        }

        if (! in_array($current, $actedNumbers, true)) {
            return;
        }

        $isolate->run(IsolationRequest::reset());

        $this->newLine();
        $this->info('Reset .env to vanilla (instance 0).');
        $this->line('Restart any running services (serve, queue, horizon, reverb, vite) to apply the changes.');
    }
}
