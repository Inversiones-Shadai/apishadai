<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('auth/session/login', [AuthController::class, 'login']);

require __DIR__.'/custom/headquarters_api.php';

Route::group(
    ['middleware' => ['auth:api', 'idempotency']],
    function () {
        Route::get('selectors/{source}', \App\Http\Controllers\API\SelectorController::class)->where('source', '[a-z0-9-]+');
        // ==================================AUTH==================================
        require __DIR__.'/custom/AuthModule_api.php';

        // ==================================CONFIG=================================
        require __DIR__.'/custom/ConfigModule_api.php';
        require __DIR__.'/custom/desktop_licenses.php';
        require __DIR__.'/custom/desktop_releases.php';

        // ==================================PROMOCIONES=============================
        require __DIR__.'/custom/sorteos_api.php';

        // ==================================REPORTES=============================
        require __DIR__.'/custom/ReportesModule_api.php';

        // ==================================FARMACIA=============================
        require __DIR__.'/custom/farmaModulo_routes.php';

        require __DIR__.'/custom/pos/clientepos_routes.php';
        require __DIR__.'/custom/pos/management_routes.php';
        require __DIR__.'/custom/pos/purchases_routes.php';
        require __DIR__.'/custom/pos/discounts_routes.php';

        // ==================================UTILIDADES============================
        require __DIR__.'/custom/utils_api.php';
    }
);
