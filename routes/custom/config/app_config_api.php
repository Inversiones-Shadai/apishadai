<?php

use App\Http\Controllers\AppConfigController;
use App\Http\Middleware\EnsureAppConfigPermission;
use Illuminate\Support\Facades\Route;

Route::prefix('appconfig')->group(function () {
    Route::get('/', [AppConfigController::class, 'index'])->middleware(EnsureAppConfigPermission::class.':acfbtn01');
    Route::post('/', [AppConfigController::class, 'store'])->middleware(EnsureAppConfigPermission::class.':acfbtn02');
    Route::get('/{id}', [AppConfigController::class, 'show'])->whereNumber('id')->middleware(EnsureAppConfigPermission::class.':acfbtn03');
    Route::put('/{id}', [AppConfigController::class, 'update'])->whereNumber('id')->middleware(EnsureAppConfigPermission::class.':acfbtn03');
});

Route::prefix('apputils')->group(function () {
    Route::get('/check', [AppConfigController::class, 'checkVersion']);

});
