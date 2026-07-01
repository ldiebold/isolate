<?php

namespace Ldiebold\Isolate\Support;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Prefers the platform's native copy tool for speed (reflink/clonefile make a
 * node_modules-sized copy near-instant on modern filesystems), falling back to
 * the pure-PHP copier whenever the tool is unavailable or errors. Both paths
 * inherit the temp-then-rename atomicity from AbstractDirectoryCopier.
 */
class SystemDirectoryCopier extends AbstractDirectoryCopier
{
    public function __construct(
        Filesystem $files,
        protected PhpDirectoryCopier $fallback,
        protected string $osFamily = PHP_OS_FAMILY,
    ) {
        parent::__construct($files);
    }

    protected function copyInto(string $source, string $temp): void
    {
        $command = $this->command($source, $temp);

        if ($command !== null && $this->run($command)) {
            return;
        }

        // Native tool missing or failed: discard any partial output and let the
        // portable copier produce a correct tree in its place.
        $this->removeTemp($temp);

        $this->fallback->copyTree($source, $temp);
    }

    /**
     * The native copy invocation for this OS, or null to use the fallback.
     *
     * @return array<int, string>|null
     */
    protected function command(string $source, string $temp): ?array
    {
        return match ($this->osFamily) {
            'Linux' => ['cp', '-a', '--reflink=auto', $source, $temp],
            'Darwin', 'BSD', 'Solaris' => ['cp', '-a', $source, $temp],
            'Windows' => ['robocopy', $source, $temp, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NC', '/NP'],
            default => null,
        };
    }

    /**
     * @param  array<int, string>  $command
     */
    protected function run(array $command): bool
    {
        try {
            $process = new Process($command);
            $process->setTimeout(null);
            $process->run();
        } catch (Throwable) {
            return false;
        }

        $code = $process->getExitCode();

        // robocopy signals success with exit codes 0-7; 8 and above are errors.
        if ($command[0] === 'robocopy') {
            return $code !== null && $code < 8;
        }

        return $process->isSuccessful();
    }
}
