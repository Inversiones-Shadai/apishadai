<?php

use App\Http\Controllers\API\Farma\ExpiryController;
use App\Http\Middleware\EnsureExpiryPermission;
use Illuminate\Support\Facades\Route;

Route::prefix('vencimientos')->group(function (): void {
    Route::get('productos', [ExpiryController::class, 'products'])->middleware(EnsureExpiryPermission::class.':vnc,vncbtn01');
    Route::get('producto/{product}/lotes', [ExpiryController::class, 'lots'])->whereNumber('product')->middleware(EnsureExpiryPermission::class.':vnc,vncbtn02');
    Route::post('verificar', [ExpiryController::class, 'verify'])->middleware(EnsureExpiryPermission::class.':vnc,vncbtn03');
});
Route::prefix('reglas-proveedores')->group(function (): void {
    Route::get('listar', [ExpiryController::class, 'providers'])->middleware(EnsureExpiryPermission::class.':rdp,rdpbtn01');
    Route::get('{provider}', [ExpiryController::class, 'policy'])->whereNumber('provider')->middleware(EnsureExpiryPermission::class.':rdp,rdpbtn01');
    Route::put('{provider}', [ExpiryController::class, 'savePolicy'])->whereNumber('provider')->middleware(EnsureExpiryPermission::class.':rdp,rdpbtn02');
});
