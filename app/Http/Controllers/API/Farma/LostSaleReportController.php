<?php

namespace App\Http\Controllers\API\Farma;

use App\Http\Controllers\Controller;
use App\Services\Selectors\SelectorCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class LostSaleReportController extends Controller
{
    public function __invoke(Request $request, SelectorCatalog $selectors): JsonResponse
    {
        abort_unless($selectors->allows($request, ['permissions' => [['frm', 'vpd', 'vpdbtn01']]]), 403);
        $id = ['sometimes', 'string', 'regex:/\A[1-9][0-9]{0,19}\z/'];
        $filters = $request->validate([
            'stock_id' => $id,
            'producto_id' => $id,
            'usuario_pos_id' => $id,
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => ['nullable', 'date_format:Y-m-d', ...($request->filled('fecha_desde') ? ['after_or_equal:fecha_desde'] : [])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ], [
            'fecha_hasta.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
        ]);

        $sales = DB::table('pos_lost_sales');
        foreach (['stock_id', 'producto_id', 'usuario_pos_id'] as $filter) {
            if (isset($filters[$filter])) {
                $sales->where($filter, $filters[$filter]);
            }
        }
        if (isset($filters['fecha_desde'])) {
            $start = Carbon::parse($filters['fecha_desde'], 'America/Tegucigalpa')->startOfDay()->utc();
            $sales->where('reported_at', '>=', $start->toDateTimeString());
        }
        if (isset($filters['fecha_hasta'])) {
            $end = Carbon::parse($filters['fecha_hasta'], 'America/Tegucigalpa')->addDay()->startOfDay()->utc();
            $sales->where('reported_at', '<', $end->toDateTimeString());
        }

        $report = $sales->select('producto_id', 'stock_id', 'usuario_pos_id')
            ->selectRaw('COUNT(*) AS registros, MAX(reported_at) AS ultimo_registro')
            ->selectRaw('MAX(producto_codigo) AS codigo_historico, MAX(producto) AS producto_historico')
            ->groupBy('producto_id', 'stock_id', 'usuario_pos_id');
        $page = DB::query()->fromSub($report, 'ventas')
            ->leftJoin('productos as p', 'p.id', '=', 'ventas.producto_id')
            ->leftJoin('stocks as s', 's.id', '=', 'ventas.stock_id')
            ->leftJoin('usuariospos as u', 'u.id', '=', 'ventas.usuario_pos_id')
            ->select('ventas.producto_id', 'ventas.stock_id', 'ventas.usuario_pos_id', 'ventas.registros', 'ventas.ultimo_registro')
            ->addSelect('s.descripcion as stock', 'u.nombre as usuario')
            ->selectRaw('COALESCE(p.codigo, ventas.codigo_historico) AS codigo, COALESCE(p.descripcion, ventas.producto_historico) AS producto')
            ->orderByDesc('registros')->orderBy('ventas.producto_id')->orderBy('ventas.stock_id')->orderBy('ventas.usuario_pos_id')
            ->paginate(25);

        return $this->sendResponse($page, 'Ventas perdidas', 200, true);
    }
}
