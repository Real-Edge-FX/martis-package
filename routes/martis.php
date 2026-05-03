<?php

use Illuminate\Support\Facades\Route;
use Martis\Http\Controllers\ActionController;
use Martis\Http\Controllers\AttachmentController;
use Martis\Http\Controllers\AuthController;
use Martis\Http\Controllers\BelongsToManyController;
use Martis\Http\Controllers\CacheController;
use Martis\Http\Controllers\CommandPaletteController;
use Martis\Http\Controllers\DashboardController;
use Martis\Http\Controllers\EmailVerificationController;
use Martis\Http\Controllers\GuestPagesController;
use Martis\Http\Controllers\HasManyController;
use Martis\Http\Controllers\HasOneController;
use Martis\Http\Controllers\ImpersonationController;
use Martis\Http\Controllers\LensController;
use Martis\Http\Controllers\LoginController;
use Martis\Http\Controllers\MagicLinkController;
use Martis\Http\Controllers\MetaController;
use Martis\Http\Controllers\MetricController;
use Martis\Http\Controllers\MorphManyController;
use Martis\Http\Controllers\MorphOneController;
use Martis\Http\Controllers\MorphToManyController;
use Martis\Http\Controllers\NavigationController;
use Martis\Http\Controllers\NotificationController;
use Martis\Http\Controllers\PreferencesController;
use Martis\Http\Controllers\ProfileController;
use Martis\Http\Controllers\ResourceController;
use Martis\Http\Controllers\SearchController;
use Martis\Http\Controllers\SlugController;
use Martis\Http\Controllers\SsoController;
use Martis\Http\Controllers\ToolsController;
use Martis\Http\Controllers\TranslationsController;
use Martis\Http\Controllers\TwoFactorController;

Route::middleware(config('martis.middleware', ['web']))
    ->prefix(config('martis.path', 'admin'))
    ->name('martis.')
    ->group(function () {
        // Public routes — no authentication required
        Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [LoginController::class, 'login'])
            ->middleware('throttle:'.config('martis.throttle.login_attempts', 20).','.config('martis.throttle.login_minutes', 1))
            ->name('login.attempt');
        Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

        // Guest auth surfaces — always registered; the controller checks
        // the matching `martis.auth.{flow}.enabled` flag and the URL
        // override at request time and either renders the SPA shell
        // (default mode), 302s to /login (disabled), or 302s to the
        // off-platform URL (when `auth.{flow}.url` is set).
        Route::get('/register', [GuestPagesController::class, 'showRegister'])
            ->name('register');
        Route::get('/forgot-password', [GuestPagesController::class, 'showForgotPassword'])
            ->name('password.request');
        Route::get('/reset-password/{token}', [GuestPagesController::class, 'showResetPassword'])
            ->name('password.reset');

        // SSO entry points — public (no auth middleware). The provider
        // sub-segment is whatever the user registered (azure, google,
        // github, custom-okta, …). Routes only resolve when SSO is
        // enabled in config and the requested provider is registered.
        if (config('martis.auth.sso.enabled', false)) {
            Route::get('/sso/{provider}/redirect', [SsoController::class, 'redirect'])
                ->middleware('throttle:'.config('martis.throttle.login_attempts', 20).','.config('martis.throttle.login_minutes', 1))
                ->name('sso.redirect');
            Route::get('/sso/{provider}/callback', [SsoController::class, 'callback'])
                ->middleware('throttle:'.config('martis.throttle.login_attempts', 20).','.config('martis.throttle.login_minutes', 1))
                ->name('sso.callback');
        }

        // Favicon — public, served from configured path, published assets,
        // or the package's own resources/ directory as final fallback (so
        // the default Martis favicon works out-of-the-box without any
        // vendor:publish step, even after a Vite rebuild wipes public/).
        Route::get('/favicon.ico', function () {
            $faviconPath = config('martis.brand.favicon');
            if ($faviconPath) {
                if (str_contains($faviconPath, '..') || str_starts_with($faviconPath, '/')) {
                    abort(400, 'Invalid favicon path.');
                }
                if (file_exists(public_path($faviconPath))) {
                    return response()->file(public_path($faviconPath));
                }
            }
            $published = public_path('vendor/martis/favicon.ico');
            if (file_exists($published)) {
                return response()->file($published);
            }
            $packageDefault = __DIR__.'/../resources/favicon.ico';
            if (file_exists($packageDefault)) {
                return response()->file($packageDefault);
            }
            abort(404);
        })->name('favicon');

        // API auth — public (exempt from CSRF via playground bootstrap/app.php)
        // Two throttles compose: per-IP (generic) and per-email (named limiter
        // registered in MartisServiceProvider::registerRateLimiters()). The
        // per-email layer catches credential-stuffing distributed across IPs
        // that the generic per-IP throttle alone cannot stop.
        Route::post('/api/auth/login', [AuthController::class, 'login'])
            ->middleware([
                'throttle:'.config('martis.throttle.login_attempts', 20).','.config('martis.throttle.login_minutes', 1),
                'throttle:martis-login',
            ])
            ->name('api.auth.login');
        Route::post('/api/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

        // Registration + password-reset POST endpoints. Always
        // registered; the controllers check the matching enabled flag
        // and 404 when the surface is disabled (so the route inventory
        // stays predictable across environments and config:cache
        // doesn't have to differ between dev/staging/prod).
        Route::post('/api/auth/register', [AuthController::class, 'register'])
            ->middleware('throttle:'.config('martis.throttle.login_attempts', 20).','.config('martis.throttle.login_minutes', 1))
            ->name('api.auth.register');
        Route::post('/api/auth/password/email', [AuthController::class, 'sendPasswordResetLink'])
            ->middleware('throttle:'.config('martis.throttle.login_attempts', 20).','.config('martis.throttle.login_minutes', 1))
            ->name('api.auth.password.email');
        Route::post('/api/auth/password/reset', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:'.config('martis.throttle.login_attempts', 20).','.config('martis.throttle.login_minutes', 1))
            ->name('api.auth.password.reset');

        // Magic-link (passwordless) sign-in. Routes are always
        // registered; the controller checks `auth.magic_link.enabled`
        // and 404s when off (same pattern as register / forgot).
        Route::post('/api/auth/magic-link/request', [MagicLinkController::class, 'request'])
            ->middleware([
                'throttle:'.config('martis.throttle.login_attempts', 20).','.config('martis.throttle.login_minutes', 1),
                'throttle:martis-login',
            ])
            ->name('api.auth.magic-link.request');
        Route::get('/api/auth/magic-link/consume', [MagicLinkController::class, 'consume'])
            ->middleware('throttle:'.config('martis.throttle.login_attempts', 20).','.config('martis.throttle.login_minutes', 1))
            ->name('api.auth.magic-link.consume');

        // Email verification surfaces — always-registered. The
        // controller checks `auth.email_verification.enabled` and
        // 404s when off. Notice + send sit outside the auth group
        // because the user must be logged in but NOT yet verified to
        // hit them — the auth middleware below would gate them by
        // verified status if applied. The signed verify route uses
        // the framework's `signed` middleware for safety.
        Route::get('/email/verify', [EmailVerificationController::class, 'notice'])
            ->middleware('martis.auth')
            ->name('email.verify.notice');
        // Public route — the signed URL + `sha1(email)` hash in the
        // path are the proof of intent; requiring an active session
        // (via `martis.auth`) on top broke the common case of "open
        // email on phone, click link" because the user is logged out
        // and the auth middleware would bounce them to /login,
        // discarding the signature. v1.8.16+.
        Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:6,1'])
            ->name('email.verify');
        // Resend throttle dropped from 6/min to 3/min after a user
        // reported clicking >5 times in a row without being blocked.
        // 3/min is the conventional ceiling for password-reset and
        // verification re-send flows. v1.8.16+.
        Route::post('/api/auth/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware(['martis.auth', 'throttle:3,1'])
            ->name('api.auth.email.verification.send');

        // Translations — public, loaded before login
        Route::get('/api/translations/{locale}', [TranslationsController::class, 'show'])
            ->name('api.translations.show');
        // Auth user check — public so login page can check session without 401
        Route::get('/api/auth/user', [AuthController::class, 'user'])->name('api.auth.user');

        // Throttle middleware shorthand
        $throttle = config('martis.throttle.enabled', true)
            ? 'throttle:'.config('martis.throttle.max_attempts', 120).','.config('martis.throttle.decay_minutes', 1)
            : [];

        // Protected routes — require martis.auth middleware. The
        // `martis.impersonation.duration` middleware sits inside the
        // auth group so it can read the impersonation session bag —
        // it auto-stops impersonation that has run past the configured
        // `max_duration_minutes` (default disabled).
        Route::middleware([
            ...config('martis.auth_middleware', ['martis.auth']),
            'martis.impersonation.duration',
        ])
            ->group(function () use ($throttle) {
                // ── 2FA challenge — auth only, NO martis.2fa (this is how you complete 2FA) ──
                Route::prefix('api')
                    ->name('api.')
                    ->middleware($throttle)
                    ->group(function () {
                        Route::post('/2fa/challenge', [TwoFactorController::class, 'challenge'])
                            ->middleware('throttle:'.config('martis.throttle.login_attempts', 20).','.config('martis.throttle.login_minutes', 1))
                            ->name('2fa.challenge');
                    });

                // ── All other protected routes — require completed 2FA ──
                // `martis.locale` applies the user's saved language preference
                // before controllers run so `__()` and validation messages are
                // returned in their chosen locale.
                // `martis.verified` only enforces `email_verified_at` when
                // `martis.auth.email_verification.enabled=true` (otherwise
                // it's a pass-through), so this is safe to apply globally
                // — backwards-compatible for consumers that haven't opted
                // in.
                Route::middleware(['martis.2fa', 'martis.locale', 'martis.verified'])
                    ->group(function () use ($throttle) {
                        // API routes
                        Route::prefix('api')
                            ->name('api.')
                            ->middleware($throttle)
                            ->group(function () {
                                Route::get('/navigation', [NavigationController::class, 'index'])->name('navigation');
                                Route::get('/navigation/badges', [NavigationController::class, 'badges'])->name('navigation.badges');

                                // Meta endpoints — reference data for admin
                                // dropdowns. v1.8.0.
                                Route::get('/_meta/guards', [MetaController::class, 'guards'])->name('meta.guards');

                                // Tools — free-form sidebar pages registered
                                // via `Martis::tools([...])`. List + per-key
                                // metadata; the SPA catch-all renders the
                                // page itself by looking up the React
                                // component bound to the tool's component()
                                // key.
                                Route::get('/tools', [ToolsController::class, 'index'])->name('tools.index');
                                Route::get('/tools/{uriKey}', [ToolsController::class, 'show'])->name('tools.show');

                                // Impersonation — v0.10 opt-in subsystem.
                                // Master switch is off by default; gate
                                // `martis-impersonate` must be defined by
                                // the consumer. See docs/impersonation.md.
                                Route::get('/impersonation/status', [ImpersonationController::class, 'status'])
                                    ->name('impersonation.status');
                                Route::post('/impersonation/start/{userId}', [ImpersonationController::class, 'start'])
                                    ->name('impersonation.start');
                                Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])
                                    ->name('impersonation.stop');

                                // Global command palette aggregate — resources,
                                // standalone actions, and the user's recent
                                // action-events in one round-trip.
                                Route::get('/command-palette', [CommandPaletteController::class, 'index'])->name('command-palette');

                                // User preferences (Task 07.1 ⭐ D2)
                                if (config('martis.preferences.enabled', true)) {
                                    Route::get('/preferences', [PreferencesController::class, 'show'])->name('preferences.show');
                                    Route::put('/preferences', [PreferencesController::class, 'update'])->name('preferences.update');
                                    Route::delete('/preferences', [PreferencesController::class, 'reset'])->name('preferences.reset');
                                }

                                // Cache admin (v0.8 — Task 17)
                                if (config('martis.cache.admin_ui', true)) {
                                    Route::get('/cache', [CacheController::class, 'status'])
                                        ->name('cache.status');
                                    Route::post('/cache/clear', [CacheController::class, 'clear'])
                                        ->name('cache.clear');
                                    Route::post('/cache/disable', [CacheController::class, 'disable'])
                                        ->name('cache.disable');
                                    Route::post('/cache/enable', [CacheController::class, 'enable'])
                                        ->name('cache.enable');
                                    Route::post('/cache/reset-override', [CacheController::class, 'resetOverride'])
                                        ->name('cache.reset-override');
                                }

                                // In-app notifications (v0.8 — Task 12)
                                if (config('martis.notifications.enabled', true)) {
                                    Route::get('/notifications', [NotificationController::class, 'index'])
                                        ->name('notifications.index');
                                    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
                                        ->name('notifications.unread-count');
                                    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
                                        ->name('notifications.read-all');
                                    Route::delete('/notifications', [NotificationController::class, 'clearAll'])
                                        ->name('notifications.clear');
                                    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])
                                        ->name('notifications.read');
                                    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])
                                        ->name('notifications.destroy');
                                }

                                // Dashboards and Metrics
                                Route::get('/dashboards', [MetricController::class, 'dashboards'])
                                    ->name('dashboards.index');
                                Route::get('/dashboards/{dashboard}', [MetricController::class, 'show'])
                                    ->name('dashboards.show');
                                Route::get('/dashboards/{dashboard}/cards/{card}', [MetricController::class, 'computeDashboardMetric'])
                                    ->name('dashboards.cards.compute');

                                // Global Search
                                Route::get('/search', [SearchController::class, 'search'])->name('search');

                                // Attachment upload (Trix / Markdown file uploads)
                                Route::post('/attachments/upload', [AttachmentController::class, 'upload'])
                                    ->name('attachments.upload');

                                // ──────────────────────────────────────────────────────
                                // Profile routes — registered only when profile is enabled
                                // ──────────────────────────────────────────────────────
                                if (config('martis.profile.enabled', true)) {
                                    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
                                    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
                                    Route::post('/profile/password', [ProfileController::class, 'changePassword'])->name('profile.password');

                                    // Avatar
                                    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar'])->name('profile.avatar.upload');
                                    Route::delete('/profile/avatar', [ProfileController::class, 'removeAvatar'])->name('profile.avatar.remove');

                                    // 2FA setup (within profile)
                                    Route::post('/profile/2fa/setup', [ProfileController::class, 'twoFactorSetup'])->name('profile.2fa.setup');
                                    Route::post('/profile/2fa/confirm', [ProfileController::class, 'twoFactorConfirm'])->name('profile.2fa.confirm');
                                    Route::delete('/profile/2fa', [ProfileController::class, 'twoFactorDisable'])->name('profile.2fa.disable');
                                    Route::post('/profile/2fa/recovery-codes', [ProfileController::class, 'twoFactorRegenerateCodes'])->name('profile.2fa.recovery-codes');

                                    // Browser sessions — backed by the Laravel
                                    // database session driver. The service
                                    // short-circuits to supported=false when
                                    // the driver does not store sessions.
                                    Route::get('/profile/sessions', [ProfileController::class, 'sessions'])->name('profile.sessions.index');
                                    Route::delete('/profile/sessions/others', [ProfileController::class, 'destroyOtherSessions'])->name('profile.sessions.destroy.others');
                                    Route::delete('/profile/sessions/{id}', [ProfileController::class, 'destroySession'])
                                        ->where('id', '[A-Za-z0-9]+')
                                        ->name('profile.sessions.destroy');
                                }

                                // Resource CRUD
                                Route::get('/resources/{resource}/schema', [ResourceController::class, 'schema'])
                                    ->name('resources.schema');
                                Route::get('/resources/{resource}', [ResourceController::class, 'index'])
                                    ->name('resources.index');
                                Route::post('/resources/{resource}', [ResourceController::class, 'store'])
                                    ->name('resources.store');
                                // Relatable options
                                Route::get('/resources/{resource}/{id}/relatable/{field}', [ResourceController::class, 'relatableOptions'])
                                    ->name('resources.relatable');

                                // Slug live collision check — Martis differential (D2)
                                Route::get('/resources/{resource}/slug-check/{field}', [SlugController::class, 'check'])
                                    ->name('resources.slug.check');

                                // Lenses
                                Route::get('/resources/{resource}/lenses/{lens}', [LensController::class, 'index'])
                                    ->name('resources.lenses.index');

                                // HasMany relationship CRUD
                                Route::get('/resources/{resource}/{id}/has-many/{relationship}', [HasManyController::class, 'index'])
                                    ->name('resources.has-many.index');
                                Route::post('/resources/{resource}/{id}/has-many/{relationship}', [HasManyController::class, 'store'])
                                    ->name('resources.has-many.store');
                                Route::put('/resources/{resource}/{id}/has-many/{relationship}/{relatedId}', [HasManyController::class, 'update'])
                                    ->name('resources.has-many.update');
                                Route::delete('/resources/{resource}/{id}/has-many/{relationship}/{relatedId}', [HasManyController::class, 'destroy'])
                                    ->name('resources.has-many.destroy');

                                // HasOne relationship
                                Route::get('/resources/{resource}/{id}/has-one/{relationship}', [HasOneController::class, 'show'])
                                    ->name('resources.has-one.show');
                                Route::post('/resources/{resource}/{id}/has-one/{relationship}', [HasOneController::class, 'store'])
                                    ->name('resources.has-one.store');
                                Route::put('/resources/{resource}/{id}/has-one/{relationship}', [HasOneController::class, 'update'])
                                    ->name('resources.has-one.update');
                                Route::delete('/resources/{resource}/{id}/has-one/{relationship}', [HasOneController::class, 'destroy'])
                                    ->name('resources.has-one.destroy');

                                // BelongsToMany relationship
                                Route::get('/resources/{resource}/{id}/belongs-to-many/{relationship}', [BelongsToManyController::class, 'index'])
                                    ->name('resources.belongs-to-many.index');
                                Route::get('/resources/{resource}/{id}/belongs-to-many/{relationship}/attachable', [BelongsToManyController::class, 'attachableIndex'])
                                    ->name('resources.belongs-to-many.attachable');
                                Route::post('/resources/{resource}/{id}/belongs-to-many/{relationship}/attach', [BelongsToManyController::class, 'attach'])
                                    ->name('resources.belongs-to-many.attach');
                                Route::delete('/resources/{resource}/{id}/belongs-to-many/{relationship}/{relatedId}/detach', [BelongsToManyController::class, 'detach'])
                                    ->name('resources.belongs-to-many.detach');
                                Route::put('/resources/{resource}/{id}/belongs-to-many/{relationship}/{relatedId}/pivot', [BelongsToManyController::class, 'updatePivot'])
                                    ->name('resources.belongs-to-many.pivot');
                                // Pivot action execution
                                Route::get('/resources/{resource}/{id}/belongs-to-many/{relationship}/actions', [ActionController::class, 'pivotIndex'])
                                    ->name('resources.belongs-to-many.actions.index');
                                Route::post('/resources/{resource}/{id}/belongs-to-many/{relationship}/actions/{action}', [ActionController::class, 'executePivot'])
                                    ->name('resources.belongs-to-many.actions.execute');

                                // MorphToMany polymorphic relationship
                                Route::get('/resources/{resource}/{id}/morph-to-many/{relationship}', [MorphToManyController::class, 'index'])
                                    ->name('resources.morph-to-many.index');
                                Route::get('/resources/{resource}/{id}/morph-to-many/{relationship}/attachable', [MorphToManyController::class, 'attachableIndex'])
                                    ->name('resources.morph-to-many.attachable');
                                Route::post('/resources/{resource}/{id}/morph-to-many/{relationship}/attach', [MorphToManyController::class, 'attach'])
                                    ->name('resources.morph-to-many.attach');
                                Route::delete('/resources/{resource}/{id}/morph-to-many/{relationship}/{relatedId}/detach', [MorphToManyController::class, 'detach'])
                                    ->name('resources.morph-to-many.detach');
                                Route::put('/resources/{resource}/{id}/morph-to-many/{relationship}/{relatedId}/pivot', [MorphToManyController::class, 'updatePivot'])
                                    ->name('resources.morph-to-many.pivot');

                                // MorphMany polymorphic one-to-many
                                Route::get('/resources/{resource}/{id}/morph-many/{relationship}', [MorphManyController::class, 'index'])
                                    ->name('resources.morph-many.index');
                                Route::post('/resources/{resource}/{id}/morph-many/{relationship}', [MorphManyController::class, 'store'])
                                    ->name('resources.morph-many.store');
                                Route::put('/resources/{resource}/{id}/morph-many/{relationship}/{relatedId}', [MorphManyController::class, 'update'])
                                    ->name('resources.morph-many.update');
                                Route::delete('/resources/{resource}/{id}/morph-many/{relationship}/{relatedId}', [MorphManyController::class, 'destroy'])
                                    ->name('resources.morph-many.destroy');

                                // MorphOne polymorphic one-to-one
                                Route::get('/resources/{resource}/{id}/morph-one/{relationship}', [MorphOneController::class, 'show'])
                                    ->name('resources.morph-one.show');
                                Route::post('/resources/{resource}/{id}/morph-one/{relationship}', [MorphOneController::class, 'store'])
                                    ->name('resources.morph-one.store');
                                Route::put('/resources/{resource}/{id}/morph-one/{relationship}', [MorphOneController::class, 'update'])
                                    ->name('resources.morph-one.update');
                                Route::delete('/resources/{resource}/{id}/morph-one/{relationship}', [MorphOneController::class, 'destroy'])
                                    ->name('resources.morph-one.destroy');

                                // Force delete (permanent deletion of soft-deleted records)
                                Route::delete('/resources/{resource}/{id}/force', [ResourceController::class, 'forceDelete'])
                                    ->name('resources.force-delete');
                                // Replicate fields (pre-fill data for create form)
                                Route::get('/resources/{resource}/{id}/replicate', [ResourceController::class, 'replicateFields'])
                                    ->name('resources.replicate');
                                // Peek card — fetch related resource preview fields
                                Route::get('/resources/{resource}/{id}/peek', [ResourceController::class, 'peek'])
                                    ->name('resources.peek');
                                // Resource metric cards
                                Route::get('/resources/{resource}/cards/{card}', [MetricController::class, 'computeResourceMetric'])
                                    ->name('resources.cards.compute');

                                // Action routes
                                Route::get('/resources/{resource}/actions', [ActionController::class, 'index'])
                                    ->name('resources.actions.index');
                                Route::get('/resources/{resource}/actions/{action}/fields', [ActionController::class, 'fields'])
                                    ->name('resources.actions.fields');
                                Route::post('/resources/{resource}/actions/{action}', [ActionController::class, 'execute'])
                                    ->name('resources.actions.execute');
                                Route::post('/resources/{resource}/{id}/actions/{action}', [ActionController::class, 'executeSingle'])
                                    ->name('resources.actions.execute-single');

                                // Inline create schema
                                Route::get('/resources/{resource}/inline-create-schema', [ResourceController::class, 'inlineCreateSchema'])
                                    ->name('resources.inline-create-schema');
                                // Inline create store
                                Route::post('/resources/{resource}/inline-create', [ResourceController::class, 'inlineCreateStore'])
                                    ->name('resources.inline-create-store');

                                // Sync a single dependsOn() field against
                                // the live form payload. The frontend hits
                                // this whenever a watched sibling field
                                // changes. Returns the fresh field
                                // descriptor with reactive state applied.
                                Route::post('/resources/{resource}/sync-field', [ResourceController::class, 'syncField'])
                                    ->name('resources.sync-field');

                                Route::get('/resources/{resource}/{id}', [ResourceController::class, 'show'])
                                    ->name('resources.show');
                                Route::put('/resources/{resource}/{id}', [ResourceController::class, 'update'])
                                    ->name('resources.update');
                                Route::delete('/resources/{resource}/{id}', [ResourceController::class, 'destroy'])
                                    ->name('resources.destroy');
                                Route::put('/resources/{resource}/{id}/restore', [ResourceController::class, 'restore'])
                                    ->name('resources.restore');
                            });

                        // SPA catch-all — must come AFTER api routes
                        Route::get('/', [DashboardController::class, 'index'])->name('index');
                        Route::get('/{path}', [DashboardController::class, 'index'])
                            ->where('path', '(?!api/).*')
                            ->name('spa');
                    });
            });
    });
