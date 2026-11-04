<?php

namespace App\Http\Resources\Farma;

use App\Services\Images\ImageStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'producto_padre_id' => $this->producto_padre_id === null ? null : (string) $this->producto_padre_id,
            'producto_padre' => $this->whenLoaded('padreDetalle', fn ($parent): array => [
                'id' => (string) $parent->id, 'codigo' => $parent->codigo, 'descripcion' => $parent->descripcion,
            ]),
            'categoria_id' => $this->categoria,
            'laboratorio_id' => $this->laboratorio,
            'unidad_id' => $this->unidad,
            'familia_id' => $this->familia,
            'estado_id' => $this->estado,
            'codigo' => $this->codigo,
            'codigobar' => $this->codigobar,
            'descripcion' => $this->descripcion,
            'categoria' => $this->whenLoaded('categoriaDetalle', fn ($categoria): array => $categoria->only(['id', 'nombre'])),
            'laboratorio' => $this->whenLoaded('laboratorioDetalle', fn ($laboratorio): array => $laboratorio->only(['id', 'nombre', 'direccion'])),
            'unidad' => $this->whenLoaded('unidadDetalle', fn ($unidad): array => $unidad->only(['id', 'abreviatura_c', 'cantidad_c', 'abreviatura_v', 'cantidad_v'])),
            'familia' => $this->whenLoaded('Familia', fn ($familia): array => [
                'id' => $familia->id,
                'descripcion' => $familia->descripcion,
                'presentacion' => [
                    'id' => $familia->Presentacion->id,
                    'descripcion' => $familia->Presentacion->descripcion,
                ],
                'administracion' => [
                    'id' => $familia->Administracion->id,
                    'descripcion' => $familia->Administracion->descripcion,
                ],
            ]),
            'estado' => $this->whenLoaded('estadoDetalle', fn ($estado): array => $estado->only(['id', 'descripcion'])),
            'tiene_imagen' => (bool) $this->tiene_imagen,
            'imagen_uuid' => Str::isUuid(($this->imagen_uuid ?? $this->imagen) ?? '') ? ($this->imagen_uuid ?? $this->imagen) : null,
            'imagen_url' => app(ImageStorage::class)->url(($this->imagen_uuid ?? $this->imagen), 'prdbtn06'),
            'principios_activos' => PrincipalActivoResource::collection($this->whenLoaded('principiosActivos')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
