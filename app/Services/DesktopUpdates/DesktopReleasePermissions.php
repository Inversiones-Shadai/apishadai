<?php

namespace App\Services\DesktopUpdates;

use Illuminate\Support\Facades\DB;

class DesktopReleasePermissions
{
    public function allows(int $actor, string $action): bool
    {
        return $actor > 0 && in_array($action, array_map(fn ($n) => 'updbtn0'.$n, range(1, 8)), true)
            && DB::table('permisos')->join('modulos', 'permisos.modulo', '=', 'modulos.id')
                ->join('vistas', 'permisos.vista', '=', 'vistas.id')->join('actionsvistas', 'permisos.actionvista', '=', 'actionsvistas.id')
                ->join('modulo_estados', 'modulos.estado', '=', 'modulo_estados.id')->join('vista_estados', 'vistas.estado', '=', 'vista_estados.id')
                ->where('permisos.usuario', $actor)->where('modulos.codigo', 'cfg')->where('vistas.codigo', 'upd')
                ->where('modulo_estados.descripcion', 'ACTIVO')->where('vista_estados.descripcion', 'ACTIVO')
                ->whereColumn('vistas.modulo', 'modulos.id')->whereColumn('actionsvistas.vista', 'vistas.id')
                ->where('actionsvistas.codigo', $action)
                ->where(fn ($query) => $query->whereNull('permisos.lifetime')->orWhere('permisos.lifetime', '>=', now()->timestamp))->exists();
    }
}
