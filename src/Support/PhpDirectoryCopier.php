<?php

namespace Ldiebold\Isolate\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Portable, dependency-free recursive copy used everywhere as the correctness
 * backstop (and directly on platforms without a fast native tool). Symlinks are
 * recreated rather than followed, so node_modules bin links and pnpm stores
 * survive the copy intact.
 */
class PhpDirectoryCopier extends AbstractDirectoryCopier
{
    /**
     * Raw (non-atomic) recursive copy of $source into the new directory $target.
     * Exposed so SystemDirectoryCopier can reuse it as its fallback without
     * re-implementing the walk.
     */
    public function copyTree(string $source, string $target): void
    {
        if (! is_dir($source)) {
            throw new RuntimeException("Source directory [{$source}] does not exist.");
        }

        $this->makeDirectory($target);

        $prefix = strlen(rtrim($source, '/\\')) + 1;

        /** @var SplFileInfo $item */
        foreach ($this->iterator($source) as $item) {
            $destination = $target.DIRECTORY_SEPARATOR.substr($item->getPathname(), $prefix);

            match (true) {
                $item->isLink() => $this->copyLink($item, $destination),
                $item->isDir() => $this->makeDirectory($destination),
                default => $this->copyFile($item, $destination),
            };
        }
    }

    protected function copyInto(string $source, string $temp): void
    {
        $this->copyTree($source, $temp);
    }

    /**
     * @return RecursiveIteratorIterator<RecursiveDirectoryIterator>
     */
    protected function iterator(string $source): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            // SKIP_DOTS keeps . and .. out; symlinked directories are yielded but
            // not descended into (hasChildren defaults to disallowing links).
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
    }

    protected function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException("Could not create directory [{$path}].");
        }
    }

    protected function copyFile(SplFileInfo $item, string $destination): void
    {
        if (! @copy($item->getPathname(), $destination)) {
            throw new RuntimeException("Could not copy file to [{$destination}].");
        }
    }

    protected function copyLink(SplFileInfo $item, string $destination): void
    {
        $target = @readlink($item->getPathname());

        if ($target === false || ! @symlink($target, $destination)) {
            throw new RuntimeException("Could not recreate symlink at [{$destination}].");
        }
    }
}
