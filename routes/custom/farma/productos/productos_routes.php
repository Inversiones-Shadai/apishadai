<?php

use App\Http\Controllers\API\Farma\Productos\ProductController;
use App\Http\Middleware\EnsureProductParentPermission;
use Illuminate\Support\Facades\Route;

Route::prefix('producto')->group(
    function (): void {

        Route::get('/listar', [ProductController::class, 'index']);
        Route::get('/filter_productos', [ProductController::class, 'filterProductos']);
        Route::post('/crear', [ProductController::class, 'store']);
        Route::post('/crear-hijo/{id}', [ProductController::class, 'createChild'])->whereNumber('id')
            ->middleware(EnsureProductParentPermission::class.':prdbtn19');
        Route::get('/buscar/{id}', [ProductController::class, 'show'])->whereNumber('id');
        Route::put('/actualizar/{id}', [ProductController::class, 'update'])->whereNumber('id');
        Route::delete('/eliminar/{id}', [ProductController::class, 'destroy'])->whereNumber('id');
        Route::get('/imagen/{id}', [ProductController::class, 'getImage']);

        Route::get('/padres', [ProductController::class, 'padres'])->middleware(EnsureProductParentPermission::class);
        Route::get('/laboratorios', [ProductController::class, 'laboratorios']);
        Route::get('/filter_laboratorios', [ProductController::class, 'filterLaboratorios']);
        Route::get('/familias', [ProductController::class, 'familias']);
        Route::get('/filter_familias', [ProductController::class, 'filterFamilias']);
        Route::get('/unidades', [ProductController::class, 'unidades']);
        Route::get('/estados', [ProductController::class, 'estados']);
        Route::get('/categorias', [ProductController::class, 'categorias']);
        Route::get('/filter_unidades', [ProductController::class, 'filterUnidades']);

        // rutas para principios activos
        Route::get('/principios_activos', [ProductController::class, 'todosPrincipiosActivos']);
        Route::get('/filter_principios_activos', [ProductController::class, 'filterPrincipiosActivos']);
        Route::get('/principios_activos/{id}', [ProductController::class, 'principiosActivos'])->whereNumber('id');
        Route::post('/crear_principios_activos/{id}', [ProductController::class, 'agregarPrincipioActivo'])->whereNumber('id');
        Route::delete('/remove_principios_activos/{id}', [ProductController::class, 'eliminarPrincipioActivo'])
            ->whereNumber('id');
    }
);
