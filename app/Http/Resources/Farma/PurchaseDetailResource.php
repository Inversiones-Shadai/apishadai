<?php

namespace App\Http\Resources\Farma;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $subtotal = round((int) $this->cantidad * (float) $this->costo, 2);
        $unitTax = $this->isv
            ? round((float) $this->costo * max(0, min(1, (float) config('farma.purchase_isv_rate', 0.15))), 2)
            : 0.0;
        $tax = round((int) $this->cantidad * $unitTax, 2);
        $discount = round((int) $this->cantidad * (float) $this->descuento, 2);

        return [
            'id' => (int) $this->id,
            'producto_id' => (int) $this->producto,
            'es_bonificacion' => (bool) $this->es_bonificacion,
            'cantidad' => (int) $this->cantidad,
            'lote' => (string) $this->lote,
            'fecha_elaboracion' => $this->fecha_elaboracion?->format('Y-m-d'),
            'fecha_expiracion' => $this->fecha_expiracion?->format('Y-m-d'),
            'costo' => (float) $this->costo,
            'isv' => (bool) $this->isv,
            'subtotal' => $subtotal,
            'isv_unitario' => $unitTax,
            'isv_valor' => $tax,
            'descuento' => (float) $this->descuento,
            'descuento_valor' => $discount,
            'total' => (float) $this->total,
            'producto' => $this->whenLoaded('productoDetalle', fn (): array => [
                'id' => (int) $this->productoDetalle->id,
                'descripcion' => (string) $this->productoDetalle->descripcion,
                'laboratorio' => (string) ($this->productoDetalle->laboratorioDetalle?->nombre ?? ''),
                'presentacion' => (string) ($this->productoDetalle->Familia?->Presentacion?->descripcion ?? ''),
                'unidad_compra' => (int) ($this->productoDetalle->unidadDetalle?->cantidad_c ?? 0),
            ]),
        ];
    }
}
