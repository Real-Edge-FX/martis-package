<?php

use Illuminate\Support\Facades\Route;
use Martis\Http\Controllers\AttachmentController;
use Martis\Http\Controllers\AuthController;
use Martis\Http\Controllers\DashboardController;
use Martis\Http\Controllers\HasManyController;
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

        // Protected routes — require martis.auth middleware
        Route::middleware(config('martis.auth_middleware', ['martis.auth']))
            ->group(function () {
                // API routes
                Route::prefix('api')
                    ->name('api.')
                    ->middleware(config('martis.api_middleware', ['throttle:60,1']))
                    ->group(function () {
                        Route::get('/navigation', [NavigationController::class, 'index'])->name('api.navigation');

                        // Attachment upload (Trix / Markdown file uploads)
                        Route::post('/attachments/upload', [AttachmentController::class, 'upload'])
                            ->name('attachments.upload');

                        // Resource CRUD
                        Route::get('/resources/{resource}/schema', [ResourceController::class, 'schema'])
                            ->name('resources.schema');
                        Route::get('/resources/{resource}', [ResourceController::class, 'index'])
                            ->name('resources.index');
                        Route::post('/resources/{resource}', [ResourceController::class, 'store'])
                            ->name('resources.store');
                        // Relatable options — Nova v5 parity (REA-1144)
                        Route::get('/resources/{resource}/{id}/relatable/{field}', [ResourceController::class, 'relatableOptions'])
                            ->name('resources.relatable');

                        // HasMany relationship CRUD — Nova v5 parity (REA-1109)
                        Route::get('/resources/{resource}/{id}/has-many/{relationship}', [HasManyController::class, 'index'])
                            ->name('resources.has-many.index');
                        Route::post('/resources/{resource}/{id}/has-many/{relationship}', [HasManyController::class, 'store'])
                            ->name('resources.has-many.store');
                        Route::put('/resources/{resource}/{id}/has-many/{relationship}/{relatedId}', [HasManyController::class, 'update'])
                            ->name('resources.has-many.update');
                        Route::delete('/resources/{resource}/{id}/has-many/{relationship}/{relatedId}', [HasManyController::class, 'destroy'])
                            ->name('resources.has-many.destroy');

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
