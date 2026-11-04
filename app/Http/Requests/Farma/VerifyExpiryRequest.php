<?php

namespace App\Http\Requests\Farma;

use App\Http\Requests\BaseRequest;

class VerifyExpiryRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'lotes' => ['required', 'array', 'min:1', 'max:100'],
            'lotes.*' => ['required', 'array:id,producto_empezado'],
            'lotes.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            // Old clients may still send this field; classification comes from the catalog.
            'lotes.*.producto_empezado' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
