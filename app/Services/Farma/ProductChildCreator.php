<?php

namespace App\Services\Farma;

use App\Models\Farma\Producto;
use App\Models\Farma\ProdUnidad;
use App\Services\Headquarters\ChangeRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductChildCreator
{
    public function create(int $parentId, string $description): Producto
    {
        return DB::transaction(function () use ($parentId, $description): Producto {
            DB::table('producto_relacion_mutex')->where('id', 1)->lockForUpdate()->firstOrFail();
            $parent = Producto::query()->lockForUpdate()->findOrFail($parentId);
            if ($parent->producto_padre_id !== null || Str::endsWith(mb_strtolower(rtrim($parent->codigo)), 'h')) {
                throw ValidationException::withMessages(['producto_padre_id' => 'El producto ya es hijo y no puede convertirse nuevamente.']);
            }

            $code = $parent->codigo.'H';
            if (mb_strlen($code) > 20) {
                throw ValidationException::withMessages(['codigo' => 'El código del padre no permite añadir H: el máximo es de 20 caracteres.']);
            }
            if (Producto::query()->whereRaw('LOWER(codigo) = ?', [mb_strtolower($code)])->exists()) {
                throw ValidationException::withMessages(['codigo' => 'Ya existe un producto con el código '.$code.'.']);
            }

            $parentUnit = ProdUnidad::query()->findOrFail($parent->unidad);
            $unit = ProdUnidad::query()->firstOrCreate([
                'abreviatura_c' => $parentUnit->abreviatura_v,
                'abreviatura_v' => $parentUnit->abreviatura_v,
                'cantidad_c' => 1,
                'cantidad_v' => 1,
            ]);
            $child = new Producto;
            $child->fill([
                ...$parent->only(['categoria', 'laboratorio', 'familia', 'codigobar', 'estado']),
                'producto_padre_id' => $parent->id,
                'codigo' => $code,
                'descripcion' => $description,
                'unidad' => $unit->id,
            ]);
            // Reuse the stored image reference, including legacy blobs, without uploading it again.
            $child->setRawAttributes([...$child->getAttributes(), 'imagen' => $parent->getRawOriginal('imagen')]);
            $recorder = app(ChangeRecorder::class);
            $recorder->withoutRecording(function () use ($child, $parent): void {
                $child->save();
                $child->principiosActivos()->sync($parent->principiosActivos()->pluck('principal_activos.id')->all());
            });
            $recorder->record($child, 'CREATE');

            return $child;
        }, 3);
    }
}
