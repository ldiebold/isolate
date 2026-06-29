<?php

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Conflict;
use Ldiebold\Isolate\Isolate;
use Ldiebold\Isolate\Tests\Fakes\FakeCollisionDetector;
use Ldiebold\Isolate\Tests\Fakes\RecordingApplier;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->dir = sys_get_temp_dir().'/isolate_ext_'.uniqid();
    $this->files->makeDirectory($this->dir);
    $this->envPath = $this->dir.'/.env';

    config()->set('app.url', 'http://localhost:8000');
    config()->set('isolate.max_instances', 50);
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
});

it('runs registered appliers, hooks, detectors and derived resolvers in a full command run', function () {
    $applier = new RecordingApplier;
    $afterApplyFired = false;

    app(Isolate::class)
        ->applier($applier)
        ->after(function () use (&$afterApplyFired): void {
            $afterApplyFired = true;
        })
        ->derive('CUSTOM', fn (array $env, int $n): string => 'custom-'.$n)
        ->collisionDetector(new FakeCollisionDetector([
            2 => [Conflict::port('SERVER_PORT', 8002, 'fake extension conflict')],
        ]));

    $this->artisan('isolate', ['--number' => '2'])
        ->expectsOutputToContain('fake extension conflict')
        ->assertSuccessful();

    expect($applier->appliedNumber)->toBe(2)
        ->and($afterApplyFired)->toBeTrue()
        ->and($this->files->get($this->envPath))->toContain('CUSTOM=custom-2');
});

it('fires registered restart hooks with --restart', function () {
    $restarted = false;

    app(Isolate::class)->restartUsing(function () use (&$restarted): void {
        $restarted = true;
    });

    $this->artisan('isolate', ['--number' => '1', '--restart' => true])->assertSuccessful();

    expect($restarted)->toBeTrue();
});
