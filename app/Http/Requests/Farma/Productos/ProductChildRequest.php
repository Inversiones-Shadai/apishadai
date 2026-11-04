<?php

namespace App\Http\Requests\Farma\Productos;

use App\Http\Requests\BaseRequest;

class ProductChildRequest extends BaseRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('descripcion'))) {
            $this->merge(['descripcion' => trim(preg_replace(
                '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags($this->input('descripcion')),
            ) ?? '')]);
        }
    }

    public function rules(): array
    {
        return ['descripcion' => ['required', 'string', 'max:150']];
    }

    protected function customMessages(): array
    {
        return [
            'descripcion.required' => 'La descripción del hijo es obligatoria.',
            'descripcion.max' => 'La descripción no debe exceder 150 caracteres.',
        ];
    }
}
