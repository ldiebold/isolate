<?php

use Illuminate\Support\Facades\Artisan;
use Ldiebold\Isolate\Facades\Isolate;
use Ldiebold\Isolate\Isolate as IsolateService;

it('registers the isolate commands', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)
        ->toContain('isolate')
        ->toContain('isolate:status')
        ->toContain('isolate:list')
        ->toContain('isolate:teardown');
});

it('merges the package config as data', function () {
    expect(config('isolate.band_size'))->toBe(100)
        ->and(config('isolate.max_instances'))->toBe(50)
        ->and(config('isolate.suffix_format'))->toBe('_{n}')
        ->and(config('isolate.resources'))->toBeArray();
});

it('binds the isolate service as a singleton behind the facade', function () {
    expect(app(IsolateService::class))->toBeInstanceOf(IsolateService::class)
        ->and(Isolate::getFacadeRoot())->toBe(app(IsolateService::class));
});
