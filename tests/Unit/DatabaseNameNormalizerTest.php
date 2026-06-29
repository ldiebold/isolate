<?php

use Ldiebold\Isolate\Support\DatabaseNameNormalizer;

beforeEach(function () {
    $this->normalizer = new DatabaseNameNormalizer;
});

it('normalizes to a safe, deterministic identifier', function (string $input, string $expected) {
    expect($this->normalizer->normalize($input))->toBe($expected);
})->with([
    'lowercases and underscores spaces' => ['My App DB', 'my_app_db'],
    'underscores a hyphen' => ['Fuellox-7', 'fuellox_7'],
    'collapses repeated symbols' => ['a!!!b', 'a_b'],
    'leaves a safe identifier untouched' => ['fuellox_7', 'fuellox_7'],
    'collapses and trims separators' => ['__a..b__', 'a_b'],
    'prefixes db_ for a numeric-leading name' => ['7up', 'db_7up'],
    'prefixes db_ for an all-numeric name' => ['123', 'db_123'],
    'falls back to db for an empty result' => ['', 'db'],
    'falls back to db for an all-symbol name' => ['---', 'db'],
]);

it('always starts with a letter', function (string $name) {
    expect(ctype_alpha($this->normalizer->normalize($name)[0]))->toBeTrue();
})->with(['7x', '___', '99bottles', 'A B C']);

it('caps long names at 63 bytes with a deterministic hash suffix', function () {
    $long = str_repeat('a', 100);

    $result = $this->normalizer->normalize($long);

    expect(strlen($result))->toBeLessThanOrEqual(63)
        ->and($result)->toBe($this->normalizer->normalize($long));
});

it('keeps distinct long names distinct after truncation', function () {
    $a = str_repeat('a', 70).'_one';
    $b = str_repeat('a', 70).'_two';

    $normalizedA = $this->normalizer->normalize($a);
    $normalizedB = $this->normalizer->normalize($b);

    expect($normalizedA)->not->toBe($normalizedB)
        ->and(strlen($normalizedA))->toBeLessThanOrEqual(63)
        ->and(strlen($normalizedB))->toBeLessThanOrEqual(63);
});
