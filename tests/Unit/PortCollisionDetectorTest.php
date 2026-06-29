<?php

use Ldiebold\Isolate\CollisionDetectors\PortCollisionDetector;
use Ldiebold\Isolate\ConflictKind;
use Ldiebold\Isolate\IsolationPlan;
use Ldiebold\Isolate\Tests\Fakes\FakePortChecker;

it('yields a conflict for each bound port', function () {
    $plan = new IsolationPlan(3, ['SERVER_PORT' => '8003'], [], ['SERVER_PORT' => 8003, 'REVERB_PORT' => 8103]);
    $detector = new PortCollisionDetector(new FakePortChecker([8003]));

    $conflicts = iterator_to_array($detector->conflicts($plan));

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]->kind)->toBe(ConflictKind::Port)
        ->and($conflicts[0]->value)->toBe(8003)
        ->and($conflicts[0]->resource)->toBe('SERVER_PORT');
});

it('yields nothing when every port is free', function () {
    $plan = new IsolationPlan(3, [], [], ['SERVER_PORT' => 8003]);
    $detector = new PortCollisionDetector(new FakePortChecker([]));

    expect(iterator_to_array($detector->conflicts($plan)))->toBe([]);
});
