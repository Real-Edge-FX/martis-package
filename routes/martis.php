<?php

use Illuminate\Support\Facades\Route;
use Martis\Http\Controllers\ActionController;
use Martis\Http\Controllers\AttachmentController;
use Martis\Http\Controllers\MetricController;
use Martis\Http\Controllers\AuthController;
use Martis\Http\Controllers\BelongsToManyController;
use Martis\Http\Controllers\DashboardController;
use Martis\Http\Controllers\HasManyController;
use Martis\Http\Controllers\HasOneController;
use Martis\Http\Controllers\LensController;
use Martis\Http\Controllers\LoginController;
use Martis\Http\Controllers\MorphManyController;
use Martis\Http\Controllers\MorphOneController;
use Martis\Http\Controllers\MorphToManyController;
use Martis\Http\Controllers\NavigationController;
use Martis\Http\Controllers\ProfileController;
use Martis\Http\Controllers\ResourceController;
use Martis\Http\Controllers\SearchController;
use Martis\Http\Controllers\TranslationsController;
use Martis\Http\Controllers\TwoFactorController;

Route::middleware(config('martis.middleware', ['web']))
    ->prefix(config('martis.path', 'admin'))
    ->name('martis.')
    ->group(function () {
        // Public routes — no authentication required
        Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [LoginController::class, 'login'])
            ->middleware('throttle:5,1')
            ->name('login.attempt');
        Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

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
        Route::post('/api/auth/login', [AuthController::class, 'login'])
            ->middleware('throttle:5,1')
            ->name('api.auth.login');
        Route::post('/api/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

        // Translations — public, loaded before login
        Route::get('/api/translations/{locale}', [TranslationsController::class, 'show'])
            ->name('api.translations.show');
        // Auth user check — public so login page can check session without 401
        Route::get('/api/auth/user', [AuthController::class, 'user'])->name('api.auth.user');

        // Throttle middleware shorthand
        $throttle = config('martis.throttle.enabled', true)
            ? 'throttle:'.config('martis.throttle.max_attempts', 120).','.config('martis.throttle.decay_minutes', 1)
            : [];

        // Protected routes — require martis.auth middleware
        Route::middleware(config('martis.auth_middleware', ['martis.auth']))
            ->group(function () use ($throttle) {
                // ── 2FA challenge — auth only, NO martis.2fa (this is how you complete 2FA) ──
                Route::prefix('api')
                    ->name('api.')
                    ->middleware($throttle)
                    ->group(function () {
                        Route::post('/2fa/challenge', [TwoFactorController::class, 'challenge'])
                            ->middleware('throttle:5,1')
                            ->name('2fa.challenge');
                    });

                // ── All other protected routes — require completed 2FA ──
                Route::middleware('martis.2fa')
                    ->group(function () use ($throttle) {
                        // API routes
                        Route::prefix('api')
                            ->name('api.')
                            ->middleware($throttle)
                            ->group(function () {
                                Route::get('/navigation', [NavigationController::class, 'index'])->name('api.navigation');

                                // Dashboards and Metrics — Nova v5 parity
                                Route::get('/dashboards', [MetricController::class, 'dashboards'])
                                    ->name('dashboards.index');
                                Route::get('/dashboards/{dashboard}', [MetricController::class, 'show'])
                                    ->name('dashboards.show');
                                Route::get('/dashboards/{dashboard}/cards/{card}', [MetricController::class, 'computeDashboardMetric'])
                                    ->name('dashboards.cards.compute');

                                // Global Search — Nova v5 parity
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
                                }

                                // Resource CRUD
                                Route::get('/resources/{resource}/schema', [ResourceController::class, 'schema'])
                                    ->name('resources.schema');
                                Route::get('/resources/{resource}', [ResourceController::class, 'index'])
                                    ->name('resources.index');
                                Route::post('/resources/{resource}', [ResourceController::class, 'store'])
                                    ->name('resources.store');
                                // Relatable options — Nova v5 parity
                                Route::get('/resources/{resource}/{id}/relatable/{field}', [ResourceController::class, 'relatableOptions'])
                                    ->name('resources.relatable');

                                // Lenses — Nova v5 parity
                                Route::get('/resources/{resource}/lenses/{lens}', [LensController::class, 'index'])
                                    ->name('resources.lenses.index');

                                // HasMany relationship CRUD — Nova v5 parity
                                Route::get('/resources/{resource}/{id}/has-many/{relationship}', [HasManyController::class, 'index'])
                                    ->name('resources.has-many.index');
                                Route::post('/resources/{resource}/{id}/has-many/{relationship}', [HasManyController::class, 'store'])
                                    ->name('resources.has-many.store');
                                Route::put('/resources/{resource}/{id}/has-many/{relationship}/{relatedId}', [HasManyController::class, 'update'])
                                    ->name('resources.has-many.update');
                                Route::delete('/resources/{resource}/{id}/has-many/{relationship}/{relatedId}', [HasManyController::class, 'destroy'])
                                    ->name('resources.has-many.destroy');

                                // HasOne relationship — Nova v5 parity
                                Route::get('/resources/{resource}/{id}/has-one/{relationship}', [HasOneController::class, 'show'])
                                    ->name('resources.has-one.show');
                                Route::post('/resources/{resource}/{id}/has-one/{relationship}', [HasOneController::class, 'store'])
                                    ->name('resources.has-one.store');
                                Route::put('/resources/{resource}/{id}/has-one/{relationship}', [HasOneController::class, 'update'])
                                    ->name('resources.has-one.update');
                                Route::delete('/resources/{resource}/{id}/has-one/{relationship}', [HasOneController::class, 'destroy'])
                                    ->name('resources.has-one.destroy');

                                // BelongsToMany relationship — Nova v5 parity
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
                                // Pivot action execution — Nova v5 parity
                                Route::get('/resources/{resource}/{id}/belongs-to-many/{relationship}/actions', [ActionController::class, 'pivotIndex'])
                                    ->name('resources.belongs-to-many.actions.index');
                                Route::post('/resources/{resource}/{id}/belongs-to-many/{relationship}/actions/{action}', [ActionController::class, 'executePivot'])
                                    ->name('resources.belongs-to-many.actions.execute');

                                // MorphToMany polymorphic relationship — Nova v5 parity
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

                                // MorphMany polymorphic one-to-many — Nova v5 parity
                                Route::get('/resources/{resource}/{id}/morph-many/{relationship}', [MorphManyController::class, 'index'])
                                    ->name('resources.morph-many.index');
                                Route::post('/resources/{resource}/{id}/morph-many/{relationship}', [MorphManyController::class, 'store'])
                                    ->name('resources.morph-many.store');
                                Route::put('/resources/{resource}/{id}/morph-many/{relationship}/{relatedId}', [MorphManyController::class, 'update'])
                                    ->name('resources.morph-many.update');
                                Route::delete('/resources/{resource}/{id}/morph-many/{relationship}/{relatedId}', [MorphManyController::class, 'destroy'])
                                    ->name('resources.morph-many.destroy');

                                // MorphOne polymorphic one-to-one — Nova v5 parity
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
                                // Peek card — fetch related resource preview fields (Nova v5 parity)
                                Route::get('/resources/{resource}/{id}/peek', [ResourceController::class, 'peek'])
                                    ->name('resources.peek');
                                // Resource metric cards — Nova v5 parity
                                Route::get('/resources/{resource}/cards/{card}', [MetricController::class, 'computeResourceMetric'])
                                    ->name('resources.cards.compute');

                                // Action routes — Nova v5 parity
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
