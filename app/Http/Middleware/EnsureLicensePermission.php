<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureLicensePermission
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        $allowed = $request->header('Action-Btn') === $action && $request->user()?->getAuthIdentifier() !== null
            && DB::table('permisos')
                ->join('modulos', 'permisos.modulo', '=', 'modulos.id')
                ->join('vistas', 'permisos.vista', '=', 'vistas.id')
                ->join('actionsvistas', 'permisos.actionvista', '=', 'actionsvistas.id')
                ->join('modulo_estados', 'modulos.estado', '=', 'modulo_estados.id')
                ->join('vista_estados', 'vistas.estado', '=', 'vista_estados.id')
                ->where('permisos.usuario', $request->user()->getAuthIdentifier())
                ->where('modulos.codigo', 'cfg')->where('vistas.codigo', 'lic')
                ->where('modulo_estados.descripcion', 'ACTIVO')->where('vista_estados.descripcion', 'ACTIVO')
                ->whereColumn('vistas.modulo', 'modulos.id')->whereColumn('actionsvistas.vista', 'vistas.id')
                ->where('actionsvistas.codigo', $action)
                ->where(fn ($query) => $query->whereNull('permisos.lifetime')->orWhere('permisos.lifetime', '>=', now()))->exists();
        abort_unless($allowed, 403, 'No tienes permiso para administrar licencias.');

        return $next($request);
    }
}
