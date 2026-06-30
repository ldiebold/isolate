<?php

namespace Ldiebold\Isolate\Redis;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;
use Ldiebold\Isolate\Contracts\RawConnection;
use Throwable;

/**
 * Builds a prefix-stripped ("raw") Redis connection for a configured connection
 * name, so the flusher operates on ABSOLUTE keys and phpredis/predis never
 * double-prefixes UNLINK/DEL.
 *
 * A fresh RedisManager is constructed from the current config with
 * options.prefix forced empty: the application's shared `redis` manager caches
 * its config at construction, so the prefix cannot be mutated in place. The
 * resolver is injectable so tests can supply an in-memory connection.
 */
class RawRedisConnectionFactory
{
    /**
     * @param  (Closure(string): ?RawConnection)|null  $resolver
     */
    public function __construct(
        protected Application $app,
        protected ?Closure $resolver = null,
    ) {}

    public function for(string $connectionName): ?RawConnection
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($connectionName);
        }

        return $this->build($connectionName);
    }

    protected function build(string $connectionName): ?RawConnection
    {
        try {
            $config = $this->app->make('config');

            if (! $config instanceof Repository) {
                return null;
            }

            /** @var array<string, mixed> $redis */
            $redis = (array) $config->get('database.redis', []);

            // Only first-class connections can be flushed here; clusters degrade.
            if (! isset($redis[$connectionName]) || ! is_array($redis[$connectionName])) {
                return null;
            }

            $client = Arr::pull($redis, 'client', 'phpredis');

            $options = (array) ($redis['options'] ?? []);
            $options['prefix'] = '';
            $redis['options'] = $options;

            $manager = new RedisManager(
                $this->app,
                is_string($client) ? $client : 'phpredis',
                $redis,
            );

            return new LaravelRawConnection($manager->connection($connectionName));
        } catch (Throwable) {
            return null;
        }
    }
}
