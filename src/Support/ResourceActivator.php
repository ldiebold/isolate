<?php

namespace Ldiebold\Isolate\Support;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;
use Ldiebold\Isolate\Contracts\PackageDetector;

/**
 * Evaluates a resource's active_when predicate. Supported forms:
 * 'always', ['env' => 'KEY'], ['config' => 'path'], ['package' => 'vendor/name'],
 * ['any' => [...]], ['all' => [...]].
 */
class ResourceActivator
{
    /**
     * @var (Closure(string): mixed)|null
     */
    protected ?Closure $envReader;

    /**
     * @param  (Closure(string): mixed)|null  $envReader
     */
    public function __construct(
        protected Repository $config,
        protected PackageDetector $packages,
        ?Closure $envReader = null,
    ) {
        $this->envReader = $envReader;
    }

    public function isActive(mixed $predicate): bool
    {
        if ($predicate === 'always' || $predicate === null) {
            return true;
        }

        if (! is_array($predicate)) {
            return false;
        }

        if (array_key_exists('env', $predicate)) {
            return filled($this->readEnv((string) $predicate['env']));
        }

        if (array_key_exists('config', $predicate)) {
            $path = ConfigPath::resolve((string) $predicate['config'], $this->config);

            return filled($this->config->get($path));
        }

        if (array_key_exists('package', $predicate)) {
            return $this->packages->installed((string) $predicate['package']);
        }

        if (array_key_exists('any', $predicate)) {
            foreach ((array) $predicate['any'] as $inner) {
                if ($this->isActive($inner)) {
                    return true;
                }
            }

            return false;
        }

        if (array_key_exists('all', $predicate)) {
            $inners = (array) $predicate['all'];

            foreach ($inners as $inner) {
                if (! $this->isActive($inner)) {
                    return false;
                }
            }

            return $inners !== [];
        }

        return false;
    }

    protected function readEnv(string $key): mixed
    {
        if ($this->envReader !== null) {
            return ($this->envReader)($key);
        }

        return Env::get($key);
    }
}
