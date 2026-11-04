<?php

namespace App\Http\Resources\Farma;

use App\Models\Stocks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistributionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'lote_id' => (int) $this->lote,
            'precio' => (float) $this->precio,
            'isv' => (bool) $this->isv,
            'dto1' => (float) $this->dto1,
            'dto2' => (float) $this->dto2,
            'dto3' => (float) $this->dto3,
            'dto4' => (float) $this->dto4,
            'promocion_activa' => (bool) $this->promocion_activa,
            'promocion_porcentaje' => $this->promocion_porcentaje === null ? null : (float) $this->promocion_porcentaje,
            'promocion_hasta' => $this->promocion_hasta?->toDateString(),
            'mostrar_encabezado' => (bool) $this->mostrar_encabezado,
            'encabezado_texto' => $this->encabezado_texto,
            'promocion_todos_stocks' => (bool) ($this->promocion_todos_stocks ?? true),
            'promocion_stock_ids' => $this->promocion_stock_ids ?? [],
            'promocion_stocks' => empty($this->promocion_stock_ids) ? [] : Stocks::query()
                ->whereIn('id', $this->promocion_stock_ids)->get(['id', 'descripcion'])->toArray(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
