<?php

namespace App\Http\Controllers\API\Farma;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureInventoryMatrixPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryMatrixController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['stock_id' => ['nullable', 'integer', 'min:1'],
            'buscar' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        if (! empty($data['stock_id']) || trim($data['buscar'] ?? '') !== '') {
            $guard = app(EnsureInventoryMatrixPermission::class);
            abort_unless($guard->allows($request, 'mivbtn02'), 403);
        }
        $query = DB::table('stock_matriz_inventario as m')->join('stocks as s', 's.id', '=', 'm.stock_id')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->leftJoin('stock_matriz_existencias as e', fn ($join) => $join->on('e.stock_id', '=', 'm.stock_id')->on('e.producto_id', '=', 'm.producto_id'))
            ->where('e.reorden_activo', true)
            ->where(fn ($q) => $q->whereColumn('m.minimo', '>', 'e.existencia_total')
                ->orWhere(fn ($empty) => $empty->where('e.existencia_total', 0)->where('m.maximo', '>', 0)))
            ->select('m.id', 'm.stock_id', 'm.producto_id', 'm.minimo', 'm.maximo', 'm.revision', 'm.updated_at',
                's.descripcion as stock_nombre', 'p.codigo', 'p.descripcion', 'e.existencia_total', 'e.updated_at as existencias_actualizadas_at')
            ->selectRaw('CASE WHEN e.existencia_total IS NULL THEN NULL WHEN e.existencia_total = 0 THEN m.maximo WHEN m.minimo > e.existencia_total THEN m.minimo - e.existencia_total ELSE 0 END as punto_reorden');
        if (! empty($data['stock_id'])) {
            $query->where('m.stock_id', $data['stock_id']);
        }
        if (($search = trim($data['buscar'] ?? '')) !== '') {
            $query->where(fn ($q) => $q->where('p.descripcion', 'like', '%'.$search.'%')->orWhere('p.codigo', 'like', '%'.$search.'%'));
        }

        return $this->sendResponse($query->orderBy('s.descripcion')->orderBy('p.descripcion')->orderBy('m.id')->paginate(50), 'ok', 200, true);
    }

    public function stocks(): JsonResponse
    {
        return $this->sendResponse(DB::table('stocks')->orderBy('descripcion')->get(['id', 'descripcion']));
    }
}
