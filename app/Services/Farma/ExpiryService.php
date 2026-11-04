<?php

namespace App\Services\Farma;

use App\Services\Images\ImageStorage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

class ExpiryService
{
    public const TIMEZONE = 'America/Tegucigalpa';

    public function products(string $search, ?int $months, int $perPage): LengthAwarePaginator
    {
        $lots = DB::table('lotes')->select('producto')->selectRaw('COUNT(*) as lotes_count, MIN(fecha_exp) as proxima_expiracion');
        if ($months !== null) {
            $lots->where('fecha_exp', '<=', CarbonImmutable::today(self::TIMEZONE)->addMonthsNoOverflow($months)->toDateString());
        }

        return DB::table('productos as p')->joinSub($lots->groupBy('producto'), 'l', 'p.id', '=', 'l.producto')
            ->select('p.id as producto_id', 'p.codigo', 'p.codigobar', 'p.descripcion', 'l.lotes_count', 'l.proxima_expiracion')
            ->selectRaw('p.imagen IS NOT NULL as tiene_imagen, CASE WHEN LENGTH(p.imagen) = 36 THEN p.imagen ELSE NULL END as imagen')
            ->when(trim($search) !== '', fn (Builder $query) => $query->where(fn (Builder $filter) => $filter
                ->whereRaw('LOWER(p.codigo) LIKE ?', ['%'.mb_strtolower(trim($search)).'%'])
                ->orWhereRaw('LOWER(p.codigobar) LIKE ?', ['%'.mb_strtolower(trim($search)).'%'])
                ->orWhereRaw('LOWER(p.descripcion) LIKE ?', ['%'.mb_strtolower(trim($search)).'%'])))
            ->orderBy('l.proxima_expiracion')->orderBy('p.id')->paginate($perPage)
            ->through(function ($row) {
                $row->imagen_url = app(ImageStorage::class)->url($row->imagen, 'prdbtn06');
                unset($row->imagen);

                return $row;
            });
    }

    public function lots(int $product, int $perPage, ?int $months = null): LengthAwarePaginator
    {
        $query = $this->lotQuery()->where('l.producto', $product);
        if ($months !== null) {
            $query->where('l.fecha_exp', '<=', CarbonImmutable::today(self::TIMEZONE)->addMonthsNoOverflow($months)->toDateString());
        }
        $page = $query->orderBy('l.fecha_exp')->orderBy('l.id')->paginate($perPage);
        $page->setCollection($page->getCollection()->map(fn (stdClass $row): array => $this->lotData($row)));

        return $page;
    }

    public function providers(string $search, int $perPage): LengthAwarePaginator
    {
        return DB::table('proveedores as p')->leftJoin('proveedor_reglas_devolucion as r', 'r.proveedor_id', '=', 'p.id')
            ->select('p.id', 'p.nombre', 'r.meses_anticipacion', 'r.tipo_devolucion', 'r.revision', 'r.updated_at')
            ->when(trim($search) !== '', fn (Builder $query) => $query->whereRaw('LOWER(p.nombre) LIKE ?', ['%'.mb_strtolower(trim($search)).'%']))
            ->orderBy('p.nombre')->orderBy('p.id')->paginate($perPage);
    }

    /** @return array<string, mixed>|null */
    public function policy(int $provider): ?array
    {
        $supplier = DB::table('proveedores')->select('id', 'nombre')->find($provider);
        if ($supplier === null) {
            return null;
        }

        return ['proveedor' => (array) $supplier, 'regla' => $this->ruleData(DB::table('proveedor_reglas_devolucion')->where('proveedor_id', $provider)->first())];
    }

    /** @param array<string, mixed> $data */
    public function savePolicy(int $provider, array $data, int $user): string
    {
        return DB::transaction(function () use ($provider, $data, $user): string {
            if (DB::table('proveedores')->where('id', $provider)->lockForUpdate()->first() === null) {
                return 'missing';
            }
            $current = DB::table('proveedor_reglas_devolucion')->where('proveedor_id', $provider)->lockForUpdate()->first();
            if ((int) ($current?->revision ?? 0) !== (int) $data['revision']) {
                return 'conflict';
            }
            $values = [
                'meses_anticipacion' => (int) $data['meses_anticipacion'],
                'acepta_hijos' => (bool) $data['acepta_hijos'], 'acepta_empezados' => (bool) $data['acepta_hijos'],
                // Retained legacy column; bonuses are always excluded from returns.
                'acepta_escala' => false, 'tipo_devolucion' => $data['tipo_devolucion'],
                'notas' => $data['notas'] ?? null, 'revision' => (int) $data['revision'] + 1,
                'actualizado_por' => $user, 'updated_at' => now(),
            ];
            if ($current === null) {
                DB::table('proveedor_reglas_devolucion')->insert(['proveedor_id' => $provider, 'created_at' => now(), ...$values]);
            } else {
                DB::table('proveedor_reglas_devolucion')->where('id', $current->id)->update($values);
            }

            return 'saved';
        }, 3);
    }

    /** @param array<int, array{id: int}> $items
     * @return array<string, mixed>
     */
    public function verify(array $items): array
    {
        // Read supplier, purchase and rules afresh; never trust browser copies.
        $rows = $this->lotQuery()->whereIn('l.id', array_column($items, 'id'))->get()->keyBy('id');
        $rules = DB::table('proveedor_reglas_devolucion')->whereIn('proveedor_id', $rows->pluck('proveedor_id')->filter()->unique())->get()->keyBy('proveedor_id');
        $today = CarbonImmutable::today(self::TIMEZONE);
        $results = [];
        foreach ($items as $item) {
            $row = $rows->get($item['id']);
            if ($row === null) {
                $results[] = ['id' => (int) $item['id'], 'estado' => 'pendiente', 'motivos' => ['El lote ya no existe. Vuelve a consultar el producto.']];

                continue;
            }
            $rule = $this->ruleData($rules->get($row->proveedor_id));
            $blocked = [];
            $unknown = [];
            if ($row->es_bonificacion) {
                $blocked[] = 'El lote es bonificación y no acepta devolución.';
            }
            if ($row->codigo === null) {
                $unknown[] = 'No se encuentra el producto del lote. Revisa su registro.';
            }
            $deadline = null;
            $expiry = $row->fecha_exp ? CarbonImmutable::parse($row->fecha_exp, self::TIMEZONE)->startOfDay() : null;
            if ($expiry === null) {
                $unknown[] = 'El lote no tiene fecha de vencimiento.';
            } elseif ($today->greaterThanOrEqualTo($expiry)) {
                $blocked[] = 'El producto está vencido.';
            }
            if ($row->proveedor_id === null) {
                $unknown[] = 'No se puede identificar el proveedor desde la compra del lote.';
            } elseif ($rule === null) {
                $unknown[] = 'El proveedor no tiene reglas de devolución configuradas.';
            }
            if ($rule !== null) {
                if ($rule['tipo_devolucion'] === 'no_acepta') {
                    $blocked[] = 'El proveedor no acepta devoluciones.';
                }
                if ($expiry !== null) {
                    $deadline = $expiry->subMonthsNoOverflow($rule['meses_anticipacion']);
                    if ($today->greaterThan($deadline)) {
                        $blocked[] = 'Se superó la fecha límite de envío ('.$deadline->format('d/m/Y').').';
                    }
                }
                if ($row->producto_padre_id !== null && ! $rule['acepta_hijos']) {
                    $blocked[] = 'El proveedor no acepta productos hijos / empezados.';
                }
            }
            $status = match (true) {
                $blocked !== [] => 'no_apto',
                $unknown !== [] => 'pendiente',
                ($rule['tipo_devolucion'] ?? '') === 'autorizacion' => 'requiere_autorizacion',
                default => 'apto',
            };
            $reasons = [...$blocked, ...$unknown];
            if ($status === 'apto') {
                $reasons[] = 'Cumple las reglas para devolución directa.';
            } elseif ($status === 'requiere_autorizacion') {
                $reasons[] = 'Cumple las condiciones, pero requiere autorización previa del proveedor.';
            }
            $results[] = [
                ...$this->lotData($row),
                'estado' => $status, 'motivos' => $reasons, 'fecha_limite_envio' => $deadline?->toDateString(), 'regla' => $rule,
            ];
        }

        return ['verificado_el' => CarbonImmutable::now(self::TIMEZONE)->toIso8601String(), 'resultados' => $results];
    }

    private function lotQuery(): Builder
    {
        return DB::table('lotes as l')->leftJoin('productos as p', 'p.id', '=', 'l.producto')
            ->leftJoin('compras as c', 'c.id', '=', 'l.compra')->leftJoin('proveedores as s', 's.id', '=', 'c.proveedor')
            ->select('l.id', 'l.producto as producto_id', 'l.lote', 'l.fecha_exp', 'l.cantidad', 'l.es_bonificacion', 'p.codigo', 'p.descripcion', 'p.producto_padre_id',
                'c.id as compra_id', 's.id as proveedor_id', 's.nombre as proveedor_nombre');
    }

    /** @return array<string, mixed> */
    private function lotData(stdClass $row): array
    {
        return [
            'id' => (int) $row->id, 'producto_id' => (int) $row->producto_id, 'lote' => $row->lote,
            'fecha_expiracion' => $row->fecha_exp, 'cantidad_entrada' => $row->cantidad === null ? null : (float) $row->cantidad,
            'codigo' => $row->codigo, 'descripcion' => $row->descripcion, 'es_hijo' => $row->producto_padre_id !== null,
            'compra_id' => $row->compra_id === null ? null : (int) $row->compra_id,
            'es_bonificacion' => (bool) $row->es_bonificacion,
            'proveedor_id' => $row->proveedor_id === null ? null : (int) $row->proveedor_id, 'proveedor_nombre' => $row->proveedor_nombre,
        ];
    }

    /** @return array<string, mixed>|null */
    private function ruleData(?stdClass $row): ?array
    {
        return $row === null ? null : [
            'meses_anticipacion' => (int) $row->meses_anticipacion,
            'acepta_hijos' => (bool) $row->acepta_hijos,
            'tipo_devolucion' => $row->tipo_devolucion,
            'notas' => $row->notas, 'revision' => (int) $row->revision, 'updated_at' => $row->updated_at,
        ];
    }
}
