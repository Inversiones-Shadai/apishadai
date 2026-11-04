<?php

namespace App\Http\Resources\Laboratorios;

use App\Services\Images\ImageStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class LaboratorioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'telefono' => $this->telefono,
            'direccion' => $this->direccion,
            'tiene_imagen' => (bool) ($this->tiene_imagen ?? ($this->imagen_uuid ?? $this->imagen)),
            'imagen_uuid' => Str::isUuid(($this->imagen_uuid ?? $this->imagen) ?? '') ? ($this->imagen_uuid ?? $this->imagen) : null,
            'imagen_url' => app(ImageStorage::class)->url(($this->imagen_uuid ?? $this->imagen), 'lbtbtn06'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
