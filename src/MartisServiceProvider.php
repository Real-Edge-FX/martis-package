<?php

namespace Martis;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Martis\Console\ActionMakeCommand;
use Martis\Console\ComponentMakeCommand;
use Martis\Console\FieldMakeCommand;
use Martis\Console\InstallCommand;
use Martis\Console\PolicyMakeCommand;
use Martis\Console\ResourceMakeCommand;
use Martis\Console\ThemeMakeCommand;
use Martis\Console\UserCommand;
use Martis\Console\VendorPublishCommand;
use Martis\Discovery\ResourceDiscovery;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resources\ActionEventResource;

class MartisServiceProvider extends ServiceProvider
{
    /** Register the ResourceRegistry singleton and merge package config. */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/martis.php',
            'martis'
        );

        $this->app->singleton(ResourceRegistry::class, function (): ResourceRegistry {
            return new ResourceRegistry;
        });
    }

    /** Boot package services: routes, views, translations, assets, and console commands. */
    public function boot(): void
    {
        $this->registerMiddlewareAlias();
        $this->discoverResources();

        $this->loadRoutesFrom(__DIR__.'/../routes/martis.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'martis');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'martis');

        $this->registerBuiltInResources();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                ResourceMakeCommand::class,
                FieldMakeCommand::class,
                UserCommand::class,
                ComponentMakeCommand::class,
                ThemeMakeCommand::class,
                VendorPublishCommand::class,
                PolicyMakeCommand::class,
                ActionMakeCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/martis.php' => config_path('martis.php'),
            ], 'martis-config');

            $this->publishes([
                __DIR__.'/../public' => public_path('vendor/martis'),
            ], 'martis-assets');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/martis'),
            ], 'martis-views');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/martis'),
            ], 'martis-lang');

            $this->publishes([
                __DIR__.'/../database/migrations/create_action_events_table.php.stub' => database_path('migrations/'.date('Y_m_d').'_000001_create_action_events_table.php'),
            ], 'martis-migrations');
        }
    }

    /**
     * Register package-provided resources (e.g. ActionEvent audit log).
     */
    protected function registerBuiltInResources(): void
    {
        if (config('martis.action_events.resource', true)) {
            $this->app->make(ResourceRegistry::class)->register(ActionEventResource::class);
        }
    }

    protected function registerMiddlewareAlias(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('martis.auth', MartisAuthenticate::class);
    }

    /**
     * Auto-discover and register Resource classes from the configured path.
     */
    protected function discoverResources(): void
    {
        /** @var string $resourcesPath */
        $resourcesPath = config('martis.resources_path', app_path('Martis'));

        $discovery = new ResourceDiscovery($resourcesPath);
        $classes = $discovery->discover();

        if ($classes !== []) {
            $this->app->make(ResourceRegistry::class)->registerMany($classes);
        }
    }
}
