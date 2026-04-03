<?php

use Illuminate\Support\Facades\Route;
use Martis\Http\Controllers\DashboardController;
use Martis\Http\Controllers\LoginController;
use Martis\Http\Controllers\ResourceController;

Route::middleware(config('martis.middleware', ['web']))
    ->prefix(config('martis.path', 'martis'))
    ->name('martis.')
    ->group(function () {
        // Public routes — no auth required
        Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
        Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

        // Protected routes — require martis.auth middleware
        Route::middleware(config('martis.auth_middleware', ['martis.auth']))
            ->group(function () {
                // API routes for resource CRUD
                Route::prefix('api')
                    ->name('api.')
                    ->middleware(config('martis.api_middleware', ['throttle:60,1']))
                    ->group(function () {
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

                // SPA catch-all — must come AFTER API routes
                Route::get('/', [DashboardController::class, 'index'])->name('index');
                Route::get('/{path}', [DashboardController::class, 'index'])
                    ->where('path', '(?!api/).*')
                    ->name('spa');
            });
    });
