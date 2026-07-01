<?php

namespace Ldiebold\Isolate;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Ldiebold\Isolate\Console\IsolateCommand;
use Ldiebold\Isolate\Console\ListCommand;
use Ldiebold\Isolate\Console\StatusCommand;
use Ldiebold\Isolate\Console\TeardownCommand;
use Ldiebold\Isolate\Contracts\DirectoryCopier;
use Ldiebold\Isolate\Contracts\EnvWriter;
use Ldiebold\Isolate\Contracts\PackageDetector;
use Ldiebold\Isolate\Contracts\PortChecker;
use Ldiebold\Isolate\Env\LineDotenvWriter;
use Ldiebold\Isolate\PortCheckers\BindPortChecker;
use Ldiebold\Isolate\Support\ComposerPackageDetector;
use Ldiebold\Isolate\Support\PhpDirectoryCopier;
use Ldiebold\Isolate\Support\SystemDirectoryCopier;
use Ldiebold\Isolate\Support\WorktreeLocator;

class IsolateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/isolate.php', 'isolate');

        $this->app->singleton(Isolate::class, static fn ($app): Isolate => new Isolate($app));

        $this->registerBindings();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/isolate.php' => $this->app->configPath('isolate.php'),
            ], 'isolate-config');

            $this->commands([
                IsolateCommand::class,
                StatusCommand::class,
                ListCommand::class,
                TeardownCommand::class,
            ]);
        }
    }

    /**
     * Bind the default contract implementations. Each is bound to its interface
     * so an application can swap any seam without touching the package. Default
     * appliers and collision detectors are composed in the Isolate service.
     */
    protected function registerBindings(): void
    {
        $this->app->bind(PackageDetector::class, ComposerPackageDetector::class);
        $this->app->bind(EnvWriter::class, LineDotenvWriter::class);
        $this->app->bind(PortChecker::class, BindPortChecker::class);

        $this->app->bind(DirectoryCopier::class, static function ($app): SystemDirectoryCopier {
            $files = $app->make(Filesystem::class);

            return new SystemDirectoryCopier($files, new PhpDirectoryCopier($files));
        });

        $this->app->bind(WorktreeLocator::class, static fn ($app): WorktreeLocator => new WorktreeLocator($app->basePath()));
    }
}
