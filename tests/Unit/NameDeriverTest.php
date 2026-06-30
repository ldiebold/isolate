<?php

use Ldiebold\Isolate\Support\NameDeriver;

it('uses the explicit isolate name when provided', function () {
    $deriver = new NameDeriver(name: 'fuellox', appName: 'Some Other App');

    expect($deriver->name())->toBe('fuellox');
});

it('falls back to a slug of the app name', function () {
    $deriver = new NameDeriver(name: null, appName: 'My Cool App');

    expect($deriver->name())->toBe('my-cool-app');
});

it('falls back to "app" when no name can be derived', function () {
    $deriver = new NameDeriver(name: null, appName: null);

    expect($deriver->name())->toBe('app');
});

it('omits the suffix entirely at n = 0', function () {
    $deriver = new NameDeriver(name: 'fuellox', appName: null);

    expect($deriver->suffix(0))->toBe('')
        ->and($deriver->derive('fuellox', 0))->toBe('fuellox');
});

it('appends the formatted suffix for n > 0', function () {
    $deriver = new NameDeriver(name: 'fuellox', appName: null);

    expect($deriver->suffix(7))->toBe('_7')
        ->and($deriver->derive('fuellox', 7))->toBe('fuellox_7');
});

it('honours a custom suffix format', function () {
    $deriver = new NameDeriver(name: 'fuellox', appName: null, suffixFormat: '-{n}');

    expect($deriver->derive('fuellox', 3))->toBe('fuellox-3');
});

it('derives from an arbitrary base such as a database name', function () {
    $deriver = new NameDeriver(name: 'fuellox', appName: null);

    expect($deriver->derive('forge', 12))->toBe('forge_12');
});

it('strips the current instance suffix so re-runs are idempotent', function () {
    $deriver = new NameDeriver(name: 'fuellox', appName: null);

    expect($deriver->stripSuffix('forge_7', 7))->toBe('forge')
        ->and($deriver->derive($deriver->stripSuffix('forge_7', 7), 7))->toBe('forge_7');
});

it('does not strip a suffix that does not match the current instance', function () {
    $deriver = new NameDeriver(name: 'fuellox', appName: null);

    expect($deriver->stripSuffix('forge_7', 3))->toBe('forge_7')
        ->and($deriver->stripSuffix('forge_7', null))->toBe('forge_7')
        ->and($deriver->stripSuffix('forge', 0))->toBe('forge');
});

it('collapses the boundary separator when the base already ends in one', function () {
    $deriver = new NameDeriver(name: null, appName: null);

    expect($deriver->derive('laravel-database-', 1))->toBe('laravel-database-1')
        ->and($deriver->derive('laravel_horizon:', 3))->toBe('laravel_horizon:3');
});

it('does not collapse when the base has no trailing separator', function () {
    $deriver = new NameDeriver(name: null, appName: null);

    expect($deriver->derive('laravel', 1))->toBe('laravel_1');
});

it('round-trips a separator-terminated base through derive then strip', function () {
    $deriver = new NameDeriver(name: null, appName: null);

    $derived = $deriver->derive('laravel-database-', 5);

    expect($derived)->toBe('laravel-database-5')
        ->and($deriver->stripSuffix($derived, 5))->toBe('laravel-database-')
        ->and($deriver->derive($deriver->stripSuffix($derived, 5), 5))->toBe('laravel-database-5');
});

it('recognises the legacy doubled-separator form when stripping', function () {
    $deriver = new NameDeriver(name: null, appName: null);

    expect($deriver->stripSuffix('laravel-database-_5', 5))->toBe('laravel-database-');
});

it('zero-pads the suffix to a fixed width', function () {
    $deriver = new NameDeriver(name: 'fuellox', appName: null);

    expect($deriver->suffix(7, 2))->toBe('_07')
        ->and($deriver->suffix(7, 3))->toBe('_007')
        ->and($deriver->suffix(7, 0))->toBe('_7')
        ->and($deriver->suffix(49, 2))->toBe('_49');
});

it('derives a fixed-width padded suffix, collapsing the boundary separator', function () {
    $deriver = new NameDeriver(name: null, appName: null);

    expect($deriver->derive('laravel-database-', 7, 2))->toBe('laravel-database-07')
        ->and($deriver->derive('forge', 7, 2))->toBe('forge_07');
});

it('makes padded instance prefixes mutually non-overlapping', function () {
    $deriver = new NameDeriver(name: null, appName: null);

    $seven = $deriver->derive('laravel-database-', 7, 2);
    $seventy = $deriver->derive('laravel-database-', 70, 2);

    expect($seven)->toBe('laravel-database-07')
        ->and($seventy)->toBe('laravel-database-70')
        ->and(str_starts_with($seventy, $seven))->toBeFalse();
});

it('round-trips a padded suffix through derive then strip', function () {
    $deriver = new NameDeriver(name: null, appName: null);

    $derived = $deriver->derive('laravel-database-', 5, 2);

    expect($derived)->toBe('laravel-database-05')
        ->and($deriver->stripSuffix($derived, 5, 2))->toBe('laravel-database-')
        ->and($deriver->derive($deriver->stripSuffix($derived, 5, 2), 5, 2))->toBe('laravel-database-05');
});
