<?php

namespace Ldiebold\Isolate\Tests;

use Illuminate\Foundation\Application;
use Ldiebold\Isolate\Facades\Isolate;
use Ldiebold\Isolate\IsolateServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            IsolateServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Isolate' => Isolate::class,
        ];
    }
}
