<?php

use Ldiebold\Isolate\Exceptions\InvalidConfigurationException;
use Ldiebold\Isolate\Support\BandValidator;

it('passes for well-spaced bases within the cap', function () {
    $validator = new BandValidator(bandSize: 100, maxInstances: 50);

    $validator->validate([8000, 8100, 8200]);
})->throwsNoExceptions();

it('allows max_instances equal to band_size', function () {
    $validator = new BandValidator(bandSize: 100, maxInstances: 100);

    $validator->validate([8000, 8100]);
})->throwsNoExceptions();

it('validates an empty base list (name-only resource sets)', function () {
    $validator = new BandValidator(bandSize: 100, maxInstances: 50);

    $validator->validate([]);
})->throwsNoExceptions();

it('rejects an invalid band layout', function (int $bandSize, int $maxInstances, array $bases, string $message) {
    expect(fn () => (new BandValidator($bandSize, $maxInstances))->validate($bases))
        ->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'max_instances greater than band_size' => [100, 101, [8000], 'must be <= isolate.band_size'],
    'bases closer than band_size apart (the vanilla 8000/8080 caveat)' => [100, 50, [8000, 8080], 'apart'],
    'duplicate bases' => [100, 50, [8000, 8000], 'Duplicate port base'],
    'privileged base below 1024' => [100, 50, [80], 'unprivileged'],
    'max_instances below 1' => [100, 0, [8000], 'isolate.max_instances must be at least 1'],
]);

it('flags duplicate resolved ports in the final set', function () {
    $validator = new BandValidator(bandSize: 100, maxInstances: 50);

    $validator->assertUniquePorts(['SERVER_PORT' => 8007, 'REVERB_PORT' => 8007]);
})->throws(InvalidConfigurationException::class, 'assigned to both');

it('accepts a unique resolved port set', function () {
    $validator = new BandValidator(bandSize: 100, maxInstances: 50);

    $validator->assertUniquePorts(['SERVER_PORT' => 8007, 'REVERB_PORT' => 8107]);
})->throwsNoExceptions();
