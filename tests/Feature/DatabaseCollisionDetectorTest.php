<?php

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\CollisionDetectors\DatabaseCollisionDetector;
use Ldiebold\Isolate\ConflictKind;
use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\SideEffect;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->base = sys_get_temp_dir().'/isolate_dbdetect_'.uniqid();
    $this->files->makeDirectory($this->base);

    $config = new Repository(['database' => ['connections' => ['sqlite' => ['driver' => 'sqlite']]]]);
    $this->detector = new DatabaseCollisionDetector($config, app('db'), $this->files, $this->base);

    $this->planFor = fn (string $database): IsolationPlan => new IsolationPlan(7, ['DB_DATABASE' => $database], [
        SideEffect::createDatabase(['connection' => 'sqlite', 'database' => $database, 'env' => 'DB_DATABASE']),
    ]);
});

afterEach(function () {
    $this->files->deleteDirectory($this->base);
});

it('reports a conflict for an existing sqlite database', function () {
    $this->files->put($this->base.'/forge_7.sqlite', '');

    $conflicts = iterator_to_array($this->detector->conflicts(($this->planFor)('forge_7.sqlite')));

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]->kind)->toBe(ConflictKind::Database)
        ->and($conflicts[0]->value)->toBe('forge_7.sqlite');
});

it('reports nothing for a missing sqlite database', function () {
    expect(iterator_to_array($this->detector->conflicts(($this->planFor)('forge_9.sqlite'))))->toBe([]);
});

it('ignores in-memory databases', function () {
    expect(iterator_to_array($this->detector->conflicts(($this->planFor)(':memory:'))))->toBe([]);
});
