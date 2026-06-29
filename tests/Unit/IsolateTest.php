<?php

use Ldiebold\Isolate\ApplyResult;
use Ldiebold\Isolate\Isolate;
use Ldiebold\Isolate\IsolationPlan;
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
