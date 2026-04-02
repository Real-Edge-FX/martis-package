<?php

namespace Martis;

use Illuminate\Support\ServiceProvider;

class MartisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/martis.php',
            'martis'
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/martis.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'martis');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'martis');

        $this->publishes([
            __DIR__.'/../config/martis.php' => config_path('martis.php'),
        ], 'martis-config');

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/martis'),
        ], 'martis-assets');
    }
}
