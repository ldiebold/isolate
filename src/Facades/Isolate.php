<?php

namespace Ldiebold\Isolate\Facades;

use Illuminate\Support\Facades\Facade;
use Ldiebold\Isolate\Isolate as IsolateService;

/**
 * @method static IsolateService port(string|array<int, string> $env, int $base, array<string, mixed> $options = [])
 * @method static IsolateService name(string|array<int, string> $env, array<string, mixed> $options = [])
 * @method static IsolateService derive(string $env, \Closure|string $resolver)
 * @method static IsolateService after(\Closure|string $callback)
 * @method static IsolateService afterDatabaseCreated(\Closure|string $callback)
 * @method static IsolateService restartUsing(\Closure|string $callback)
 * @method static IsolateService applier(\Ldiebold\Isolate\Contracts\Applier|string $applier)
 * @method static IsolateService collisionDetector(\Ldiebold\Isolate\Contracts\CollisionDetector|string $detector)
 * @method static IsolateService resource(string $env, array<string, mixed> $definition)
 * @method static \Ldiebold\Isolate\IsolationResult run(\Ldiebold\Isolate\IsolationRequest $request)
 *
 * @see IsolateService
 */
class Isolate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return IsolateService::class;
    }
}
