<?php

use App\Http\Controllers\API\DesktopUpdates\DesktopUpdateController;
use App\Http\Controllers\API\Headquarters\InventoryControlController;
use App\Http\Controllers\API\Headquarters\InventoryMatrixController;
use App\Http\Controllers\API\Headquarters\PosProductLotController;
use App\Http\Controllers\API\Headquarters\PosPurchaseController;
use App\Http\Controllers\API\Headquarters\PosTaskController;
use App\Http\Controllers\API\Headquarters\PosTransferController;
use App\Http\Controllers\API\Headquarters\RegistrationController;
use App\Http\Controllers\API\Licensing\DesktopLicenseController;
use App\Http\Controllers\WorkLunchController;
use App\Http\Middleware\DeviceSecurityMiddleware;
use App\Http\Middleware\HeadquartersPosSignatureMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('headquarters')->withoutMiddleware([DeviceSecurityMiddleware::class])->group(function (): void {
    Route::post('updates/{action}', [DesktopUpdateController::class, 'handle'])
        ->whereIn('action', ['check', 'download', 'apply', 'report'])->middleware(['throttle:120,1', HeadquartersPosSignatureMiddleware::class]);
    Route::post('licenses/{action}', [DesktopLicenseController::class, 'handle'])
        ->whereIn('action', ['activate', 'validate'])->middleware(HeadquartersPosSignatureMiddleware::class);
    Route::post('pos/tareas/{action}', [PosTaskController::class, 'handle'])
        ->whereIn('action', ['listar', 'estado'])->middleware(HeadquartersPosSignatureMiddleware::class);
    Route::post('pos/transferencias/{action}', [PosTransferController::class, 'handle'])
        ->whereIn('action', ['enviar', 'recibir', 'anular', 'confirmar-anulacion', 'listar'])
        ->middleware(HeadquartersPosSignatureMiddleware::class);
    Route::post('pos/productos/lotes-hijos/{action}', [PosProductLotController::class, 'handle'])
        ->whereIn('action', ['preparar', 'consultar'])->middleware(HeadquartersPosSignatureMiddleware::class);
    Route::post('pos/inventario/control/guardar', [InventoryControlController::class, 'handle'])->middleware(HeadquartersPosSignatureMiddleware::class);
    Route::post('pos/inventario/matriz/guardar', [InventoryMatrixController::class, 'handle'])->middleware(HeadquartersPosSignatureMiddleware::class);
    Route::post('pos/compras/{action}', [PosPurchaseController::class, 'handle'])
        ->whereIn('action', ['recibir', 'consultar', 'cancelar'])->middleware(HeadquartersPosSignatureMiddleware::class);
    Route::post('pos/asistencia/{action}', [WorkLunchController::class, 'headquarters'])
        ->whereIn('action', ['registrar', 'listar'])->middleware(HeadquartersPosSignatureMiddleware::class);
    Route::post('registro/solicitar', [RegistrationController::class, 'requestRegistration'])->middleware('throttle:headquarters-registration');
    Route::post('registro/confirmar', [RegistrationController::class, 'confirmRegistration'])->middleware('throttle:headquarters-confirmation');
});
