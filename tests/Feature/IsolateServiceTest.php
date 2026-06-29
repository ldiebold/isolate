<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Event;
use Ldiebold\Isolate\ApplyResult;
use Ldiebold\Isolate\Database\CreateResult;
use Ldiebold\Isolate\Events\DatabaseCreated;
use Ldiebold\Isolate\Events\IsolationApplied;
use Ldiebold\Isolate\Isolate;
use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\IsolationRequest;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->dir = sys_get_temp_dir().'/isolate_service_'.uniqid();
    $this->files->makeDirectory($this->dir);
    $this->envPath = $this->dir.'/.env';

    config()->set('app.url', 'http://localhost:8000');
    config()->set('isolate.env_path', $this->envPath);
    config()->set('isolate.env_example_path', $this->dir.'/.env.example');
    config()->set('isolate.lock_path', $this->dir.'/isolate.lock');
    config()->set('isolate.resources', [
        ['type' => 'port', 'env' => 'SERVER_PORT', 'base' => 8000, 'browser_facing' => true, 'active_when' => 'always'],
    ]);

    $this->files->put($this->envPath, "SERVER_PORT=8000\n");
});

afterEach(function () {
    $this->files->deleteDirectory($this->dir);
    unset($_SERVER['ISOLATE_NUMBER']);
});

it('reads the recorded instance number from the environment', function (string $raw, ?int $expected) {
    $_SERVER['ISOLATE_NUMBER'] = $raw;

    expect(app(Isolate::class)->currentNumber())->toBe($expected);
})->with([
    'a digit string' => ['3', 3],
    'zero' => ['0', 0],
    'a non-numeric value' => ['abc', null],
    'an empty value' => ['', null],
]);

it('returns null when no instance number is recorded', function () {
    expect(app(Isolate::class)->currentNumber())->toBeNull();
});

it('runs an explicit isolation programmatically', function () {
    $result = app(Isolate::class)->run(IsolationRequest::for(3));

    expect($result->number)->toBe(3)
        ->and($result->isReset())->toBeFalse()
        ->and($result->plan->get('SERVER_PORT'))->toBe('8003')
        ->and($this->files->get($this->envPath))->toContain('ISOLATE_NUMBER=3');
});

it('dispatches the IsolationApplied event when a plan is applied', function () {
    Event::fake([IsolationApplied::class]);

    app(Isolate::class)->fireAfterApply(new IsolationPlan(2), new ApplyResult);

    Event::assertDispatched(
        IsolationApplied::class,
        fn (IsolationApplied $event): bool => $event->plan->number === 2,
    );
});

it('dispatches the DatabaseCreated event when a database is created', function () {
    Event::fake([DatabaseCreated::class]);

    app(Isolate::class)->fireAfterDatabaseCreated(CreateResult::created('forge_2'), new IsolationPlan(2));

    Event::assertDispatched(
        DatabaseCreated::class,
        fn (DatabaseCreated $event): bool => $event->result->database === 'forge_2',
    );
});
