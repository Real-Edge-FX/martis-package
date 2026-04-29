<?php

namespace Martis;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Martis\Cache\MartisCache;
use Martis\Console\ActionMakeCommand;
use Martis\Console\ActivityFeedMakeCommand;
use Martis\Console\CacheClearCommand;
use Martis\Console\CacheDisableCommand;
use Martis\Console\CacheEnableCommand;
use Martis\Console\CacheStatusCommand;
use Martis\Console\CardMakeCommand;
use Martis\Console\ComponentMakeCommand;
use Martis\Console\DashboardMakeCommand;
use Martis\Console\EndpointTableMakeCommand;
use Martis\Console\FieldMakeCommand;
use Martis\Console\FilterMakeCommand;
use Martis\Console\InstallCommand;
use Martis\Console\LensMakeCommand;
use Martis\Console\ListOverridesCommand;
use Martis\Console\PartitionMakeCommand;
use Martis\Console\PolicyMakeCommand;
use Martis\Console\ProgressMakeCommand;
use Martis\Console\ResourceMakeCommand;
use Martis\Console\SsoMakeCommand;
use Martis\Console\StubsCommand;
use Martis\Console\ThemeMakeCommand;
use Martis\Console\ToolMakeCommand;
use Martis\Console\TrendMakeCommand;
use Martis\Console\UserCommand;
use Martis\Console\ValueMakeCommand;
use Martis\Console\VendorPublishCommand;
use Martis\Discovery\ResourceDiscovery;
use Martis\Exceptions\Handler as MartisExceptionHandler;
use Martis\Facades\Martis;
use Martis\Http\Middleware\ApplyUserPreferencesLocale;
use Martis\Http\Middleware\EnsureTwoFactorChallenge;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Impersonation\ImpersonationManager;
use Martis\Profile\TwoFactorService;
use Martis\Resources\ActionEventResource;
use Martis\Sso\SsoManager;

class MartisServiceProvider extends ServiceProvider
{
    /** Register the ResourceRegistry singleton and merge package config. */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/martis.php',
            'martis'
        );

        // Suppress Scramble's default routes (`/docs/api`, `/docs/api.json`)
        // unconditionally. Martis ships its own OpenAPI surface under
        // `/{martis-path}/api-docs` (gated by `MARTIS_API_DOCS_ENABLED`,
        // off by default) and we never want Scramble's default routes to
        // appear behind a consumer's back. This must run in `register()`,
        // not `boot()`: Scramble's own service provider checks the flag
        // during its own `boot()`, which runs before ours, so calling
        // `ignoreDefaultRoutes()` from our `boot()` is too late.
        if (class_exists(\Dedoc\Scramble\Scramble::class)) {
            \Dedoc\Scramble\Scramble::ignoreDefaultRoutes();
        }

        $this->app->singleton(ResourceRegistry::class, function (): ResourceRegistry {
            return new ResourceRegistry;
        });

        $this->app->singleton(MartisManager::class);
        $this->app->singleton(TwoFactorService::class);

        // Cache facade is bound during boot, but Cache::store() resolves
        // through the manager which itself depends on config — safe to
        // call lazily inside the closure.
        $this->app->singleton(MartisCache::class, function (): MartisCache {
            return new MartisCache(Cache::store());
        });

        $this->app->singleton(SsoManager::class);

        $this->app->singleton(ImpersonationManager::class, function ($app) {
            return new ImpersonationManager(
                $app,
                $app->make(AuthManager::class),
            );
        });
    }

    /** Boot package services: routes, views, translations, assets, and console commands. */
    public function boot(): void
    {
        $this->registerMiddlewareAlias();
        $this->registerExceptionHandling();
        $this->registerCacheGate();
        $this->discoverResources();
        $this->registerApiDocs();

        $this->loadRoutesFrom(__DIR__.'/../routes/martis.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'martis');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'martis');

        $this->registerBuiltInResources();

        // Boot every registered Tool's lifecycle hook AFTER Martis
        // itself has loaded routes / views / config. Tools can hook
        // their own routes, listeners, view namespaces, and
        // publishables on top of the now-initialised package.
        // Defer to the post-register phase so tools registered in
        // consumer service providers (which typically run AFTER this
        // package's own register) are picked up before we boot them.
        $this->app->booted(function () {
            Martis::getFacadeRoot()?->bootTools();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                ResourceMakeCommand::class,
                FieldMakeCommand::class,
                UserCommand::class,
                CardMakeCommand::class,
                ComponentMakeCommand::class,
                ThemeMakeCommand::class,
                VendorPublishCommand::class,
                PolicyMakeCommand::class,
                ActionMakeCommand::class,
                FilterMakeCommand::class,
                ValueMakeCommand::class,
                TrendMakeCommand::class,
                PartitionMakeCommand::class,
                ProgressMakeCommand::class,
                ActivityFeedMakeCommand::class,
                EndpointTableMakeCommand::class,
                DashboardMakeCommand::class,
                LensMakeCommand::class,
                ToolMakeCommand::class,
                CacheStatusCommand::class,
                CacheClearCommand::class,
                CacheDisableCommand::class,
                CacheEnableCommand::class,
                ListOverridesCommand::class,
                SsoMakeCommand::class,
                StubsCommand::class,
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

            // Core action-events audit log table. The stub is idempotent:
            // it renames an existing `action_events` table to
            // `martis_action_events` when upgrading, otherwise creates it
            // fresh under the martis_ prefix.
            $this->publishes([
                __DIR__.'/../stubs/create_martis_action_events_table.php.stub' => database_path('migrations/'.date('Y_m_d').'_000001_create_martis_action_events_table.php'),
            ], 'martis-migrations');

            // Profile: 2FA columns migration stub
            $this->publishes([
                __DIR__.'/../stubs/add_two_factor_columns.php.stub' => database_path('migrations/'.date('Y_m_d').'_000002_add_two_factor_columns.php'),
            ], 'martis-2fa-migration');

            // Profile: profile picture column migration stub
            // (published dynamically by InstallCommand based on user-chosen column name)
            $this->publishes([
                __DIR__.'/../stubs/add_profile_picture_column.php.stub' => database_path('migrations/'.date('Y_m_d').'_000003_add_profile_picture_column.php'),
            ], 'martis-avatar-migration');

            // Task 07.1 ⭐ D2 — user preferences table for theme/accent/
            // density/locale/reduced-motion persistence + shareable presets.
            $this->publishes([
                __DIR__.'/../stubs/create_user_preferences_table.php.stub' => database_path('migrations/'.date('Y_m_d').'_000004_create_martis_user_preferences_table.php'),
            ], 'martis-preferences-migration');

            // Task 17 — host-app MartisServiceProvider stub. Holds main
            // menu / dashboards / cache layers / gate definitions —
            // anything that can't live in `config/martis.php` because
            // closures don't survive `config:cache`. The InstallCommand
            // publishes this automatically and wires it into
            // `bootstrap/providers.php`; the tag below lets advanced
            // users republish it on demand.
            $this->publishes([
                __DIR__.'/../stubs/MartisServiceProvider.php.stub' => app_path('Providers/MartisServiceProvider.php'),
            ], 'martis-provider');
        }
    }

    /**
     * Register the default `manage-martis-cache` gate.
     *
     * Default behaviour: any authenticated user is allowed. Host apps
     * should override this in their own service provider when they want
     * to restrict the cache admin page to a subset of users:
     *
     *     Gate::define('manage-martis-cache', fn ($user) => $user->is_admin);
     *
     * Calling `Gate::define()` from the host app replaces the closure
     * registered here, so order doesn't matter.
     */
    protected function registerCacheGate(): void
    {
        if (Gate::has('manage-martis-cache')) {
            return;
        }

        Gate::define('manage-martis-cache', fn ($user) => $user !== null);
    }

    /** Register the custom exception handler for Martis routes. */
    protected function registerExceptionHandling(): void
    {
        if ($this->app->bound(ExceptionHandler::class)) {
            MartisExceptionHandler::register(
                $this->app->make(ExceptionHandler::class)
            );
        }
    }

    /**
     * Register the OpenAPI / Swagger UI surface, gated by
     * `martis.api_docs.enabled`. The surface lives at
     * `/{martis-path}/api-docs` (UI) and `/{martis-path}/api-docs.json`
     * (raw OpenAPI). Off by default — flip
     * `MARTIS_API_DOCS_ENABLED=true` in `.env` to expose it.
     *
     * Implementation note. Scramble auto-registers `/docs/api` and
     * `/docs/api.json` if its default routes are not suppressed.
     * `Scramble::ignoreDefaultRoutes()` is called unconditionally in
     * `register()` (Scramble checks the flag during its own `boot()`,
     * which runs before ours, so calling it here would be too late).
     * Here we only narrow the route resolver and register the Martis-
     * prefixed surface when the consumer's toggle is on.
     */
    protected function registerApiDocs(): void
    {
        if (! (bool) config('martis.api_docs.enabled', false)) {
            return;
        }

        if (! class_exists(\Dedoc\Scramble\Scramble::class)) {
            return;
        }

        $martisPath = trim((string) config('martis.path', 'martis'), '/');
        $apiDocsPath = trim((string) config('martis.api_docs.path', 'api-docs'), '/');
        $middleware = (array) config('martis.api_docs.middleware', ['web', 'auth']);

        \Dedoc\Scramble\Scramble::routes(function ($route) use ($martisPath) {
            $uri = ltrim((string) $route->uri(), '/');

            return str_starts_with($uri, $martisPath.'/api');
        });

        \Dedoc\Scramble\Scramble::registerUiRoute("{$martisPath}/{$apiDocsPath}")
            ->middleware($middleware);

        \Dedoc\Scramble\Scramble::registerJsonSpecificationRoute("{$martisPath}/{$apiDocsPath}.json")
            ->middleware($middleware);
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

    /** Register the Martis middleware alias with the router. */
    protected function registerMiddlewareAlias(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('martis.auth', MartisAuthenticate::class);
        $router->aliasMiddleware('martis.2fa', EnsureTwoFactorChallenge::class);
        $router->aliasMiddleware('martis.locale', ApplyUserPreferencesLocale::class);
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
