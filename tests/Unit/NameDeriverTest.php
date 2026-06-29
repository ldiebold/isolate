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
