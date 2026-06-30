<?php

use Illuminate\Config\Repository;
use Ldiebold\Isolate\Database\DatabaseDestroyerManager;
use Ldiebold\Isolate\Database\DropOutcome;
use Ldiebold\Isolate\Database\DropResult;
use Ldiebold\Isolate\Tests\Fakes\FakeDatabaseDestroyer;

it('routes to the destroyer that supports the driver', function () {
    $config = new Repository(['database' => ['connections' => ['sqlite' => ['driver' => 'sqlite']]]]);
    $destroyer = new FakeDatabaseDestroyer('sqlite', DropResult::dropped('forge_7'));

    $result = (new DatabaseDestroyerManager($config, [$destroyer]))->destroy('sqlite', 'forge_7');

    expect($result->outcome)->toBe(DropOutcome::Dropped)
        ->and($destroyer->dropped)->toContain('forge_7');
});

it('skips when no destroyer supports the driver', function () {
    $config = new Repository(['database' => ['connections' => ['mongo' => ['driver' => 'mongo']]]]);

    $result = (new DatabaseDestroyerManager($config, []))->destroy('mongo', 'forge_7');

    expect($result->outcome)->toBe(DropOutcome::Skipped)
        ->and($result->message)->toContain('mongo');
});
