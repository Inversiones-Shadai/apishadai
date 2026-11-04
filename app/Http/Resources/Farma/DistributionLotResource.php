<?php

namespace App\Http\Resources\Farma;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistributionLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $distribution = $this->distribucionDetalle;

        return [
            'id' => (int) $this->id,
            'lote' => (string) $this->lote,
            'cantidad' => (int) $this->cantidad,
            'costo' => $this->costo !== null ? (float) $this->costo : null,
            'isv' => $this->isv !== null ? (bool) $this->isv : null,
            'fecha_elaboracion' => $this->fecha_elab?->toDateString(),
            'fecha_expiracion' => $this->fecha_exp?->toDateString(),
            'factura' => $this->compraDetalle === null ? null : [
                'id' => (int) $this->compraDetalle->id,
                'nro' => (string) $this->compraDetalle->nro,
            ],
            'configurado' => $distribution !== null,
            'distribucion' => $distribution === null ? null : (new DistributionResource($distribution))->toArray($request),
        ];
    }
}
