<?php

namespace App\Http\Requests\Farma\Productos;

use App\Http\Requests\BaseRequest;
use App\Models\Farma\ProdCategoria;
use Illuminate\Validation\Rule;

use function is_string;

class ProductRequest extends BaseRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $this->merge(['id' => $this->route('id')]);
        }

        $data = [];

        // Admitir IDs escalares y objetos de los selectores sin omitir la validación.
        foreach (['categoria', 'laboratorio', 'unidad', 'familia', 'estado'] as $field) {
            $value = $this->input($field);
            if (is_int($value) || is_string($value)) {
                $data[$field] = ['id' => $value];
            }
        }

        foreach (['codigo', 'codigobar', 'descripcion'] as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            if ($field === 'codigobar' && ! filled($this->input($field))) {
                $data[$field] = null;

                continue;
            }

            $value = strip_tags((string) $this->input($field));
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
            $data[$field] = trim($value);
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $updating = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $id = $updating ? (int) $this->route('id') : null;

        return [
            ...($updating ? ['id' => ['required', 'integer', 'exists:productos,id']] : []),
            'producto_padre_id' => ['sometimes', 'nullable', 'regex:/^[1-9][0-9]{0,19}$/', 'exists:productos,id'],
            'categoria.id' => ['required', 'integer', 'exists:prod_categorias,id'],
            'laboratorio.id' => ['required', 'integer', 'exists:laboratorios,id'],
            'unidad.id' => ['required', 'integer', 'exists:prod_unidades,id'],
            'familia' => ['nullable', 'array', 'required_array_keys:id'],
            'familia.id' => [Rule::requiredIf(fn (): bool => $this->categoryRequiresFamily()), 'nullable', 'integer', 'min:1', 'exists:familia,id'],
            'codigo' => [
                'required',
                'string',
                'max:20',
                Rule::unique('productos', 'codigo')->ignore($id),
            ],
            'codigobar' => [
                'nullable',
                'string',
                'max:50',
            ],
            'descripcion' => ['required', 'string', 'max:150'],
            'estado.id' => ['required', 'integer', 'exists:prod_estado,id'],
            'principios_activos' => ['sometimes', 'array'],
            'principios_activos.*' => ['required', 'integer', 'distinct', 'exists:principal_activos,id'],
            'imagen' => [
                'nullable',
                'string',
                'max:1398104',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || base64_decode($value, true) === false) {
                        $fail('La imagen debe ser una cadena base64 válida.');
                    }
                },
            ],
        ];
    }

    private function categoryRequiresFamily(): bool
    {
        $categoryId = $this->input('categoria.id');
        if ((! is_int($categoryId) && ! is_string($categoryId))
            || filter_var($categoryId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return false;
        }

        $name = ProdCategoria::query()->whereKey($categoryId)->value('nombre');

        return $name !== null && ProdCategoria::requiresFamily($name);
    }

    protected function customMessages(): array
    {
        return [
            '*.required' => 'El campo :attribute es obligatorio.',
            'familia.id.required' => 'Selecciona una familia para esta categoría.',
            '*.exists' => 'El valor seleccionado para :attribute no existe.',
            '*.unique' => 'El valor de :attribute ya está registrado.',
            '*.max' => 'El campo :attribute excede la longitud permitida.',
            'imagen.max' => 'La imagen no debe exceder 1 MB.',
        ];
    }
}
