<?php

use Illuminate\Redis\RedisManager;
use Ldiebold\Isolate\CollisionDetectors\RedisPrefixCollisionDetector;
use Ldiebold\Isolate\Isolate;
use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\Redis\FlushOutcome;

/*
 * This test hits a real Redis server and is skipped unless INTEGRATION_REDIS is
 * enabled. Configure the server with ISOLATE_REDIS_HOST / ISOLATE_REDIS_PORT /
 * ISOLATE_REDIS_DB (a throwaway database index is recommended). Requires the
 * phpredis extension.
 */

$redisDisabled = fn (): bool => ! filter_var(env('INTEGRATION_REDIS'), FILTER_VALIDATE_BOOL);

it('flushes only the targeted padded prefix against a real redis server', function () {
    $base = 'isolate_it_'.uniqid().'-';

    config()->set('database.redis', [
        'client' => 'phpredis',
        'options' => ['prefix' => $base],
        'default' => [
            'host' => env('ISOLATE_REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('ISOLATE_REDIS_PORT', 6379),
            'database' => (int) env('ISOLATE_REDIS_DB', 15),
        ],
    ]);

    // Seed keys through the prefixed connection, so they land as <base>07:* etc.
    $connection = (new RedisManager(app(), 'phpredis', config('database.redis')))->connection('default');
    $connection->set('07:a', '1');
    $connection->set('07:b', '1');
    $connection->set('70:c', '1');

    $manager = app(Isolate::class)->keyspaceFlusherManager();

    $result = $manager->flush($base.'07');

    expect($result->outcome)->toBe(FlushOutcome::Flushed)
        ->and($result->keyCount)->toBe(2)
        ->and((int) $connection->exists('07:a'))->toBe(0)
        ->and((int) $connection->exists('07:b'))->toBe(0)
        ->and((int) $connection->exists('70:c'))->toBe(1)
        ->and($manager->count($base.'07'))->toBe(0)
        ->and($manager->count($base.'70'))->toBe(1);

    $connection->del('70:c');
})->skip($redisDisabled, 'INTEGRATION_REDIS is disabled');

it('detects an occupied redis prefix against a real redis server', function () {
    $base = 'isolate_it_'.uniqid().'-';

    config()->set('database.redis', [
        'client' => 'phpredis',
        'options' => ['prefix' => $base],
        'default' => [
            'host' => env('ISOLATE_REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('ISOLATE_REDIS_PORT', 6379),
            'database' => (int) env('ISOLATE_REDIS_DB', 15),
        ],
    ]);

    $connection = (new RedisManager(app(), 'phpredis', config('database.redis')))->connection('default');
    $connection->set('07:a', '1');

    // No prober: this exercises the real prefix-stripped SCAN probe.
    $detector = new RedisPrefixCollisionDetector(app(), ['REDIS_PREFIX']);

    $occupied = iterator_to_array($detector->conflicts(new IsolationPlan(7, ['REDIS_PREFIX' => $base.'07'])));
    $free = iterator_to_array($detector->conflicts(new IsolationPlan(99, ['REDIS_PREFIX' => $base.'99'])));

    expect($occupied)->toHaveCount(1)
        ->and($occupied[0]->value)->toBe($base.'07')
        ->and($free)->toBe([]);

    $connection->del('07:a');
})->skip($redisDisabled, 'INTEGRATION_REDIS is disabled');
