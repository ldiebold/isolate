<?php

use Illuminate\Config\Repository;
use Ldiebold\Isolate\Appliers\DatabaseCreatorApplier;
use Ldiebold\Isolate\Database\CreateResult;
use Ldiebold\Isolate\Database\DatabaseCreatorManager;
use Ldiebold\Isolate\Isolate;
use Ldiebold\Isolate\Tests\Fakes\FakeDatabaseCreator;

it('creates the database and fires the afterDatabaseCreated hook', function () {
    $config = new Repository(['database' => ['connections' => ['sqlite' => ['driver' => 'sqlite']]]]);
    $databases = new DatabaseCreatorManager($config, [
        new FakeDatabaseCreator('sqlite', CreateResult::created('forge_7')),
    ]);

    $manager = new Isolate(app());
    $fired = false;
    $manager->afterDatabaseCreated(function () use (&$fired): void {
        $fired = true;
    });

    $result = (new DatabaseCreatorApplier($databases, $manager))->apply(planWithDatabase());

    expect($result->changes)->toContain('Created database [forge_7]')
        ->and($fired)->toBeTrue();
});

it('degrades to a warning when creation is skipped, without firing the hook', function () {
    $config = new Repository(['database' => ['connections' => ['sqlite' => ['driver' => 'sqlite']]]]);
    $databases = new DatabaseCreatorManager($config, [
        new FakeDatabaseCreator('sqlite', CreateResult::skipped('forge_7', 'no CREATE grant')),
    ]);

    $manager = new Isolate(app());
    $fired = false;
    $manager->afterDatabaseCreated(function () use (&$fired): void {
        $fired = true;
    });

    $result = (new DatabaseCreatorApplier($databases, $manager))->apply(planWithDatabase());

    expect($result->hasWarnings())->toBeTrue()
        ->and($result->warnings)->toContain('no CREATE grant')
        ->and($fired)->toBeFalse();
});

it('warns when no creator supports the driver', function () {
    $config = new Repository(['database' => ['connections' => ['mongo' => ['driver' => 'mongo']]]]);
    $databases = new DatabaseCreatorManager($config, []);

    $result = (new DatabaseCreatorApplier($databases, new Isolate(app())))
        ->apply(planWithDatabase('forge_7', 'mongo'));

    expect($result->hasWarnings())->toBeTrue();
});
