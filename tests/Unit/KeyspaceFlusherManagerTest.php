<?php

use Illuminate\Config\Repository;
use Ldiebold\Isolate\Redis\FlushOutcome;
use Ldiebold\Isolate\Redis\FlushResult;
use Ldiebold\Isolate\Redis\KeyspaceFlusherManager;
use Ldiebold\Isolate\Tests\Fakes\FakeKeyspaceFlusher;

/**
 * @param  array<string, array<string, mixed>>  $connections
 */
function redisConfig(array $connections): Repository
{
    return new Repository(['database' => ['redis' => array_merge([
        'client' => 'phpredis',
        'options' => ['prefix' => 'fuellox-database-'],
    ], $connections)]]);
}

it('flushes each distinct connection once and sums the keys removed', function () {
    $config = redisConfig([
        'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
        'cache' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
        'mirror' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0], // same db as default
    ]);

    $flusher = new FakeKeyspaceFlusher(results: [
        'default' => FlushResult::flushed('fuellox-database-07', 3),
        'cache' => FlushResult::flushed('fuellox-database-07', 2),
    ]);

    $result = (new KeyspaceFlusherManager($config, $flusher))->flush('fuellox-database-07');

    expect($result->wasFlushed())->toBeTrue()
        ->and($result->keyCount)->toBe(5)
        ->and($flusher->flushed)->toBe([
            ['default', 'fuellox-database-07'],
            ['cache', 'fuellox-database-07'],
        ]);
});

it('aggregates to empty when no connection had matching keys', function () {
    $config = redisConfig([
        'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
        'cache' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
    ]);

    $result = (new KeyspaceFlusherManager($config, new FakeKeyspaceFlusher))->flush('fuellox-database-07');

    expect($result->outcome)->toBe(FlushOutcome::Empty)
        ->and($result->keyCount)->toBe(0);
});

it('aggregates to skipped, collecting warnings, when every connection is unreachable', function () {
    $config = redisConfig([
        'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
    ]);

    $flusher = new FakeKeyspaceFlusher(results: [
        'default' => FlushResult::skipped('fuellox-database-07', 'Redis connection [default] is unavailable; skipped.'),
    ]);

    $result = (new KeyspaceFlusherManager($config, $flusher))->flush('fuellox-database-07');

    expect($result->outcome)->toBe(FlushOutcome::Skipped)
        ->and($result->message)->toContain('unavailable');
});

it('still reports flushed when one connection succeeds and another is skipped', function () {
    $config = redisConfig([
        'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
        'cache' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
    ]);

    $flusher = new FakeKeyspaceFlusher(results: [
        'default' => FlushResult::flushed('fuellox-database-07', 4),
        'cache' => FlushResult::skipped('fuellox-database-07', 'cache down'),
    ]);

    $result = (new KeyspaceFlusherManager($config, $flusher))->flush('fuellox-database-07');

    expect($result->wasFlushed())->toBeTrue()
        ->and($result->keyCount)->toBe(4)
        ->and($result->message)->toContain('cache down');
});

it('aggregates counts across connections and ignores unavailable ones', function () {
    $config = redisConfig([
        'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
        'cache' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
    ]);

    $flusher = new FakeKeyspaceFlusher(counts: ['default' => 5]); // cache => null

    expect((new KeyspaceFlusherManager($config, $flusher))->count('fuellox-database-07'))->toBe(5);
});

it('returns a null count when no connection can be counted', function () {
    $config = redisConfig([
        'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
    ]);

    expect((new KeyspaceFlusherManager($config, new FakeKeyspaceFlusher))->count('fuellox-database-07'))->toBeNull();
});
