<?php

use App\Http\Controllers\API\DesktopUpdates\DesktopReleaseController;
use App\Http\Middleware\EnsureDesktopReleasePermission;
use App\Http\Middleware\IdempotencyMiddleware;
use Illuminate\Support\Facades\Route;

// Permission must be revalidated even for a replay. Use the durable catalog journal,
// not the generic middleware's cached response before route-level authorization.
Route::prefix('config/desktop-releases')->withoutMiddleware(IdempotencyMiddleware::class)->group(function (): void {
    Route::get('/', [DesktopReleaseController::class, 'index'])->middleware(EnsureDesktopReleasePermission::class.':updbtn01');
    Route::get('/artifacts', [DesktopReleaseController::class, 'artifacts'])->middleware(EnsureDesktopReleasePermission::class.':updbtn02');
    Route::post('/', [DesktopReleaseController::class, 'store'])->middleware(EnsureDesktopReleasePermission::class.':updbtn02');
    Route::put('/{id}', [DesktopReleaseController::class, 'update'])->whereUuid('id')->middleware(EnsureDesktopReleasePermission::class.':updbtn03');
    Route::get('/{id}/pilots', [DesktopReleaseController::class, 'pilotDevices'])->whereUuid('id')->middleware(EnsureDesktopReleasePermission::class.':updbtn04');
    Route::put('/{id}/pilots', [DesktopReleaseController::class, 'setPilots'])->whereUuid('id')->middleware(EnsureDesktopReleasePermission::class.':updbtn04');
    Route::post('/{id}/pilot', [DesktopReleaseController::class, 'promotePilot'])->whereUuid('id')->middleware(EnsureDesktopReleasePermission::class.':updbtn05');
    Route::post('/{id}/stable', [DesktopReleaseController::class, 'promoteStable'])->whereUuid('id')->middleware(EnsureDesktopReleasePermission::class.':updbtn06');
    Route::post('/{id}/withdraw', [DesktopReleaseController::class, 'withdraw'])->whereUuid('id')->middleware(EnsureDesktopReleasePermission::class.':updbtn07');
    Route::get('/{id}/events', [DesktopReleaseController::class, 'events'])->whereUuid('id')->middleware(EnsureDesktopReleasePermission::class.':updbtn08');
});
