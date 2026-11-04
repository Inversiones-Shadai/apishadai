<?php

namespace App\Http\Resources\Farma;

use App\Services\Images\InvoiceStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'nro' => $this->nro,
            'compra_id' => (int) $this->compra,
            'tipo_id' => (int) $this->tipo,
            'valor' => (float) $this->valor,
            'tiene_documento' => (bool) ($this->tiene_documento ?? false),
            'documento_url' => app(InvoiceStorage::class)->url($this->img, 'ctrbtn06'),
            'compra' => $this->whenLoaded('compraDetalle', fn (): array => [
                'id' => (int) $this->compraDetalle->id,
                'nro' => (string) $this->compraDetalle->nro,
                'total' => (float) $this->compraDetalle->total,
                'proveedor' => (string) ($this->compraDetalle->proveedorDetalle?->nombre ?? ''),
            ]),
            'tipo' => $this->whenLoaded('tipoDetalle', fn (): array => ['id' => (int) $this->tipoDetalle->id, 'descripcion' => (string) $this->tipoDetalle->descripcion]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
