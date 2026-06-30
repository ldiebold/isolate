<?php

use Illuminate\Support\Facades\Event;
use Ldiebold\Isolate\ApplyResult;
use Ldiebold\Isolate\Database\DropResult;
use Ldiebold\Isolate\Events\DatabaseDropped;
use Ldiebold\Isolate\Events\PrefixFlushed;
use Ldiebold\Isolate\Isolate;
use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\Redis\FlushResult;
use Ldiebold\Isolate\Tests\Fakes\FakeCollisionDetector;
use Ldiebold\Isolate\Tests\Fakes\RecordingApplier;
use Ldiebold\Isolate\Tests\Fakes\RecordingHook;

beforeEach(function () {
    $this->isolate = new Isolate(app());
});

it('resolves registered appliers from instances and class-strings', function () {
    $instance = new RecordingApplier;

    $this->isolate->applier($instance)->applier(RecordingApplier::class);

    $appliers = $this->isolate->registeredAppliers();

    expect($appliers)->toHaveCount(2)
        ->and($appliers[0])->toBe($instance)
        ->and($appliers[1])->toBeInstanceOf(RecordingApplier::class);
});

it('keeps registered collision detectors', function () {
    $detector = new FakeCollisionDetector;

    $this->isolate->collisionDetector($detector);

    expect($this->isolate->registeredCollisionDetectors())->toBe([$detector]);
});

it('stores runtime derived resolvers and resource overrides', function () {
    $this->isolate
        ->derive('CUSTOM', fn (array $env, int $n): string => 'x')
        ->resource('SERVER_PORT', ['base' => 9000]);

    expect($this->isolate->derivedResolvers())->toHaveKey('CUSTOM')
        ->and($this->isolate->resourceOverrides())->toHaveKey('SERVER_PORT');
});

it('registers port and name resources via the intent helpers', function () {
    $this->isolate
        ->port('VITE_PORT', 8200, ['browser_facing' => true])
        ->port(['REVERB_SERVER_PORT', 'REVERB_PORT'], 8100)
        ->name('REDIS_PREFIX');

    $overrides = $this->isolate->resourceOverrides();

    expect($overrides['VITE_PORT'])->toMatchArray(['type' => 'port', 'base' => 8200, 'browser_facing' => true])
        ->and($overrides['REVERB_SERVER_PORT'])->toMatchArray(['type' => 'port', 'env' => ['REVERB_SERVER_PORT', 'REVERB_PORT']])
        ->and($overrides['REDIS_PREFIX'])->toMatchArray(['type' => 'name', 'base_from' => 'isolate.name']);
});

it('fires closure and class-string hooks', function () {
    $hook = new RecordingHook;
    app()->instance(RecordingHook::class, $hook);
    $closureFired = false;

    $this->isolate
        ->after(function () use (&$closureFired): void {
            $closureFired = true;
        })
        ->after(RecordingHook::class);

    $this->isolate->fireAfterApply(new IsolationPlan(1), new ApplyResult);

    expect($closureFired)->toBeTrue()
        ->and($hook->count)->toBe(1);
});

it('fires afterDatabaseDropped hooks and dispatches the DatabaseDropped event', function () {
    Event::fake([DatabaseDropped::class]);
    $fired = false;

    $this->isolate->afterDatabaseDropped(function () use (&$fired): void {
        $fired = true;
    });

    $this->isolate->fireAfterDatabaseDropped(
        DropResult::dropped('forge_7'),
        new IsolationPlan(7, ['DB_DATABASE' => 'forge_7']),
    );

    expect($fired)->toBeTrue();
    Event::assertDispatched(DatabaseDropped::class);
});

it('fires afterPrefixFlushed hooks and dispatches the PrefixFlushed event', function () {
    Event::fake([PrefixFlushed::class]);
    $captured = null;

    $this->isolate->afterPrefixFlushed(function (FlushResult $result, IsolationPlan $plan) use (&$captured): void {
        $captured = [$result, $plan];
    });

    $this->isolate->fireAfterPrefixFlushed(
        FlushResult::flushed('fuellox-database-07', 5),
        new IsolationPlan(7, ['REDIS_PREFIX' => 'fuellox-database-07']),
    );

    expect($captured)->not->toBeNull()
        ->and($captured[0]->keyCount)->toBe(5)
        ->and($captured[1]->get('REDIS_PREFIX'))->toBe('fuellox-database-07');
    Event::assertDispatched(PrefixFlushed::class);
});

it('guards the vanilla redis base, not the active instance prefix, when flushing', function () {
    // Active instance 3: the live config prefix is the instance's own padded value,
    // exactly as .env leaves it. The flush guard must protect the n=0 base
    // (laravel-database-) and NOT the active prefix it is being asked to flush.
    config()->set('isolate.max_instances', 50);
    config()->set('database.redis.options.prefix', 'laravel-database-03');
    config()->set('isolate.resources', [
        ['type' => 'name', 'env' => 'REDIS_PREFIX', 'config' => 'database.redis.options.prefix', 'keyspace' => 'redis', 'active_when' => 'always'],
    ]);
    $_SERVER['ISOLATE_NUMBER'] = '3';

    $method = new ReflectionMethod(Isolate::class, 'protectedRedisPrefixes');
    $method->setAccessible(true);

    expect($method->invoke($this->isolate))->toBe(['laravel-database-']);

    unset($_SERVER['ISOLATE_NUMBER']);
});
