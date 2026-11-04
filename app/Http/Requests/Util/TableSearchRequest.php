<?php

namespace App\Http\Requests\Util;

use App\Http\Requests\BaseRequest;

class TableSearchRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
