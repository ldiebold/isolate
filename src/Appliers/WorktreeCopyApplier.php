<?php

namespace Ldiebold\Isolate\Appliers;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\ApplyResult;
use Ldiebold\Isolate\Contracts\Applier;
use Ldiebold\Isolate\Contracts\DirectoryCopier;
use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\Support\WorktreeLocator;
use RuntimeException;
use Throwable;

/**
 * When isolate runs inside a linked git worktree, copies the configured paths
 * (if missing) from the origin repository so gitignored artifacts a fresh
 * `git worktree add` never carries over - chiefly node_modules - are hydrated
 * without a reinstall.
 *
 * Runs before DotenvApplier so a copied origin .env keeps its real values and
 * the per-instance keys are layered on top. It is best-effort: nothing here can
 * fail an isolation. Outside a worktree it is a silent no-op.
 */
class WorktreeCopyApplier implements Applier
{
    /**
     * @param  array<int, string>  $paths
     * @param  (Closure(string): void)|null  $notifier
     */
    public function __construct(
        protected WorktreeLocator $locator,
        protected DirectoryCopier $copier,
        protected Filesystem $files,
        protected string $basePath,
        protected array $paths,
        protected ?Closure $notifier = null,
    ) {}

    public function apply(IsolationPlan $plan): ApplyResult
    {
        $result = new ApplyResult;

        if ($this->paths === [] || ! $this->locator->isLinkedWorktree()) {
            return $result;
        }

        $origin = $this->locator->originPath();

        if ($origin === null || ! is_dir($origin)) {
            return $result->addWarning(
                'Isolate detected a worktree but could not resolve the origin repository; skipped worktree file copy.'
            );
        }

        $copied = [];

        foreach ($this->paths as $path) {
            $this->copyPath($origin, $path, $copied, $result);
        }

        if ($copied !== []) {
            $result->addChange('Hydrated worktree from '.$origin);

            foreach ($copied as $change) {
                $result->addChange($change);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $copied
     */
    protected function copyPath(string $origin, string $path, array &$copied, ApplyResult $result): void
    {
        $relative = $this->normalize($path);

        if ($relative === null) {
            $result->addWarning("Skipped worktree copy of [{$path}]: only relative paths inside the project are allowed.");

            return;
        }

        $source = $origin.DIRECTORY_SEPARATOR.$relative;
        $destination = $this->basePath.DIRECTORY_SEPARATOR.$relative;

        if (! $this->exists($source)) {
            return; // Origin does not have it - expected, stay silent.
        }

        if ($this->exists($destination)) {
            return; // Copy-if-missing: never overwrite.
        }

        try {
            $this->ensureParentDirectory($destination);
            $copied[] = $this->copy($relative, $source, $destination);
        } catch (Throwable $e) {
            $result->addWarning("Failed to copy [{$relative}] from origin: ".$e->getMessage());
        }
    }

    protected function copy(string $relative, string $source, string $destination): string
    {
        if (is_link($source)) {
            $this->copyLink($source, $destination);

            return "Copied {$relative}";
        }

        if (is_dir($source)) {
            $this->notify("Copying {$relative} …");
            $this->copier->copy($source, $destination);

            return "Copied {$relative}".$this->formatSize($destination);
        }

        $this->copyFile($source, $destination);

        return "Copied {$relative}";
    }

    protected function copyFile(string $source, string $destination): void
    {
        $temp = $destination.'.isolate-tmp-'.bin2hex(random_bytes(4));

        if (! @copy($source, $temp)) {
            @unlink($temp);

            throw new RuntimeException("Could not copy file to [{$destination}].");
        }

        if (! @rename($temp, $destination)) {
            @unlink($temp);

            throw new RuntimeException("Could not move [{$destination}] into place.");
        }
    }

    protected function copyLink(string $source, string $destination): void
    {
        $target = @readlink($source);

        if ($target === false) {
            throw new RuntimeException("Could not read symlink [{$source}].");
        }

        $temp = $destination.'.isolate-tmp-'.bin2hex(random_bytes(4));

        if (! @symlink($target, $temp)) {
            throw new RuntimeException("Could not recreate symlink [{$destination}].");
        }

        if (! @rename($temp, $destination)) {
            @unlink($temp);

            throw new RuntimeException("Could not move symlink [{$destination}] into place.");
        }
    }

    protected function ensureParentDirectory(string $destination): void
    {
        $parent = dirname($destination);

        if (! $this->files->isDirectory($parent)) {
            $this->files->makeDirectory($parent, 0755, true);
        }
    }

    /**
     * Resolve a config entry to a safe, repo-root-relative path, or null when it
     * is absolute or escapes the project via `..`.
     */
    protected function normalize(string $path): ?string
    {
        $path = trim($path);

        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return null;
        }

        $segments = [];

        foreach (preg_split('#[/\\\\]+#', $path) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * A broken symlink still "exists" as a link even though file_exists is false.
     */
    protected function exists(string $path): bool
    {
        return $this->files->exists($path) || is_link($path);
    }

    protected function formatSize(string $directory): string
    {
        try {
            $bytes = 0;

            foreach ($this->files->allFiles($directory, true) as $file) {
                $bytes += $file->getSize();
            }
        } catch (Throwable) {
            return '';
        }

        if ($bytes <= 0) {
            return '';
        }

        $units = ['B', 'K', 'M', 'G', 'T'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return ' ('.($power === 0 ? (string) $bytes : number_format($value, 1)).$units[$power].')';
    }

    protected function notify(string $message): void
    {
        if ($this->notifier !== null) {
            ($this->notifier)($message);
        }
    }
}
