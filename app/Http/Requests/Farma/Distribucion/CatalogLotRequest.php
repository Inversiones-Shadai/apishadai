<?php

namespace App\Http\Requests\Farma\Distribucion;

use App\Http\Requests\BaseRequest;

class CatalogLotRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'producto' => ['required', 'integer', 'exists:productos,id'],
            'lote' => ['required', 'string', 'max:100'],
            'fecha_elab' => ['nullable', 'date_format:Y-m-d', ...($this->filled('fecha_exp') ? ['before_or_equal:fecha_exp'] : [])],
            'fecha_exp' => ['nullable', 'date_format:Y-m-d'],
            'costo' => ['nullable', 'numeric', 'min:0', 'max:99999999.9999', 'decimal:0,4'],
            'isv' => ['required', 'boolean'],
            'cantidad' => ['prohibited'],
            'compra' => ['prohibited'],
            'stock' => ['prohibited'],
        ];
    }

    protected function customAttributes(): array
    {
        return ['producto' => 'producto', 'lote' => 'número de lote', 'fecha_elab' => 'fecha de elaboración',
            'fecha_exp' => 'fecha de expiración', 'costo' => 'costo unitario', 'isv' => 'aplica ISV'];
    }
}
