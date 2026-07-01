<?php

namespace Ldiebold\Isolate\Console;

use Illuminate\Console\Command;
use Ldiebold\Isolate\Exceptions\ConflictException;
use Ldiebold\Isolate\Exceptions\InvalidConfigurationException;
use Ldiebold\Isolate\Exceptions\NoAvailableNumberException;
use Ldiebold\Isolate\Isolate;
use Ldiebold\Isolate\IsolationRequest;
use Ldiebold\Isolate\IsolationResult;
use Ldiebold\Isolate\Support\ConfigCacheGuard;
use Throwable;

class IsolateCommand extends Command
{
    protected $signature = 'isolate
        {--number= : Use this exact instance number (0 = reset to vanilla)}
        {--auto : Auto-select the next free instance number}
        {--reset : Forced return to instance 0 (vanilla)}
        {--migrate : Run migrations after isolating}
        {--seed : Seed the database after isolating (implies --migrate)}
        {--restart : Fire registered restart hooks after applying}
        {--no-copy : Skip copying worktree files (node_modules, .env) from the origin}';

    protected $description = 'Isolate this checkout: pick an instance number and write disjoint ports, prefixes and database name.';

    public function handle(Isolate $isolate, ConfigCacheGuard $guard): int
    {
        if (($code = $guard->freshen()) !== null) {
            return $code;
        }

        if (! $this->numberOptionIsValid($isolate->maxInstances())) {
            return self::FAILURE;
        }

        if ($this->option('no-copy')) {
            config(['isolate.worktree.copy' => []]);
        }

        $isolate->copyProgressUsing(fn (string $message) => $this->line('  '.$message));

        try {
            $result = $isolate->run($this->request());
        } catch (ConflictException|NoAvailableNumberException|InvalidConfigurationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('restart')) {
            $isolate->fireRestart($result->plan);
        }

        $exitCode = self::SUCCESS;

        if (($this->option('migrate') || $this->option('seed')) && ! $this->migrate($isolate, $result)) {
            $exitCode = self::FAILURE;
        }

        $this->summarize($result);

        return $exitCode;
    }

    protected function request(): IsolationRequest
    {
        if ($this->option('reset')) {
            return IsolationRequest::reset();
        }

        $number = $this->option('number');

        return $number === null
            ? IsolationRequest::auto()
            : IsolationRequest::for((int) $number);
    }

    protected function numberOptionIsValid(int $max): bool
    {
        $number = $this->option('number');

        if ($number === null || $this->option('reset')) {
            return true;
        }

        if (! ctype_digit((string) $number) || (int) $number >= $max) {
            $this->error("--number must be between 0 and {$max} (max_instances - 1).");

            return false;
        }

        return true;
    }

    protected function migrate(Isolate $isolate, IsolationResult $result): bool
    {
        $isolate->pointConnectionAtPlan($result->plan);

        try {
            if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
                return false;
            }

            if ($this->option('seed') && $this->call('db:seed', ['--force' => true]) !== self::SUCCESS) {
                return false;
            }
        } catch (Throwable $e) {
            $message = 'Migration/seed failed: '.$e->getMessage();
            $this->warn($message);
            $result->apply->addWarning($message);

            return false;
        }

        return true;
    }

    protected function summarize(IsolationResult $result): void
    {
        $this->newLine();
        $this->info($result->isReset()
            ? 'Isolation reset to vanilla (instance 0).'
            : "Isolated as instance {$result->number}.");

        foreach ($result->apply->changes as $change) {
            $this->line('  • '.$change);
        }

        foreach ([...$result->warnings, ...$result->apply->warnings] as $warning) {
            $this->warn('  ! '.$warning);
        }

        $this->newLine();
        $this->line('Restart any running services (serve, queue, horizon, reverb, vite) to apply the changes.');
    }
}
