<?php

namespace Ldiebold\Isolate\Locking;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Contracts\Lock;
use Throwable;

/**
 * An app-local exclusive flock that serializes concurrent runs in this checkout.
 * If the lock cannot be acquired it warns and still runs the critical section.
 */
class FileLock implements Lock
{
    /**
     * @param  (Closure(string): void)|null  $onWarning
     */
    public function __construct(
        protected string $path,
        protected Filesystem $files,
        protected ?Closure $onWarning = null,
    ) {}

    public function get(callable $critical): mixed
    {
        $handle = $this->open();

        if ($handle === null) {
            $this->warn("Could not open lock file [{$this->path}]; proceeding without a lock.");

            return $critical();
        }

        $acquired = flock($handle, LOCK_EX);

        if (! $acquired) {
            $this->warn("Could not acquire lock [{$this->path}]; proceeding without a lock.");

            try {
                return $critical();
            } finally {
                fclose($handle);
            }
        }

        try {
            return $critical();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return resource|null
     */
    protected function open()
    {
        $directory = dirname($this->path);

        if (! $this->files->isDirectory($directory)) {
            try {
                $this->files->makeDirectory($directory, 0755, true);
            } catch (Throwable) {
                return null;
            }
        }

        $handle = @fopen($this->path, 'c');

        return $handle === false ? null : $handle;
    }

    protected function warn(string $message): void
    {
        if ($this->onWarning !== null) {
            ($this->onWarning)($message);
        }
    }
}
