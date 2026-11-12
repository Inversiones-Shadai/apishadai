<?php

use App\Http\Controllers\API\Licensing\LicenseAdminController;
use App\Http\Controllers\API\Licensing\LicenseCustomerController;
use App\Http\Middleware\EnsureLicensePermission;
use App\Http\Middleware\IdempotencyMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('config/licenses')->group(function (): void {
    Route::get('/customers', [LicenseCustomerController::class, 'index'])->middleware(EnsureLicensePermission::class.':licbtn01');
    Route::post('/customers', [LicenseCustomerController::class, 'store'])->middleware(EnsureLicensePermission::class.':licbtn02');
    Route::put('/customers/{customer}', [LicenseCustomerController::class, 'update'])->whereUuid('customer')->middleware(EnsureLicensePermission::class.':licbtn03');
    Route::get('/', [LicenseAdminController::class, 'index'])->middleware(EnsureLicensePermission::class.':licbtn01');
    Route::post('/', [LicenseAdminController::class, 'store'])->middleware(EnsureLicensePermission::class.':licbtn02')
        ->withoutMiddleware(IdempotencyMiddleware::class);
    Route::post('/generation-code', [LicenseAdminController::class, 'requestGenerationCode'])
        ->middleware([EnsureLicensePermission::class.':licbtn02', 'throttle:5,10'])->withoutMiddleware(IdempotencyMiddleware::class);
    Route::post('/{id}/key-code', [LicenseAdminController::class, 'requestKeyCode'])->whereUuid('id')
        ->middleware([EnsureLicensePermission::class.':licbtn04', 'throttle:5,10'])->withoutMiddleware(IdempotencyMiddleware::class);
    Route::post('/{id}/reveal-key', [LicenseAdminController::class, 'revealKey'])->whereUuid('id')
        ->middleware(EnsureLicensePermission::class.':licbtn04')->withoutMiddleware(IdempotencyMiddleware::class);
    Route::put('/{id}', [LicenseAdminController::class, 'update'])->whereUuid('id')->middleware(EnsureLicensePermission::class.':licbtn03');
    Route::get('/{id}', [LicenseAdminController::class, 'show'])->whereUuid('id')->middleware(EnsureLicensePermission::class.':licbtn04');
    Route::delete('/{id}', [LicenseAdminController::class, 'destroy'])->whereUuid('id')->middleware(EnsureLicensePermission::class.':licbtn06');
    Route::delete('/{id}/devices/{activation}', [LicenseAdminController::class, 'unlink'])->whereUuid('id')->whereUuid('activation')->middleware(EnsureLicensePermission::class.':licbtn05');
});
