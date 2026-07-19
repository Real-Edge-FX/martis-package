<?php

namespace Martis;

use Dedoc\Scramble\Scramble;
use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Martis\Auth\DefaultRegistersUsers;
use Martis\Auth\DefaultResetsUserPasswords;
use Martis\Auth\DefaultSendsEmailVerification;
use Martis\Auth\DefaultSendsPasswordResetLinks;
use Martis\Auth\Listeners\RecordAuthorizationDenial;
use Martis\Auth\Listeners\RecordImpersonation;
use Martis\Auth\Listeners\RecordRoleChange;
use Martis\Authorization\RequestScopedAbilityCache;
use Martis\Cache\MartisCache;
use Martis\Concerns\HasPolicy;
use Martis\Console\ActionMakeCommand;
use Martis\Console\ActivityFeedMakeCommand;
use Martis\Console\AgentsCommand;
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
use Martis\Console\ListEnvVarsCommand;
use Martis\Console\ListOverridesCommand;
use Martis\Console\McpServeCommand;
use Martis\Console\PartitionMakeCommand;
use Martis\Console\PolicyMakeCommand;
use Martis\Console\ProgressMakeCommand;
use Martis\Console\PublishAssetsCommand;
use Martis\Console\ResourceMakeCommand;
use Martis\Console\RolesScaffoldCommand;
use Martis\Console\SsoMakeCommand;
use Martis\Console\StubsCommand;
use Martis\Console\ThemeDiffCommand;
use Martis\Console\ThemeMakeCommand;
use Martis\Console\ToolMakeCommand;
use Martis\Console\TrendMakeCommand;
use Martis\Console\UserCommand;
use Martis\Console\ValueMakeCommand;
use Martis\Console\VendorPublishCommand;
use Martis\Contracts\RegistersUsers;
use Martis\Contracts\ResetsUserPasswords;
use Martis\Contracts\SendsEmailVerification;
use Martis\Contracts\SendsPasswordResetLinks;
use Martis\Discovery\ResourceDiscovery;
use Martis\Discovery\ToolDiscovery;
use Martis\Exceptions\Handler as MartisExceptionHandler;
use Martis\Facades\Martis;
use Martis\Http\Middleware\ApplyUserPreferencesLocale;
use Martis\Http\Middleware\EnforceImpersonationDuration;
use Martis\Http\Middleware\EnsureEmailIsVerified;
use Martis\Http\Middleware\EnsureTwoFactorChallenge;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Impersonation\Events\ImpersonationStarted;
use Martis\Impersonation\Events\ImpersonationStopped;
use Martis\Impersonation\ImpersonationManager;
use Martis\Invitations\Events\InvitationAccepted;
use Martis\Invitations\Events\InvitationCreated;
use Martis\Invitations\Events\InvitationRevoked;
use Martis\Invitations\InvitationManager;
use Martis\Invitations\Listeners\RecordInvitation;
use Martis\Profile\TwoFactorService;
use Martis\Resources\ActionEventResource;
use Martis\Sso\SsoManager;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

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
        if (class_exists(Scramble::class)) {
            Scramble::ignoreDefaultRoutes();
        }

        $this->app->singleton(ResourceRegistry::class, function (): ResourceRegistry {
            return new ResourceRegistry;
        });

        $this->app->singleton(MartisManager::class);
        $this->app->singleton(TwoFactorService::class);

        // Cache facade is bound during boot, but Cache::store() resolves
        // through the manager which itself depends on config — safe to
        // call lazily inside the closure. v1.8.8: bound as scoped()
        // (per-request) so the in-instance state cache is reset between
        // requests — operational metadata read from `martis_cache_state`
        // stays at most one request stale.
        $this->app->scoped(MartisCache::class, function (): MartisCache {
            return new MartisCache(Cache::store());
        });

        $this->app->singleton(SsoManager::class);

        $this->app->singleton(ImpersonationManager::class, function ($app) {
            return new ImpersonationManager(
                $app,
                $app->make(AuthManager::class),
            );
        });

        // Bound as a plain singleton (not a factory closure) so consumers
        // can rebind a subclass in their own service provider — same
        // swappable-manager pattern as ImpersonationManager above. Only
        // the token core (invite/findByRawToken) ships in this task;
        // accept()/resend()/revoke() land in later tasks.
        $this->app->singleton(InvitationManager::class);

        // Auth-flow defaults. Each contract resolves to a Martis-shipped
        // implementation; consumer apps override by re-binding in their
        // own service provider. See docs/authentication.md →
        // "Customising auth surfaces".
        $this->app->bind(
            RegistersUsers::class,
            DefaultRegistersUsers::class,
        );
        $this->app->bind(
            SendsPasswordResetLinks::class,
            DefaultSendsPasswordResetLinks::class,
        );
        $this->app->bind(
            ResetsUserPasswords::class,
            DefaultResetsUserPasswords::class,
        );
        $this->app->bind(
            SendsEmailVerification::class,
            DefaultSendsEmailVerification::class,
        );
    }

    /** Boot package services: routes, views, translations, assets, and console commands. */
    public function boot(): void
    {
        $this->registerMiddlewareAlias();
        $this->registerExceptionHandling();
        $this->registerCacheGate();
        $this->registerInvitationGate();
        $this->discoverResources();
        $this->discoverTools();
        $this->registerApiDocs();

        $this->loadRoutesFrom(__DIR__.'/../routes/martis.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'martis');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'martis');

        $this->registerBuiltInResources();
        $this->registerPasswordResetUrl();
        $this->registerEmailVerificationUrl();
        $this->registerRateLimiters();
        $this->registerRoleAuditListeners();
        $this->registerOctanePolicyCacheFlush();

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
                ThemeDiffCommand::class,
                VendorPublishCommand::class,
                PublishAssetsCommand::class,
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
                ListEnvVarsCommand::class,
                SsoMakeCommand::class,
                RolesScaffoldCommand::class,
                StubsCommand::class,
                AgentsCommand::class,
                McpServeCommand::class,
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

            // Profile: 2FA columns migration stub
            $this->publishes([
                __DIR__.'/../stubs/add_two_factor_columns.php.stub' => database_path('migrations/'.date('Y_m_d').'_000002_add_two_factor_columns.php'),
            ], 'martis-2fa-migration');

            // Profile: profile picture column migration stub
            // (published dynamically by InstallCommand based on user-chosen column name)
            $this->publishes([
                __DIR__.'/../stubs/add_profile_picture_column.php.stub' => database_path('migrations/'.date('Y_m_d').'_000003_add_profile_picture_column.php'),
            ], 'martis-avatar-migration');

            // User preferences table for theme/accent/density/locale/
            // reduced-motion persistence + shareable presets.
            $this->publishes([
                __DIR__.'/../stubs/create_user_preferences_table.php.stub' => database_path('migrations/'.date('Y_m_d').'_000004_create_martis_user_preferences_table.php'),
            ], 'martis-preferences-migration');

            // Optional Laravel `sessions` table (key-type-aware) for the
            // browser-sessions profile section. Only needed when 'sessions'
            // is in profile.sections AND SESSION_DRIVER=database.
            $this->publishes([
                __DIR__.'/../stubs/create_sessions_table.php.stub' => database_path('migrations/'.date('Y_m_d').'_000005_create_sessions_table.php'),
            ], 'martis-sessions-migration');

            // Optional `invitations` table (key-type-aware) for the
            // invite-a-user flow. Portable schema stub only — no model
            // or behaviour ships yet; gated behind config('martis.invitations.enabled')
            // and the `martis-invite` Gate before it is reachable.
            $this->publishes([
                __DIR__.'/../stubs/create_invitations_table.php.stub' => database_path('migrations/'.date('Y_m_d').'_000008_create_invitations_table.php'),
            ], 'martis-invitations-migration');

            // v1.10.5 drop migration for `dashboards_layout`. v1.10.4
            // briefly shipped that column under the retracted per-user
            // toggle; v1.10.5 nests dashboards declaratively via
            // `Dashboard::under()` instead, so the column is dead weight.
            // Idempotent — skipped when the column is already absent.
            $this->publishes([
                __DIR__.'/../stubs/drop_dashboards_layout_from_user_preferences_table.php.stub' => database_path('migrations/'.date('Y_m_d').'_000007_drop_dashboards_layout_from_user_preferences_table.php'),
            ], 'martis-preferences-drop-dashboards-layout-migration');

            // Cache subsystem operational metadata. Lives in a
            // dedicated table so the version counter / cleared_at /
            // runtime override flag survive Cache::flush(),
            // redis-cli FLUSHDB, container restarts, and LRU
            // eviction. v1.8.8.
            $this->publishes([
                __DIR__.'/../stubs/create_martis_cache_state_table.php.stub' => database_path('migrations/'.date('Y_m_d').'_000005_create_martis_cache_state_table.php'),
            ], 'martis-cache-state-migration');

            // Bundle this into `martis-migrations` too so apps doing
            // `vendor:publish --tag=martis-migrations` pick up every
            // package-owned table at once.
            $this->publishes([
                __DIR__.'/../stubs/create_martis_action_events_table.php.stub' => database_path('migrations/'.date('Y_m_d').'_000001_create_martis_action_events_table.php'),
                __DIR__.'/../stubs/create_user_preferences_table.php.stub' => database_path('migrations/'.date('Y_m_d').'_000004_create_martis_user_preferences_table.php'),
                __DIR__.'/../stubs/create_martis_cache_state_table.php.stub' => database_path('migrations/'.date('Y_m_d').'_000005_create_martis_cache_state_table.php'),
                __DIR__.'/../stubs/drop_dashboards_layout_from_user_preferences_table.php.stub' => database_path('migrations/'.date('Y_m_d').'_000007_drop_dashboards_layout_from_user_preferences_table.php'),
            ], 'martis-migrations');

            // Host-app MartisServiceProvider stub. Holds main menu /
            // dashboards / cache layers / gate definitions — anything
            // that can't live in `config/martis.php` because closures
            // don't survive `config:cache`. The InstallCommand publishes
            // this automatically and wires it into
            // `bootstrap/providers.php`; the tag below lets advanced
            // users republish it on demand.
            $this->publishes([
                __DIR__.'/../stubs/MartisServiceProvider.php.stub' => app_path('Providers/MartisServiceProvider.php'),
            ], 'martis-provider');
        }
    }

    /**
     * Register the default cache-related gates. Both DENY by default.
     *
     * `manage-martis-cache` — access to the cache admin UI and REST
     * endpoints (flush / disable). Destructive and privileged, so the
     * package will not grant it implicitly. Until the host defines it,
     * the endpoints return 403 and the sidebar entry is hidden:
     *
     *     Gate::define('manage-martis-cache', fn ($user) => $user->is_admin);
     *
     * `bypass-martis-cache` — the right to skip the per-request cache via
     * the `X-Martis-No-Cache: 1` header or `?nocache=1`. Defaults to deny
     * so ordinary authenticated users cannot force expensive metric /
     * navigation / schema re-computation on every request:
     *
     *     Gate::define('bypass-martis-cache', fn ($user) => $user->is_admin);
     *
     * Calling `Gate::define()` from the host app replaces the closure
     * registered here, so order doesn't matter.
     */
    protected function registerCacheGate(): void
    {
        // Secure default: deny. The host must explicitly grant this ability.
        if (! Gate::has('manage-martis-cache')) {
            Gate::define('manage-martis-cache', fn ($user) => false);
        }

        // Secure default: deny. Without this, the cache-bypass signals
        // (header / query param) are ignored for every user.
        if (! Gate::has('bypass-martis-cache')) {
            Gate::define('bypass-martis-cache', fn () => false);
        }
    }

    /**
     * Register the default `martis-invite` gate. Denies by default.
     *
     * Invitations: no default — the consumer decides who may invite
     * (403 until defined):
     *
     *     Gate::define('martis-invite', fn ($user) => $user->is_admin);
     *
     * Calling `Gate::define()` from the host app replaces the closure
     * registered here, so order doesn't matter.
     */
    protected function registerInvitationGate(): void
    {
        // Secure default: deny. The host must explicitly grant this ability.
        if (! Gate::has('martis-invite')) {
            Gate::define('martis-invite', static fn ($user = null): bool => false);
        }
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

        if (! class_exists(Scramble::class)) {
            return;
        }

        $martisPath = trim((string) config('martis.path', 'martis'), '/');
        $apiDocsPath = trim((string) config('martis.api_docs.path', 'api-docs'), '/');
        $middleware = (array) config('martis.api_docs.middleware', ['web', 'auth']);

        Scramble::routes(function ($route) use ($martisPath) {
            $uri = ltrim((string) $route->uri(), '/');

            return str_starts_with($uri, $martisPath.'/api');
        });

        Scramble::registerUiRoute("{$martisPath}/{$apiDocsPath}")
            ->middleware($middleware);

        Scramble::registerJsonSpecificationRoute("{$martisPath}/{$apiDocsPath}.json")
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
        $router->aliasMiddleware('martis.verified', EnsureEmailIsVerified::class);
        $router->aliasMiddleware(
            'martis.impersonation.duration',
            EnforceImpersonationDuration::class,
        );
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

    /**
     * Auto-discover and register Tool classes from `app/Martis/Tools/`.
     *
     * Runs inside `Application::booted` so host providers that call
     * `Martis::tools([...])` manually have already executed — the
     * discovery's `mergeTools()` then appends with dedup, never
     * stomping the host's explicit registration.
     *
     * Disable per-app via `martis.discovery.tools = false` (defaults to
     * true) when full manual control is desired.
     */
    protected function discoverTools(): void
    {
        if (config('martis.discovery.tools', true) === false) {
            return;
        }

        /** @var string $toolsPath */
        $toolsPath = config(
            'martis.tools_path',
            rtrim((string) config('martis.resources_path', app_path('Martis')), '/').'/Tools'
        );

        $namespace = (string) config('martis.tools_namespace', 'App\\Martis\\Tools');

        $this->app->booted(function () use ($toolsPath, $namespace): void {
            $classes = (new ToolDiscovery($toolsPath, $namespace))->discover();

            if ($classes !== []) {
                Martis::mergeTools($classes);
            }
        });
    }

    /**
     * Wire the Laravel `ResetPassword` notification's URL builder to the
     * Martis-prefixed `martis.password.reset` route.
     *
     * Laravel's default notification calls `route('password.reset', …)`
     * to render the link in the email body. Martis nests every route
     * under a `martis.` name prefix, so the unqualified `password.reset`
     * is undefined and the broker crashes with
     * `Symfony\Component\Routing\Exception\RouteNotFoundException`.
     *
     * Defensive registration:
     *   - Only runs when the password-reset feature is enabled.
     *   - Does not overwrite a callback already configured by the host
     *     app (consumers can register their own URL builder before this
     *     boots, or override it from `AppServiceProvider::boot()` after
     *     this provider runs — both paths win).
     *
     * v1.8.3.
     */
    protected function registerPasswordResetUrl(): void
    {
        if (! (bool) config('martis.auth.passwordReset.enabled', false)) {
            return;
        }

        // Reflection-based "is the static already set?" probe so we
        // don't trample a consumer's own callback.
        try {
            $ref = new \ReflectionClass(ResetPassword::class);
            if ($ref->hasProperty('createUrlCallback')) {
                $prop = $ref->getProperty('createUrlCallback');
                $prop->setAccessible(true);
                if ($prop->getValue() !== null) {
                    return; // Already customised — respect it.
                }
            }
        } catch (\Throwable) {
            // Probe failed; fall through to register anyway. Worst case
            // we override an unset default.
        }

        ResetPassword::createUrlUsing(static function (object $notifiable, string $token): string {
            $email = method_exists($notifiable, 'getEmailForPasswordReset')
                ? (string) $notifiable->getEmailForPasswordReset()
                : (string) ($notifiable->email ?? '');

            return route('martis.password.reset', [
                'token' => $token,
                'email' => $email,
            ]);
        });
    }

    /**
     * Override Laravel's stock `VerifyEmail` notification URL builder so
     * verification links resolve under Martis's own route name
     * (`martis.email.verify`) instead of the framework default
     * (`verification.verify`), which Martis does not register.
     *
     * Without this override, registering a user whose model implements
     * `MustVerifyEmail` crashes in
     * `SendEmailVerificationNotification` with
     * `Route [verification.verify] not defined.`
     *
     * Mirrors the precedent set by `registerPasswordResetUrl()`.
     * Skipped when email verification is disabled in config, and
     * preserves any consumer-side override via reflection probe.
     */
    protected function registerEmailVerificationUrl(): void
    {
        if (! (bool) config('martis.auth.email_verification.enabled', false)) {
            return;
        }

        try {
            $ref = new \ReflectionClass(VerifyEmail::class);
            if ($ref->hasProperty('createUrlCallback')) {
                $prop = $ref->getProperty('createUrlCallback');
                $prop->setAccessible(true);
                if ($prop->getValue() !== null) {
                    return; // Consumer already customised — respect it.
                }
            }
        } catch (\Throwable) {
            // Probe failed; fall through to register anyway.
        }

        VerifyEmail::createUrlUsing(static function (object $notifiable): string {
            $expireMinutes = (int) config('auth.verification.expire', 60);

            $key = method_exists($notifiable, 'getKey')
                ? $notifiable->getKey()
                : ($notifiable->id ?? null);

            $emailForVerification = method_exists($notifiable, 'getEmailForVerification')
                ? (string) $notifiable->getEmailForVerification()
                : (string) ($notifiable->email ?? '');

            return URL::temporarySignedRoute(
                'martis.email.verify',
                Carbon::now()->addMinutes($expireMinutes),
                [
                    'id' => $key,
                    'hash' => sha1($emailForVerification),
                ]
            );
        });
    }

    /**
     * Register the named rate limiters Martis applies on top of the
     * generic per-IP `throttle:N,1` middleware.
     *
     * `martis-login` keys on the lowercased email + the client IP, so
     * a credential-stuffing attempt against a single account is caught
     * regardless of which botnet IP fires the next attempt. The window
     * matches the global login throttle so users see a single, coherent
     * 429 envelope across both layers.
     *
     * Routes opt in via `throttle:martis-login` in addition to the
     * generic `throttle:N,1`. Per-IP catches a noisy machine, per-email
     * catches a slow distributed attack on a known account.
     */
    protected function registerRateLimiters(): void
    {
        $attempts = (int) config('martis.throttle.login_attempts', 20);
        $minutes = (int) config('martis.throttle.login_minutes', 1);

        RateLimiter::for('martis-login', function (Request $request) use ($attempts, $minutes) {
            $email = strtolower((string) $request->input('email', ''));

            // Empty-email request (no payload at all): fall back to
            // the standard per-IP envelope so a script hammering the
            // endpoint without payload still gets throttled.
            $key = $email === ''
                ? 'martis-login|ip|'.$request->ip()
                : 'martis-login|email|'.sha1($email).'|ip|'.$request->ip();

            return [
                Limit::perMinutes($minutes, $attempts)->by($key),
            ];
        });
    }

    /**
     * Subscribe Spatie role / permission attach + detach events to
     * the Martis audit log when the package is installed.
     *
     * Defensive: skips silently when Spatie's event classes are not
     * loaded (consumer never ran `martis:roles`) or when the audit
     * table is missing (consumer skipped the `martis:install` migrations).
     * The listener itself probes both at dispatch time, so the
     * registration is cheap regardless.
     */
    protected function registerRoleAuditListeners(): void
    {
        // v1.8.8 — impersonation audit is package-internal (no third-party
        // dependency), register unconditionally.
        Event::listen(
            ImpersonationStarted::class,
            [RecordImpersonation::class, 'handleStarted'],
        );
        Event::listen(
            ImpersonationStopped::class,
            [RecordImpersonation::class, 'handleStopped'],
        );

        // Invitation lifecycle audit is package-internal too (no third-party
        // dependency), register unconditionally. RecordInvitation itself
        // gates on `martis.audit.invitations` + the audit table's presence.
        Event::listen(
            InvitationCreated::class,
            [RecordInvitation::class, 'handleCreated'],
        );
        Event::listen(
            InvitationAccepted::class,
            [RecordInvitation::class, 'handleAccepted'],
        );
        Event::listen(
            InvitationRevoked::class,
            [RecordInvitation::class, 'handleRevoked'],
        );

        // v1.8.8 — Gate denial audit. The listener carries per-request
        // dedup state, so register it as a request-scoped singleton so
        // the same instance handles every event in one request lifecycle.
        $this->app->scoped(RecordAuthorizationDenial::class);
        Event::listen(
            GateEvaluated::class,
            [RecordAuthorizationDenial::class, 'handle'],
        );

        // v1.8.8 — Per-request Gate cache. Same scoped registration so
        // the cache state lives only for the current request lifecycle.
        $this->app->scoped(RequestScopedAbilityCache::class);
        Event::listen(
            GateEvaluated::class,
            [RequestScopedAbilityCache::class, 'handle'],
        );

        // Spatie listeners only register when the package is installed.
        // The class names diverged across major versions:
        //   - spatie/laravel-permission v6.x: `RoleAttached`, `RoleDetached`,
        //     `PermissionAttached`, `PermissionDetached` (no `Event` suffix).
        //   - v7.x: same names with the `Event` suffix.
        // Probe both so the listener wires regardless of the major the
        // host has resolved (Laravel 11 forces 6.x; Laravel 12+ pulls 7.x).
        $candidates = [
            'handleRoleAttached' => [
                RoleAttachedEvent::class,
                'Spatie\\Permission\\Events\\RoleAttached',
            ],
            'handleRoleDetached' => [
                RoleDetachedEvent::class,
                'Spatie\\Permission\\Events\\RoleDetached',
            ],
            'handlePermissionAttached' => [
                PermissionAttachedEvent::class,
                'Spatie\\Permission\\Events\\PermissionAttached',
            ],
            'handlePermissionDetached' => [
                PermissionDetachedEvent::class,
                'Spatie\\Permission\\Events\\PermissionDetached',
            ],
        ];

        foreach ($candidates as $method => $eventClasses) {
            foreach ($eventClasses as $eventClass) {
                if (! class_exists($eventClass)) {
                    continue;
                }
                Event::listen($eventClass, [
                    RecordRoleChange::class,
                    $method,
                ]);
            }
        }
    }

    /**
     * Register listeners that flush the static policy-resolution caches
     * held by {@see \Martis\Resource} and {@see HasPolicy}
     * between requests in persistent-process environments (Laravel Octane,
     * queue workers).
     *
     * Under PHP-FPM the process dies after each request so the caches are
     * naturally empty on the next request. Under Octane or long-running
     * queue workers the same process handles many requests; without this
     * flush a policy class rebound in the container (e.g. via
     * `$this->app->bind(MyPolicy::class, …)` in a request-scoped provider)
     * would not be visible to subsequent requests because the old instance
     * is already cached in the static array.
     *
     * The listener is registered only when the Octane event classes exist
     * in the project, so there is no hard dependency on laravel/octane.
     */
    protected function registerOctanePolicyCacheFlush(): void
    {
        // Referenced as strings, not ::class, so there is no compile-time
        // dependency on laravel/octane (an optional peer). The class_exists()
        // guard below registers the listener only when Octane is installed.
        $octaneEvents = [
            'Laravel\\Octane\\Events\\RequestTerminated',
            'Laravel\\Octane\\Events\\TaskTerminated',
        ];

        foreach ($octaneEvents as $eventClass) {
            if (! class_exists($eventClass)) {
                continue;
            }

            Event::listen($eventClass, static function () {
                \Martis\Resource::flushPolicyCache();
                HasPolicy::flushPolicyCache();
            });
        }
    }
}
