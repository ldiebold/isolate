<?php

use Illuminate\Filesystem\Filesystem;
use Ldiebold\Isolate\Locking\FileLock;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->dir = sys_get_temp_dir().'/isolate_lock_'.uniqid();
    $this->files->makeDirectory($this->dir);
    $this->path = $this->dir.'/isolate.lock';
});

afterEach(function () {
    $this->files->deleteDirectory($this->dir);
});

it('runs the critical section under the lock and returns its value', function () {
    $lock = new FileLock($this->path, $this->files);

    $result = $lock->get(fn (): string => 'done');

    expect($result)->toBe('done')
        ->and($this->files->exists($this->path))->toBeTrue();
});

it('holds an exclusive lock during the critical section', function () {
    $lock = new FileLock($this->path, $this->files);

    $lockedDuring = null;

    $lock->get(function () use (&$lockedDuring) {
        $other = fopen($this->path, 'c');
        $lockedDuring = flock($other, LOCK_EX | LOCK_NB);
        fclose($other);

        return null;
    });

    expect($lockedDuring)->toBeFalse();
});

it('serializes sequential runs, releasing the lock between them', function () {
    $lock = new FileLock($this->path, $this->files);

    $lock->get(fn () => null);

    $reacquired = null;
    $lock->get(function () use (&$reacquired) {
        $reacquired = true;

        return null;
    });

    expect($reacquired)->toBeTrue();
});

it('degrades with a warning and still runs when the lock cannot be opened', function () {
    $blocker = $this->dir.'/blocker';
    $this->files->put($blocker, '');

    $warnings = [];
    $lock = new FileLock($blocker.'/isolate.lock', $this->files, function (string $message) use (&$warnings): void {
        $warnings[] = $message;
    });

    $ran = false;
    $lock->get(function () use (&$ran) {
        $ran = true;

        return null;
    });

    expect($ran)->toBeTrue()
        ->and($warnings)->not->toBeEmpty();
});
