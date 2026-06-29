<?php

use Ldiebold\Isolate\Conflict;
use Ldiebold\Isolate\Exceptions\ConflictException;
use Ldiebold\Isolate\Exceptions\NoAvailableNumberException;
use Ldiebold\Isolate\Tests\Fakes\FakeCollisionDetector;

it('prefers a valid self claim', function () {
    $selection = selector(['self' => 3])->next();

    expect($selection->number)->toBe(3)
        ->and($selection->isSelf)->toBeTrue()
        ->and($selection->conflicts)->toBe([]);
});

it('reuses the self claim idempotently despite a database conflict', function () {
    $dbConflict = Conflict::database('DB_DATABASE', 'forge_3', 'exists');

    $selection = selector([
        'self' => 3,
        'detectors' => [new FakeCollisionDetector([3 => [$dbConflict]])],
    ])->next();

    expect($selection->number)->toBe(3)
        ->and($selection->conflicts)->toBe([]);
});

it('warns about a live self port conflict by default', function () {
    $portConflict = Conflict::port('SERVER_PORT', 8003, 'in use');

    $selection = selector([
        'self' => 3,
        'detectors' => [new FakeCollisionDetector([3 => [$portConflict]])],
    ])->next();

    expect($selection->number)->toBe(3)
        ->and($selection->conflicts)->toHaveCount(1);
});

it('throws on a live self port conflict in strict mode', function () {
    $portConflict = Conflict::port('SERVER_PORT', 8003, 'in use');

    selector([
        'self' => 3,
        'throw' => true,
        'detectors' => [new FakeCollisionDetector([3 => [$portConflict]])],
    ])->next();
})->throws(ConflictException::class);

it('skips conflicted candidates and returns the next free number', function () {
    $conflict = Conflict::port('SERVER_PORT', 8000, 'in use');

    $selection = selector([
        'detectors' => [new FakeCollisionDetector([0 => [$conflict]])],
    ])->next();

    expect($selection->number)->toBe(1)
        ->and($selection->isSelf)->toBeFalse();
});

it('skips numbers that map a browser port onto a restricted port', function () {
    $selection = selector(['restricted' => [8000]])->next();

    expect($selection->number)->toBe(1);
});

it('fails fast on a conflict when throw_on_conflict is enabled', function () {
    $conflict = Conflict::port('SERVER_PORT', 8000, 'in use');

    selector([
        'throw' => true,
        'detectors' => [new FakeCollisionDetector([0 => [$conflict]])],
    ])->next();
})->throws(ConflictException::class);

it('throws when no number is available', function () {
    $detectors = [new FakeCollisionDetector([
        0 => [Conflict::port('SERVER_PORT', 8000, 'in use')],
        1 => [Conflict::port('SERVER_PORT', 8001, 'in use')],
    ])];

    selector(['max' => 2, 'detectors' => $detectors])->next();
})->throws(NoAvailableNumberException::class);

it('ignores an out-of-range self claim and scans instead', function () {
    $selection = selector(['self' => 99, 'max' => 50])->next();

    expect($selection->number)->toBe(0)
        ->and($selection->isSelf)->toBeFalse();
});
