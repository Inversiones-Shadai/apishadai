<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureSaleAlertPermission
{
    public function handle(Request $request, Closure $next, string $action): Response
    {
        $user = $request->user()?->getAuthIdentifier();
        abort_unless($user !== null && $request->header('Action-Btn') === $action
            && DB::table('permisos as p')
                ->join('modulos as m', 'm.id', '=', 'p.modulo')
                ->join('vistas as v', fn ($join) => $join->on('v.id', '=', 'p.vista')->on('v.modulo', '=', 'm.id'))
                ->join('actionsvistas as a', fn ($join) => $join->on('a.id', '=', 'p.actionvista')->on('a.vista', '=', 'v.id'))
                ->join('modulo_estados as me', 'me.id', '=', 'm.estado')
                ->join('vista_estados as ve', 've.id', '=', 'v.estado')
                ->where('p.usuario', $user)->where('m.codigo', 'frm')->where('v.codigo', 'avt')
                ->where('me.descripcion', 'ACTIVO')->where('ve.descripcion', 'ACTIVO')->where('a.codigo', $action)
                ->where(fn ($query) => $query->whereNull('p.lifetime')->orWhere('p.lifetime', '>=', now()))
                ->exists(), 403, 'No tienes permiso para gestionar alertas de venta.');

        return $next($request);
    }
}
