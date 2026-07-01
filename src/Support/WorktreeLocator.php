<?php

namespace Ldiebold\Isolate\Support;

use Closure;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Answers two questions about the directory isolate is running in: is it a
 * linked git worktree, and if so where is the origin repository it was created
 * from? The origin is the main worktree - the source we copy gitignored
 * artifacts (node_modules, .env) from. The git runner is injectable so the
 * detection logic can be tested without a real repository.
 */
class WorktreeLocator
{
    /**
     * @var Closure(array<int, string>): ?string
     */
    protected Closure $git;

    /**
     * @param  (Closure(array<int, string>): ?string)|null  $git
     */
    public function __construct(protected string $workingDirectory, ?Closure $git = null)
    {
        $this->git = $git ?? fn (array $args): ?string => $this->runGit($args);
    }

    /**
     * The origin (main worktree) path when the working directory is a linked
     * worktree, or null otherwise (main worktree, plain clone, non-repo, or git
     * unavailable - all of which are a silent no-op for the caller).
     */
    public function originPath(): ?string
    {
        if (! $this->isLinkedWorktree()) {
            return null;
        }

        return $this->mainWorktree();
    }

    /**
     * A linked worktree has a per-worktree git dir distinct from the shared
     * common dir; the main worktree resolves both to the same path.
     */
    public function isLinkedWorktree(): bool
    {
        $gitDir = ($this->git)(['rev-parse', '--absolute-git-dir']);
        $commonDir = ($this->git)(['rev-parse', '--path-format=absolute', '--git-common-dir']);

        if ($gitDir === null || $commonDir === null) {
            return false;
        }

        return $this->normalize($gitDir) !== $this->normalize($commonDir);
    }

    /**
     * The main worktree is always the first entry of `git worktree list`.
     */
    protected function mainWorktree(): ?string
    {
        $output = ($this->git)(['worktree', 'list', '--porcelain']);

        if ($output === null) {
            return null;
        }

        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $path = substr($line, strlen('worktree '));

                return $path === '' ? null : $path;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $args
     */
    protected function runGit(array $args): ?string
    {
        try {
            $process = new Process(['git', ...$args], $this->workingDirectory);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        return $output === '' ? null : $output;
    }

    protected function normalize(string $path): string
    {
        return rtrim($path, '/\\');
    }
}
