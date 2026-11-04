<?php

namespace App\Http\Resources\Farma;

use Illuminate\Http\Request;

class PurchaseReportExportResource extends PurchaseResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'pagos' => PurchaseTransactionResource::collection($this->whenLoaded('transacciones')),
        ];
    }
}
