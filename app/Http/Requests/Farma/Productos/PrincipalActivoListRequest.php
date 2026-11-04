<?php

namespace App\Http\Requests\Farma\Productos;

use App\Http\Requests\BaseRequest;

class PrincipalActivoListRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'search' => ['nullable', 'string', 'max:255'],
            'all' => ['sometimes', 'boolean'],
        ];
    }
}
