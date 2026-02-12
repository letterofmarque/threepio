<?php

declare(strict_types=1);

namespace Marque\Threepio;

use Illuminate\Support\ServiceProvider;
use Marque\Threepio\Services\PeerService;

class ThreepioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/threepio.php', 'threepio');

        $this->app->singleton(PeerService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/threepio.php' => config_path('threepio.php'),
            ], 'threepio-config');
        }
    }
}
