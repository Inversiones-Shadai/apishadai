<?php

use App\Http\Controllers\API\Farma\TransferController;
use App\Http\Middleware\EnsureTransferConversionPermission;
use Illuminate\Support\Facades\Route;

Route::prefix('transferencias')->group(function (): void {
    Route::get('/listar', [TransferController::class, 'index']);
    Route::get('/opciones', [TransferController::class, 'options']);
    Route::get('/lotes', [TransferController::class, 'lots']);
    Route::get('/conversion/{id}', [TransferController::class, 'conversionOptions'])->whereNumber('id')
        ->middleware(EnsureTransferConversionPermission::class);
    Route::post('/convertir', [TransferController::class, 'convertProduct'])
        ->middleware(EnsureTransferConversionPermission::class);
    Route::post('/enviar', [TransferController::class, 'send']);
    Route::post('/actualizar/{id}', [TransferController::class, 'update'])->whereNumber('id');
    Route::post('/recibir/{id}', [TransferController::class, 'receive'])->whereNumber('id');
    Route::get('/buscar/{id}', [TransferController::class, 'show'])->whereNumber('id');
});

require __DIR__.'/transfer_types_routes.php';
