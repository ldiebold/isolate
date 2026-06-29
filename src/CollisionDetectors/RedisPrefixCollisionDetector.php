<?php

namespace Ldiebold\Isolate\CollisionDetectors;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Ldiebold\Isolate\Conflict;
use Ldiebold\Isolate\Contracts\CollisionDetector;
use Ldiebold\Isolate\IsolationPlan;
use Throwable;

/**
 * Reports a conflict when keys already exist for a candidate Redis prefix,
 * degrading silently when Redis is unavailable. The probe is injectable.
 */
class RedisPrefixCollisionDetector implements CollisionDetector
{
    /**
     * @param  array<int, string>  $prefixKeys
     * @param  (Closure(string): (bool|null))|null  $prober
     */
    public function __construct(
        protected Container $container,
        protected array $prefixKeys = ['REDIS_PREFIX', 'HORIZON_PREFIX'],
        protected ?Closure $prober = null,
    ) {}

    public function conflicts(IsolationPlan $plan): iterable
    {
        foreach ($this->prefixKeys as $key) {
            $prefix = $plan->envMap[$key] ?? null;

            if (! is_string($prefix) || $prefix === '') {
                continue;
            }

            if ($this->probe($prefix) === true) {
                yield Conflict::redisPrefix($key, $prefix, "Redis keys for prefix [{$prefix}] already exist.");
            }
        }
    }

    protected function probe(string $prefix): ?bool
    {
        if ($this->prober !== null) {
            return ($this->prober)($prefix);
        }

        return $this->realProbe($prefix);
    }

    protected function realProbe(string $prefix): ?bool
    {
        if (! $this->container->bound('redis')) {
            return null;
        }

        try {
            $factory = $this->container->make('redis');

            if (! $factory instanceof RedisFactory) {
                return null;
            }

            $result = $factory->connection()->command('scan', [0, ['match' => $prefix.'*', 'count' => 100]]);

            if (is_array($result) && isset($result[1]) && is_array($result[1])) {
                return $result[1] !== [];
            }

            return is_array($result) ? $result !== [] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
