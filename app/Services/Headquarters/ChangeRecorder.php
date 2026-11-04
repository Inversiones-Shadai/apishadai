<?php

namespace App\Services\Headquarters;

use App\Jobs\PushHeadquartersOutboxJob;
use App\Models\Device;
use App\Models\Farma\Concentracion;
use App\Models\Farma\Distribucion;
use App\Models\Farma\FamAdministracion;
use App\Models\Farma\Familia;
use App\Models\Farma\FamPresentacion;
use App\Models\Farma\Lote;
use App\Models\Farma\PrincipalActivo;
use App\Models\Farma\ProdCategoria;
use App\Models\Farma\ProdEstado;
use App\Models\Farma\Producto;
use App\Models\Farma\ProdUnidad;
use App\Models\Farma\Transferencia;
use App\Models\Farma\TransferenciaEstado;
use App\Models\Farma\TransferenciaTipo;
use App\Models\Laboratorio;
use App\Models\Pos\BotonPos;
use App\Models\Pos\CatalogoPos;
use App\Models\Pos\Clientepos;
use App\Models\Pos\ModuloPos;
use App\Models\Pos\PermisoPos;
use App\Models\Pos\PermisoUsuarioPos;
use App\Models\Pos\RolPos;
use App\Models\Pos\UsuarioPos;
use App\Models\Pos\VistaPos;
use App\Models\Proveedores;
use App\Models\StockEstado;
use App\Models\Stocks;
use App\Models\SyncChange;
use App\Services\Images\ImageStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

class ChangeRecorder
{
    private int $suppressed = 0;

    public const MODELS = [
        'devices' => Device::class,
        'modulospos' => ModuloPos::class,
        'vistaspos' => VistaPos::class,
        'botonespos' => BotonPos::class,
        'permisospos' => PermisoPos::class,
        'permisosusuariospos' => PermisoUsuarioPos::class,
        'rolespos' => RolPos::class,
        'usuariospos' => UsuarioPos::class,
        'catalogospos' => CatalogoPos::class,
        'prod_categorias' => ProdCategoria::class,
        'prod_unidades' => ProdUnidad::class,
        'prod_estado' => ProdEstado::class,
        'concentraciones' => Concentracion::class,
        'fam_presentacion' => FamPresentacion::class,
        'fam_administracion' => FamAdministracion::class,
        'familia' => Familia::class,
        'laboratorios' => Laboratorio::class,
        'proveedores' => Proveedores::class,
        'stock_estado' => StockEstado::class,
        'stocks' => Stocks::class,
        'principal_activos' => PrincipalActivo::class,
        'productos' => Producto::class,
        'lotes' => Lote::class,
        'clientepos' => Clientepos::class,
        'distribucion' => Distribucion::class,
        'transferencias_tipo' => TransferenciaTipo::class,
        'transferencia_estado' => TransferenciaEstado::class,
        'transferencias' => Transferencia::class,
    ];

    public function enabled(): bool
    {
        return (bool) config('headquarters.publishing_enabled') && $this->suppressed === 0;
    }

    public function withoutRecording(callable $callback): mixed
    {
        $this->suppressed++;
        try {
            return $callback();
        } finally {
            $this->suppressed--;
        }
    }

    public function record(Model $model, string $action): void
    {
        if (! $this->enabled()) {
            return;
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Business data and its outbox must share one transaction.');
        }
        $entity = $model->getTable();
        $id = (int) $model->getKey();
        DB::table('sync_entity_versions')->insertOrIgnore(['entity' => $entity, 'entity_id' => $id, 'version' => 0]);
        $version = DB::table('sync_entity_versions')->where('entity', $entity)->where('entity_id', $id)->lockForUpdate()->first();
        $next = (int) $version->version + 1;
        if ($next <= 0) {
            throw new LogicException('Synchronization version overflow.');
        }
        $previousStocks = json_decode($version->stock_ids ?? '[]', true) ?? [];
        $stockIds = [];
        $removedStocks = [];
        $payload = null;
        if ($action !== 'DELETE') {
            $fresh = $model->newQuery()->findOrFail($id);
            $payload = array_intersect_key($fresh->attributesToArray(), array_flip(EntityContract::FIELDS[$entity]));
            if ($entity === 'lotes') {
                $payload = $this->lotData($fresh);
            }
            if (in_array('imagen_base64', EntityContract::FIELDS[$entity], true)) {
                $image = $fresh->getRawOriginal('imagen');
                $image = app(ImageStorage::class)->base64($image);
                $payload['imagen_base64'] = is_string($image) && $image !== '' ? base64_encode($image) : null;
            }
            if (in_array($entity, ['catalogospos'], true)) {
                $global = $fresh->tipo === 'DESCUENTO' && $fresh->stock_id === null;
                $stockIds = $global ? [EntityContract::GLOBAL_DISCOUNT_SCOPE] : [(int) $fresh->stock_id];
                $removedStocks = $global ? [] : array_values(array_diff($previousStocks, $stockIds));
            }
            if ($entity === 'productos') {
                $payload['principios_activos'] = $fresh->principiosActivos()->orderBy('principal_activos.id')->pluck('principal_activos.id')->map(fn ($id): int => (int) $id)->all();
            }
            if ($entity === 'catalogospos') {
                $payload['lote_datos'] = $fresh->tipo === 'PRECIO' ? $this->lotData(Lote::find($fresh->datos['lote_id'])) : null;
            }
            if ($entity === 'usuariospos') {
                $payload['nombre'] = base64_encode($fresh->nombre);
                $payload['vendedor'] = $fresh->vendedor;
                $payload['credenciales'] = $fresh->credenciales;
            }
            if ($entity === 'devices') {
                $payload = ['id' => $id, 'hq_client_uuid' => $fresh->hq_client_uuid, 'stock_id' => (int) $fresh->stock,
                    'active' => mb_strtoupper((string) $fresh->Estado?->descripcion) === 'ACTIVO'];
            }
            if ($entity === 'distribucion') {
                $payload['lote_datos'] = $this->lotData($fresh->loteDetalle);
            }
            if ($entity === 'transferencias') {
                foreach ($fresh->detalles()->with('loteDetalle.distribucionDetalle')->orderBy('id')->get() as $detail) {
                    $distribution = $detail->loteDetalle?->distribucionDetalle;
                    if ($distribution !== null) {
                        $this->record($distribution, 'UPDATE');
                    }
                }
                $stockIds = array_values(array_unique([(int) $fresh->stock_de, (int) $fresh->stock_para]));
                sort($stockIds);
                $removedStocks = array_values(array_diff($previousStocks, $stockIds));
                $payload['detalles'] = $fresh->detalles()->with('loteDetalle')->orderBy('id')->get()->map(fn ($detail): array => [
                    ...array_intersect_key($detail->attributesToArray(), array_flip(['id', 'transferencia_id', 'lote', 'cantidad', 'subtotal', 'isv', 'total'])),
                    'lote_datos' => $this->lotData($detail->loteDetalle),
                ])->all();
            }
        }
        if ($action === 'DELETE' && in_array($entity, ['catalogospos'], true)) {
            $stockIds = $previousStocks ?: ($model->tipo === 'DESCUENTO' && $model->stock_id === null ? [EntityContract::GLOBAL_DISCOUNT_SCOPE] : [(int) $model->stock_id]);
        }
        if ($action === 'DELETE' && $entity === 'transferencias') {
            $stockIds = $previousStocks ?: array_values(array_unique([(int) $model->stock_de, (int) $model->stock_para]));
        }
        if ($payload !== null) {
            foreach (EntityContract::references($entity, $payload) as $reference) {
                if (DB::table('sync_entity_versions')->where('entity', $reference['entity'])->where('entity_id', $reference['entity_id'])->exists()) {
                    continue;
                }
                $class = self::MODELS[$reference['entity']];
                $parent = $class::whereKey($reference['entity_id'])->lockForUpdate()->firstOrFail();
                $this->record($parent, 'CREATE');
            }
        }
        DB::table('sync_entity_versions')->where('entity', $entity)->where('entity_id', $id)->update(['version' => $next, 'stock_ids' => json_encode($stockIds, JSON_THROW_ON_ERROR)]);
        $change = SyncChange::create(['uuid' => (string) Str::uuid(), 'entity' => $entity, 'entity_id' => $id, 'action' => $action,
            'version' => $next, 'payload' => $payload, 'stock_ids' => $stockIds, 'removed_stock_ids' => $removedStocks]);
        DB::table('hq_outbox')->insert(['sync_change_id' => $change->id]);
        if (config('headquarters.send_immediately')) {
            DB::afterCommit(function (): void {
                try {
                    Bus::dispatch(new PushHeadquartersOutboxJob);
                } catch (\Throwable $exception) {
                    Log::warning('El cambio confirmado permanece en hq_outbox.', ['exception' => $exception::class]);
                }
            });
        }
    }

    /** Distribution is a net sale price; the origin's configured rate is exported explicitly.
     * Never use purchase cost or transfer totals as a sale price.
     */
    private function saleData(Lote $lot): ?array
    {
        if (! Schema::hasTable('distribucion')) {
            return null;
        }
        $rows = Distribucion::query()->where('lote', $lot->id)->limit(2)->get();
        if ($rows->count() !== 1) {
            return null;
        }
        $sale = $rows->first();
        $rate = config('farma.purchase_isv_rate');
        $tax = $sale->isv;
        $percent = $tax === false ? '0.0000' : ($tax === true && is_numeric($rate) && $rate > 0 && $rate <= 1
            ? number_format((float) $rate * 100, 4, '.', '') : null);

        return ['distribucion_id' => (string) $sale->id, 'precio' => $sale->precio,
            'incluye_isv' => false, 'tasa_isv' => $percent,
            'tratamiento' => $tax === true ? 'GRAVADO' : ($tax === false ? 'EXENTO' : null),
            'dto1' => $sale->dto1, 'dto2' => $sale->dto2, 'dto3' => $sale->dto3, 'dto4' => $sale->dto4,
            'promocion_activa' => (bool) $sale->promocion_activa,
            'promocion_porcentaje' => $sale->promocion_porcentaje,
            'promocion_hasta' => $sale->promocion_hasta?->toDateString(),
            'promocion_todos_stocks' => (bool) ($sale->promocion_todos_stocks ?? true),
            'promocion_stock_ids' => $sale->promocion_stock_ids ?? [],
            'mostrar_encabezado' => (bool) $sale->mostrar_encabezado,
            'encabezado_texto' => $sale->encabezado_texto];
    }

    private function lotData(?Lote $lot): ?array
    {
        if ($lot === null) {
            return null;
        }
        $sale = $this->saleData($lot);

        return ['id' => (int) $lot->id, 'producto' => (int) $lot->producto, 'lote' => $lot->lote,
            'fecha_elab' => $lot->fecha_elab?->toDateString(), 'fecha_exp' => $lot->fecha_exp?->toDateString(),
            'costo' => $lot->costo === null ? null : (string) $lot->getRawOriginal('costo'),
            'venta' => $sale, 'valoracion' => $this->valuationData($lot, $sale)];
    }

    private function valuationData(Lote $lot, ?array $sale): ?array
    {
        $tax = $lot->isv;
        if ($tax === null) {
            // Converted historical lots can retain an explicit distribution tax
            // without a purchase flag. The distribution must be unambiguous.
            return isset($sale['tasa_isv'], $sale['tratamiento'])
                ? ['tasa_isv' => $sale['tasa_isv'], 'tratamiento' => $sale['tratamiento']] : null;
        }
        $rate = config('farma.purchase_isv_rate');

        return [
            'tasa_isv' => $tax === false ? '0.0000' : ($tax === true && is_numeric($rate) && $rate > 0 && $rate <= 1
                ? number_format((float) $rate * 100, 4, '.', '') : null),
            'tratamiento' => $tax === true ? 'GRAVADO' : ($tax === false ? 'EXENTO' : null),
        ];
    }
}
