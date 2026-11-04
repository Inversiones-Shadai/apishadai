<?php

namespace App\Http\Requests\Farma;

use App\Http\Requests\BaseRequest;

class ExpirySearchRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'meses' => ['nullable', 'integer', 'min:1', 'max:60'],
        ];
    }
}
