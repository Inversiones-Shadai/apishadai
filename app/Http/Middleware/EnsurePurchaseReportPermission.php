<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsurePurchaseReportPermission
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        $userId = $request->user()?->getAuthIdentifier();
        $allowed = $userId !== null && $request->header('Action-Btn') === $action
            && DB::table('permisos')
                ->join('modulos', 'permisos.modulo', '=', 'modulos.id')
                ->join('vistas', 'permisos.vista', '=', 'vistas.id')
                ->join('actionsvistas', 'permisos.actionvista', '=', 'actionsvistas.id')
                ->join('modulo_estados', 'modulos.estado', '=', 'modulo_estados.id')
                ->join('vista_estados', 'vistas.estado', '=', 'vista_estados.id')
                ->where('permisos.usuario', $userId)
                ->where('modulos.codigo', 'frm')
                ->where('vistas.codigo', 'rcp')
                ->where('modulo_estados.descripcion', 'ACTIVO')
                ->where('vista_estados.descripcion', 'ACTIVO')
                ->whereColumn('vistas.modulo', 'modulos.id')
                ->whereColumn('actionsvistas.vista', 'vistas.id')
                ->where('actionsvistas.codigo', $action)
                ->where(fn ($query) => $query->whereNull('permisos.lifetime')->orWhere('permisos.lifetime', '>=', now()))
                ->exists();

        if (! $allowed) {
            return response()->json(['message' => 'No tienes permiso para consultar el reporte de compras.', 'code' => 403, 'data' => null], 403);
        }

        return $next($request);
    }
}
