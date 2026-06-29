<?php

namespace Ldiebold\Isolate\Support;

use Closure;
use Illuminate\Foundation\Application;
use Symfony\Component\Process\Process;

/**
 * Ensures the command does not run with stale cached config by clearing the
 * cache and re-executing once in a fresh process. The re-exec is injectable.
 */
class ConfigCacheGuard
{
    /**
     * @var Closure(array<int, string>): int
     */
    protected Closure $reexec;

    /**
     * @param  (Closure(array<int, string>): int)|null  $reexec
     */
    public function __construct(
        protected Application $app,
        ?Closure $reexec = null,
    ) {
        $this->reexec = $reexec ?? fn (array $argv): int => $this->spawn($argv);
    }

    /**
     * Returns null when the run may proceed, or the exit code of a re-executed
     * fresh process when the config had to be refreshed.
     */
    public function freshen(): ?int
    {
        if (! is_file($this->app->getCachedConfigPath())) {
            return null;
        }

        $this->clearCache();

        return ($this->reexec)($this->argv());
    }

    protected function clearCache(): void
    {
        $path = $this->app->getCachedConfigPath();

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function argv(): array
    {
        /** @var array<int, string> $argv */
        $argv = $_SERVER['argv'] ?? [];

        return array_values(array_slice($argv, 1));
    }

    /**
     * @param  array<int, string>  $argv
     */
    protected function spawn(array $argv): int
    {
        $process = new Process([PHP_BINARY, $this->app->basePath('artisan'), ...$argv]);
        $process->setTimeout(null);

        return $process->run(function (string $type, string $buffer): void {
            fwrite(STDOUT, $buffer);
        });
    }
}
