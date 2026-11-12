<?php

use App\Http\Controllers\API\Farma\InventoryMatrixController;
use App\Http\Controllers\API\Farma\LostSaleReportController;
use App\Http\Controllers\API\Farma\TaskController;
use App\Http\Controllers\API\Farma\SaleAlertController;
use App\Http\Middleware\EnsureSaleAlertPermission;
use App\Http\Middleware\EnsureInventoryMatrixPermission;
use App\Http\Middleware\EnsureTaskPermission;
use Illuminate\Support\Facades\Route;

Route::prefix('farma/alertas-venta')->group(function (): void {
    Route::get('/', [SaleAlertController::class, 'index'])->middleware(EnsureSaleAlertPermission::class.':avtbtn01');
    Route::post('/', [SaleAlertController::class, 'store'])->middleware(EnsureSaleAlertPermission::class.':avtbtn02');
    Route::put('/{id}', [SaleAlertController::class, 'update'])->whereNumber('id')->middleware(EnsureSaleAlertPermission::class.':avtbtn02');
    Route::delete('/{id}', [SaleAlertController::class, 'destroy'])->whereNumber('id')->middleware(EnsureSaleAlertPermission::class.':avtbtn02');
});

Route::get('farma/reportes/ventas-perdidas', LostSaleReportController::class);

Route::prefix('farma/tareas')->group(function (): void {
    $controller = TaskController::class;
    $guard = EnsureTaskPermission::class;
    Route::get('listar', [$controller, 'index'])->middleware($guard.':tarbtn01');
    Route::get('opciones', [$controller, 'options'])->middleware($guard.':tarbtn02|tarbtn04|tarbtn06');
    Route::get('buscar/{id}', [$controller, 'show'])->whereNumber('id')->middleware($guard.':tarbtn03|tarbtn04');
    Route::post('crear', [$controller, 'create'])->middleware($guard.':tarbtn02');
    Route::post('modificar/{id}', [$controller, 'update'])->whereNumber('id')->middleware($guard.':tarbtn04');
    Route::post('cancelar/{id}', [$controller, 'cancel'])->whereNumber('id')->middleware($guard.':tarbtn05');
});

Route::prefix('farma')->group(
    function () {
        // ==================================PROVEEDORES=============================
        require __DIR__.'/../custom/farma/proveedores_routes.php';

        // ==================================LABORATORIOS=============================
        require __DIR__.'/../custom/farma/laboratorios_routes.php';

        // ==================================PRODUCTOS================================
        require __DIR__.'/../custom/farma/productos_routes.php';

        // ==================================COMPRAS==================================
        require __DIR__.'/../custom/farma/compras_routes.php';

        // ==================================LOTES====================================
        require __DIR__.'/../custom/farma/lotes_routes.php';

        require __DIR__.'/../custom/farma/vencimientos_routes.php';

        // ==================================DISTRIBUCION=============================
        require __DIR__.'/../custom/farma/distribucion_routes.php';

        // ==================================TRANSFERENCIAS===========================
        require __DIR__.'/../custom/farma/transferencias_routes.php';
    }
);

Route::prefix('farma/stocks/matriz-inventario')->group(function (): void {
    Route::get('listar', [InventoryMatrixController::class, 'index'])
        ->middleware(EnsureInventoryMatrixPermission::class.':mivbtn01');
    Route::get('stocks', [InventoryMatrixController::class, 'stocks'])
        ->middleware(EnsureInventoryMatrixPermission::class.':mivbtn01');
});
