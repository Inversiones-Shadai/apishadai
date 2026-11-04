<?php

namespace App\Http\Requests;

class SelectorLookupRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'search' => ['nullable', 'string', 'max:255'],
            'selected' => ['sometimes', 'regex:/^[1-9][0-9]{0,19}$/'],
            'filters' => ['sometimes', 'array:parent,product'],
            'filters.*' => ['nullable', 'regex:/^[1-9][0-9]{0,19}$/'],
        ];
    }
}
