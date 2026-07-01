<?php

namespace Ldiebold\Isolate\Support;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Contracts\DirectoryCopier;
use RuntimeException;
use Throwable;

/**
 * Shared atomicity for directory copiers: every strategy writes into a temporary
 * sibling of the destination and is renamed into place only once complete, so a
 * failed copy never leaves a partial tree that copy-if-missing would later
 * mistake for a finished one.
 */
abstract class AbstractDirectoryCopier implements DirectoryCopier
{
    public function __construct(protected Filesystem $files) {}

    public function copy(string $source, string $destination): void
    {
        $this->atomically(
            $destination,
            fn (string $temp) => $this->copyInto($source, $temp),
        );
    }

    /**
     * Copy $source into the (non-existent) $temp path, creating it.
     */
    abstract protected function copyInto(string $source, string $temp): void;

    /**
     * @param  Closure(string): void  $writeInto
     */
    protected function atomically(string $destination, Closure $writeInto): void
    {
        $temp = $destination.'.isolate-tmp-'.bin2hex(random_bytes(4));
        $this->removeTemp($temp);

        try {
            $writeInto($temp);
        } catch (Throwable $e) {
            $this->removeTemp($temp);

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        if (! @rename($temp, $destination)) {
            $this->removeTemp($temp);

            throw new RuntimeException("Could not move the copied directory into place at [{$destination}].");
        }
    }

    protected function removeTemp(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);

            return;
        }

        if (is_dir($path)) {
            $this->files->deleteDirectory($path);

            return;
        }

        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
