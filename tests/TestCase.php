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

    /**
     * Worktree hydration is off by default in tests so command runs stay
     * deterministic and never shell out to git; the tests that cover it opt in
     * explicitly (via config or a fake WorktreeLocator).
     *
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('isolate.worktree.copy', []);
    }
}
