<?php

use Ldiebold\Isolate\CollisionDetectors\RedisPrefixCollisionDetector;
use Ldiebold\Isolate\ConflictKind;
use Ldiebold\Isolate\IsolationPlan;

it('reports a conflict when the prober finds keys', function () {
    $detector = new RedisPrefixCollisionDetector(app(), ['REDIS_PREFIX'], fn (string $prefix): bool => true);

    $conflicts = iterator_to_array($detector->conflicts(new IsolationPlan(7, ['REDIS_PREFIX' => 'fuellox_7'])));

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]->kind)->toBe(ConflictKind::RedisPrefix)
        ->and($conflicts[0]->value)->toBe('fuellox_7');
});

it('reports nothing when the prober finds no keys', function () {
    $detector = new RedisPrefixCollisionDetector(app(), ['REDIS_PREFIX'], fn (string $prefix): bool => false);

    expect(iterator_to_array($detector->conflicts(new IsolationPlan(7, ['REDIS_PREFIX' => 'fuellox_7']))))->toBe([]);
});

it('degrades gracefully when redis is unavailable', function () {
    $detector = new RedisPrefixCollisionDetector(app(), ['REDIS_PREFIX'], fn (string $prefix): ?bool => null);

    expect(iterator_to_array($detector->conflicts(new IsolationPlan(7, ['REDIS_PREFIX' => 'fuellox_7']))))->toBe([]);
});

it('skips empty or missing prefixes', function () {
    $detector = new RedisPrefixCollisionDetector(app(), ['REDIS_PREFIX', 'HORIZON_PREFIX'], fn (string $prefix): bool => true);

    expect(iterator_to_array($detector->conflicts(new IsolationPlan(7, ['REDIS_PREFIX' => '']))))->toBe([]);
});
