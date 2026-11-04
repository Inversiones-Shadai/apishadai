<?php

namespace App\Http\Requests\Farma\Distribucion;

use App\Http\Requests\BaseRequest;

class DistributionRequest extends BaseRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'dto1' => $this->input('dto1', 0),
            'dto2' => $this->input('dto2', 0),
            'dto3' => $this->input('dto3', 0),
            'dto4' => $this->input('dto4', 0),
        ]);
    }

    public function rules(): array
    {
        return [
            'precio' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'dto1' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'dto2' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'dto3' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'dto4' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'promocion_activa' => ['sometimes', 'boolean'],
            'promocion_porcentaje' => ['required_if:promocion_activa,true', 'nullable', 'numeric', 'gt:0', 'max:99.99'],
            'promocion_hasta' => ['required_if:promocion_activa,true', 'nullable', 'date_format:Y-m-d'],
            'mostrar_encabezado' => ['sometimes', 'boolean'],
            'encabezado_texto' => ['nullable', 'string', 'max:160'],
            'promocion_todos_stocks' => ['sometimes', 'boolean'],
            'promocion_stock_ids' => ['required_if:promocion_todos_stocks,false', 'nullable', 'array', 'min:1', 'max:100'],
            'promocion_stock_ids.*' => ['required', 'integer', 'min:1', 'distinct', 'exists:stocks,id'],
        ];
    }

    protected function customMessages(): array
    {
        return [
            'precio.required' => 'El precio es obligatorio.',
            'precio.gt' => 'El precio debe ser mayor que cero.',
            'dto*.max' => 'Los descuentos no pueden ser mayores que 99.99%.',
        ];
    }
}
