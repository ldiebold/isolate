<?php

namespace Ldiebold\Isolate\CollisionDetectors;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Ldiebold\Isolate\Conflict;
use Ldiebold\Isolate\Contracts\CollisionDetector;
use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\Redis\RawRedisConnectionFactory;
use Throwable;

/**
 * Reports a conflict when keys already exist for a candidate Redis prefix,
 * degrading silently when Redis is unavailable.
 *
 * The probe runs a cursor SCAN against a prefix-stripped connection, operating on
 * absolute keys: phpredis prepends the client prefix to a SCAN match and only
 * accepts the match through its wrapper (not command('scan', ...)), so probing
 * the app's normal connection would both double-prefix the pattern and throw.
 * The probe is injectable for testing.
 */
class RedisPrefixCollisionDetector implements CollisionDetector
{
    /**
     * @param  array<int, string>  $prefixKeys
     * @param  (Closure(string): (bool|null))|null  $prober
     */
    public function __construct(
        protected Application $app,
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
        try {
            $connection = (new RawRedisConnectionFactory($this->app))->for('default');

            if ($connection === null) {
                return null;
            }

            $match = $prefix.'*';
            $cursor = 0;

            do {
                [$cursor, $keys] = $connection->scan($cursor, $match, 100);

                if ($keys !== []) {
                    return true;
                }
            } while ((string) $cursor !== '0');

            return false;
        } catch (Throwable) {
            return null;
        }
    }
}
