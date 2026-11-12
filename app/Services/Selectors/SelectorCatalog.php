<?php

namespace App\Services\Selectors;

use App\Http\Resources\Farma\DistributionLotResource;
use App\Models\Farma\Lote;
use App\Models\Farma\ProdCategoria;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class SelectorCatalog
{
    public function definition(string $source): array
    {
        return config('selector_sources.'.$source) ?? abort(404);
    }

    public function allows(Request $request, array $definition): bool
    {
        foreach ($definition['requires'] ?? [] as $codes) {
            if (! $this->hasPermission($request, ...$codes)) {
                return false;
            }
        }
        foreach ($definition['permissions'] as [$module, $view, $action]) {
            if ($request->header('Action-Btn') !== $action || ! $request->user()) {
                continue;
            }
            if ($this->hasPermission($request, $module, $view, $action)) {
                return true;
            }
        }

        return false;
    }

    private function hasPermission(Request $request, string $module, string $view, string $action): bool
    {
        return $request->user() !== null && DB::table('permisos as p')->join('modulos as m', 'm.id', '=', 'p.modulo')
            ->join('vistas as v', 'v.id', '=', 'p.vista')->join('actionsvistas as a', 'a.id', '=', 'p.actionvista')
            ->join('modulo_estados as ms', 'ms.id', '=', 'm.estado')->join('vista_estados as vs', 'vs.id', '=', 'v.estado')
            ->where('p.usuario', $request->user()->getAuthIdentifier())->where('m.codigo', $module)
            ->where('v.codigo', $view)->where('a.codigo', $action)->whereColumn('v.modulo', 'm.id')
            ->whereColumn('a.vista', 'v.id')->where('ms.descripcion', 'ACTIVO')->where('vs.descripcion', 'ACTIVO')
            ->where(fn ($q) => $q->whereNull('p.lifetime')->orWhere('p.lifetime', '>=', now()))->exists();
    }

    public function page(array $definition, array $input): LengthAwarePaginator
    {
        $id = $definition['id'] ?? 'id';
        $queryId = $id;
        $query = DB::table($definition['table'])->select(array_unique([$id, ...$definition['columns']]));
        if (($definition['scope'] ?? null) === 'purchases') {
            $queryId = 'compras.id';
            $query->leftJoin('proveedores', 'proveedores.id', '=', 'compras.proveedor')
                ->select('compras.id', 'compras.nro', 'compras.total', 'compras.proveedor', 'proveedores.nombre as proveedor_nombre')
                ->where('compras.estado', '!=', 'borrador')->selectSub(DB::table('compra_transc')->selectRaw('COALESCE(SUM(valor), 0)')->whereColumn('compra', 'compras.id'), 'total_aplicado');
        }
        if (($definition['scope'] ?? null) === 'distribution-lots') {
            $query = Lote::with(['distribucionDetalle', 'compraDetalle:id,nro'])
                ->withExists('distribucionDetalle')
                ->orderBy('distribucion_detalle_exists');
        }
        if (($definition['scope'] ?? null) === 'conversion-children') {
            $lotId = $input['filters']['parent'] ?? null;
            $parent = DB::table('lotes')->join('productos', 'productos.id', '=', 'lotes.producto')
                ->join('prod_unidades', 'prod_unidades.id', '=', 'productos.unidad')->where('lotes.id', $lotId)
                ->first(['productos.id', 'prod_unidades.cantidad_c', 'prod_unidades.cantidad_v']);
            if (! $parent || $parent->cantidad_c < 1 || $parent->cantidad_v < 1 || $parent->cantidad_c % $parent->cantidad_v !== 0) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('producto_padre_id', $parent->id)->whereIn('unidad', DB::table('prod_unidades')->select('id')
                    ->where('cantidad_c', '>', 0)->where('cantidad_v', '>', 0)->whereRaw('cantidad_c % cantidad_v = 0')
                    ->whereRaw('? % cantidad_v = 0', [$parent->cantidad_c]));
            }
        }
        if (in_array($definition['scope'] ?? null, ['active-stocks', 'transfer-stocks'], true)) {
            $query->whereIn('estado', DB::table('stock_estado')->select('id')->where('descripcion', 'ACTIVO'));
        }
        if (($definition['scope'] ?? null) === 'transfer-stocks') {
            $query->where('id', '!=', $input['device_stock']);
        }
        if (($definition['scope'] ?? null) === 'active-pos-users') {
            $query->where('activo', true)->whereIn('rol_id', DB::table('rolespos')->select('id')->where('activo', true));
        }
        if (($definition['scope'] ?? null) === 'active-role-options' && ! isset($input['selected'])) {
            $query->where('activo', true);
        }
        foreach ($definition['where'] ?? [] as $column => $value) {
            $query->where($column, $value);
        }
        foreach ($definition['parents'] ?? [] as $parameter => $column) {
            if (empty($input['filters'][$parameter])) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where($column, $input['filters'][$parameter]);
            }
        }
        if (isset($input['selected'])) {
            $query->where($queryId, $input['selected']);
        } elseif (($search = trim($input['search'] ?? '')) !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(function ($query) use ($definition, $pattern): void {
                foreach ($definition['search'] ?? $definition['labels'] as $column) {
                    $wrapped = $query->getGrammar()->wrap($column);
                    $query->orWhereRaw("LOWER($wrapped) LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        return $query->orderBy($queryId, 'desc')->paginate(10, ['*'], 'page', $input['page'] ?? 1)
            ->through(function ($row) use ($definition, $id): array {
                $record = ($definition['scope'] ?? null) === 'distribution-lots'
                    ? DistributionLotResource::make($row)->resolve() : (array) $row;
                $record['id'] = $record[$id];
                if ($definition['table'] === 'prod_categorias') {
                    $record['requires_family'] = ProdCategoria::requiresFamily($record['nombre']);
                }
                if (($definition['scope'] ?? null) === 'purchases') {
                    $record['total_aplicado'] = (float) $record['total_aplicado'];
                    $record['saldo'] = max(0, (float) $record['total'] - $record['total_aplicado']);
                }
                $label = implode(' · ', array_filter(array_map(fn ($field) => (string) ($record[$field] ?? ''), $definition['labels']), fn ($value) => $value !== ''));
                if (($definition['scope'] ?? null) === 'purchases') {
                    $label .= ' · '.($record['proveedor_nombre'] ?? '').' · saldo L '.number_format($record['saldo'], 2);
                }
                if ($definition['table'] === 'prod_unidades') {
                    $label = $record['abreviatura_c'].' × '.$record['cantidad_c'].' → '.$record['abreviatura_v'].' × '.$record['cantidad_v'];
                }

                return ['id' => (string) $record['id'], 'label' => $label, 'record' => $record + ['label' => $label]];
            });
    }
}
