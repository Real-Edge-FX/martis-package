<?php

use Illuminate\Support\Facades\Route;
use Martis\Http\Controllers\AuthController;
use Martis\Http\Controllers\DashboardController;
use Martis\Http\Controllers\LoginController;
use Martis\Http\Controllers\NavigationController;
use Martis\Http\Controllers\ResourceController;
use Martis\Http\Controllers\TranslationsController;

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

        // API logout — public so it works even with stale CSRF/session
        Route::post('/api/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

        // Translations — public, loaded before login
        Route::get('/api/translations/{locale}', [TranslationsController::class, 'show'])
            ->name('api.translations.show');
        // Auth user check — public so login page can check session without 401
        Route::get('/api/auth/user', [AuthController::class, 'user'])->name('api.auth.user');

        // Protected routes — require martis.auth middleware
        Route::middleware(config('martis.auth_middleware', ['martis.auth']))
            ->group(function () {
                // API routes
                Route::prefix('api')
                    ->name('api.')
                    ->middleware(config('martis.api_middleware', ['throttle:60,1']))
                    ->group(function () {
                        Route::get('/navigation', [NavigationController::class, 'index'])->name('api.navigation');

                        // Resource CRUD
                        Route::get('/resources/{resource}/schema', [ResourceController::class, 'schema'])
                            ->name('resources.schema');
                        Route::get('/resources/{resource}', [ResourceController::class, 'index'])
                            ->name('resources.index');
                        Route::post('/resources/{resource}', [ResourceController::class, 'store'])
                            ->name('resources.store');
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
