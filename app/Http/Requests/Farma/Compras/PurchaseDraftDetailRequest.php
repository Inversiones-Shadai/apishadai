<?php

namespace App\Http\Requests\Farma\Compras;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class PurchaseDraftDetailRequest extends BaseRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'lote' => trim(strip_tags((string) $this->input('lote', ''))),
            'fecha_elaboracion' => filled($this->input('fecha_elaboracion')) ? $this->input('fecha_elaboracion') : null,
            'fecha_expiracion' => filled($this->input('fecha_expiracion')) ? $this->input('fecha_expiracion') : null,
        ]);
    }

    public function rules(): array
    {
        $purchase = (int) $this->route('purchase');

        return [
            'id' => ['nullable', 'integer', Rule::exists('compra_detalle', 'id')->where('compra', $purchase)],
            'producto' => ['required', 'integer', 'exists:productos,id'],
            'es_bonificacion' => ['sometimes', 'boolean'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lote' => ['nullable', 'string', 'max:100'],
            'fecha_elaboracion' => ['nullable', 'date_format:Y-m-d'],
            'fecha_expiracion' => [
                'nullable',
                'date_format:Y-m-d',
                Rule::when($this->filled('fecha_elaboracion'), 'after_or_equal:fecha_elaboracion'),
            ],
            'costo' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'isv' => ['required', 'boolean'],
            'descuento' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    protected function customMessages(): array
    {
        return [
            '*.required' => 'El campo :attribute es obligatorio.',
            '*.exists' => 'El registro seleccionado no existe o no pertenece a esta compra.',
        ];
    }
}
