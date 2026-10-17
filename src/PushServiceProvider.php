<?php

declare(strict_types=1);

namespace Filagora\Push;

use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

class PushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filagora-push.php', 'filagora-push');
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('push', 'Filagora Push', 'filagora/filagora-push', requires: ['core']),
        );

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filagora-push.php' => config_path('filagora-push.php'),
            ], 'filagora-push-config');
        }
    }
}
