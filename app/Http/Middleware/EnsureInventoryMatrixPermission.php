<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureInventoryMatrixPermission
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        $allowed = $request->header('Action-Btn') === $action && $this->allows($request, $action);

        if (! $allowed) {
            return response()->json(['message' => 'No tienes permiso para realizar esta acción sobre la matriz de inventario.', 'code' => 403, 'data' => null], 403);
        }

        return $next($request);
    }

    public function allows(Request $request, string $action): bool
    {
        $userId = $request->user()?->getAuthIdentifier();
        $allowed = $userId !== null
            && DB::table('permisos')
                ->join('modulos', 'permisos.modulo', '=', 'modulos.id')
                ->join('vistas', 'permisos.vista', '=', 'vistas.id')
                ->join('actionsvistas', 'permisos.actionvista', '=', 'actionsvistas.id')
                ->join('modulo_estados', 'modulos.estado', '=', 'modulo_estados.id')
                ->join('vista_estados', 'vistas.estado', '=', 'vista_estados.id')
                ->where('permisos.usuario', $userId)
                ->where('modulos.codigo', 'frm')
                ->where('vistas.codigo', 'miv')
                ->where('modulo_estados.descripcion', 'ACTIVO')
                ->where('vista_estados.descripcion', 'ACTIVO')
                ->whereColumn('vistas.modulo', 'modulos.id')
                ->whereColumn('actionsvistas.vista', 'vistas.id')
                ->where('actionsvistas.codigo', $action)
                ->where(fn ($query) => $query->whereNull('permisos.lifetime')->orWhere('permisos.lifetime', '>=', now()))
                ->exists();

        return $allowed;
    }
}
