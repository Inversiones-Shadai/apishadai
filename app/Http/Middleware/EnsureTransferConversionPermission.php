<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureTransferConversionPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = $request->user() !== null && $request->header('Action-Btn') === 'trfbtn04'
            && DB::table('permisos')
                ->join('modulos', 'permisos.modulo', '=', 'modulos.id')
                ->join('vistas', 'permisos.vista', '=', 'vistas.id')
                ->join('actionsvistas', 'permisos.actionvista', '=', 'actionsvistas.id')
                ->join('modulo_estados', 'modulos.estado', '=', 'modulo_estados.id')
                ->join('vista_estados', 'vistas.estado', '=', 'vista_estados.id')
                ->where('permisos.usuario', $request->user()->getAuthIdentifier())
                ->where('modulos.codigo', 'frm')->where('vistas.codigo', 'trf')
                ->where('actionsvistas.codigo', 'trfbtn04')
                ->where('modulo_estados.descripcion', 'ACTIVO')->where('vista_estados.descripcion', 'ACTIVO')
                ->whereColumn('vistas.modulo', 'modulos.id')->whereColumn('actionsvistas.vista', 'vistas.id')
                ->where(fn ($query) => $query->whereNull('permisos.lifetime')->orWhere('permisos.lifetime', '>=', now()))
                ->exists();

        abort_unless($allowed, 403, 'No tienes permiso para convertir productos en transferencias.');

        return $next($request);
    }
}
