<?php

namespace App\Http\Resources\Farma;

use App\Services\Images\ImageStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class LotProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'producto_id' => (int) $this->producto,
            'codigo' => (string) ($this->productoDetalle?->codigo ?? ''),
            'descripcion' => (string) ($this->productoDetalle?->descripcion ?? ''),
            'laboratorio' => (string) ($this->productoDetalle?->laboratorioDetalle?->nombre ?? ''),
            'presentacion' => (string) ($this->productoDetalle?->Familia?->Presentacion?->descripcion ?? ''),
            'administracion' => (string) ($this->productoDetalle?->Familia?->Administracion?->descripcion ?? ''),
            'tiene_imagen' => (bool) ($this->productoDetalle?->tiene_imagen ?? false),
            'imagen_uuid' => Str::isUuid(($this->productoDetalle?->imagen_uuid ?? $this->productoDetalle?->imagen) ?? '') ? ($this->productoDetalle?->imagen_uuid ?? $this->productoDetalle?->imagen) : null,
            'imagen_url' => app(ImageStorage::class)->url(($this->productoDetalle?->imagen_uuid ?? $this->productoDetalle?->imagen), 'prdbtn06'),
            'lotes_count' => (int) $this->lotes_count,
            'cantidad_total' => (int) $this->cantidad_total,
            'proxima_expiracion' => $this->proxima_expiracion,
        ];
    }
}
