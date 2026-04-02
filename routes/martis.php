<?php

use Illuminate\Support\Facades\Route;
use Martis\Http\Controllers\MartisController;

Route::middleware(config('martis.middleware', ['web', 'auth']))
    ->prefix(config('martis.path', 'martis'))
    ->name('martis.')
    ->group(function () {
        Route::get('/', [MartisController::class, 'index'])->name('index');
        Route::get('/{any}', [MartisController::class, 'index'])->where('any', '.*')->name('spa');
    });
