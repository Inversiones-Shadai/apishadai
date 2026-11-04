<?php

namespace App\Http\Requests\Farma;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class SupplierReturnRuleRequest extends BaseRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('notas')) {
            $this->merge(['notas' => trim(strip_tags((string) $this->input('notas')))]);
        }
    }

    public function rules(): array
    {
        return [
            'meses_anticipacion' => ['required', 'integer', 'min:0', 'max:60'],
            'acepta_hijos' => ['required', 'boolean'],
            'acepta_empezados' => ['sometimes', 'boolean', 'same:acepta_hijos'],
            'tipo_devolucion' => ['required', Rule::in(['directa', 'autorizacion', 'no_acepta'])],
            'notas' => ['nullable', 'string', 'max:2000'],
            'revision' => ['required', 'integer', 'min:0'],
        ];
    }
}
