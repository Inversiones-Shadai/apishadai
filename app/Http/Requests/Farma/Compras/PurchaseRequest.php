<?php

namespace App\Http\Requests\Farma\Compras;

use App\Http\Requests\BaseRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class PurchaseRequest extends BaseRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'nro' => $this->clean($this->input('nro')),
            'nota' => filled($this->input('nota')) ? $this->clean($this->input('nota')) : null,
        ]);
    }

    public function rules(): array
    {
        $updating = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $id = $updating ? $this->integer('id') : null;

        return [
            ...($updating ? ['id' => ['required', 'integer', 'exists:compras,id']] : []),
            'tipo' => ['required', 'integer', 'exists:compra_tipo,int'],
            'proveedor' => ['required', 'integer', 'exists:proveedores,id'],
            'plazo' => ['required', 'integer', 'min:0', 'max:3650'],
            'nro' => ['required', 'string', 'max:255', Rule::unique('compras', 'nro')->where('proveedor', $this->integer('proveedor'))->ignore($id)],
            'nota' => ['nullable', 'string', 'max:5000'],
            'img' => ['nullable', 'string', 'max:1398104', $this->base64Rule()],
            'detalles' => ['required', 'array', 'min:1', 'max:200'],
            'detalles.*.producto' => ['required', 'integer', 'exists:productos,id'],
            'detalles.*.es_bonificacion' => ['sometimes', 'boolean'],
            'detalles.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000000'],
            'detalles.*.lote' => ['required', 'string', 'max:100'],
            'detalles.*.fecha_elaboracion' => ['required', 'date_format:Y-m-d'],
            'detalles.*.fecha_expiracion' => ['required', 'date_format:Y-m-d', 'after_or_equal:detalles.*.fecha_elaboracion'],
            'detalles.*.costo' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'detalles.*.isv' => ['required', 'boolean'],
            'detalles.*.descuento' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $details = $this->input('detalles', []);

            if (! is_array($details)) {
                return;
            }

            $taxRate = max(0, min(1, (float) config('farma.purchase_isv_rate', 0.15)));

            foreach ($details as $index => $detail) {
                if (! is_array($detail)
                    || ! is_numeric($detail['cantidad'] ?? null)
                    || ! is_numeric($detail['costo'] ?? null)
                    || ! is_numeric($detail['descuento'] ?? null)) {
                    continue;
                }

                $quantity = max(0, (int) $detail['cantidad']);
                if ((float) $detail['costo'] < 0.01 && ! filter_var($detail['es_bonificacion'] ?? false, FILTER_VALIDATE_BOOL)) {
                    $validator->errors()->add("detalles.{$index}.costo", 'El costo debe ser mayor que cero para productos que no son bonificación.');
                }
                $cost = max(0, (float) $detail['costo']);
                $subtotal = $quantity * $cost;
                $unitTax = filter_var($detail['isv'] ?? false, FILTER_VALIDATE_BOOL)
                    ? round($cost * $taxRate, 2)
                    : 0.0;
                $tax = round($quantity * $unitTax, 2);
                $discount = $quantity * max(0, (float) $detail['descuento']);

                if ($discount > round($subtotal + $tax, 2)) {
                    $validator->errors()->add(
                        "detalles.{$index}.descuento",
                        'El descuento no puede superar el subtotal más el ISV de la línea.',
                    );
                }
            }
        });
    }

    protected function customMessages(): array
    {
        return [
            '*.required' => 'El campo :attribute es obligatorio.',
            '*.exists' => 'El valor seleccionado para :attribute no existe.',
            '*.unique' => 'La factura ya está registrada para este proveedor.',
            'detalles.min' => 'Agrega al menos un producto a la compra.',
            'img.max' => 'El documento escaneado no debe exceder 1 MB.',
        ];
    }

    private function base64Rule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || base64_decode($value, true) === false) {
                $fail('El documento debe ser una imagen base64 válida.');
            }
        };
    }

    private function clean(mixed $value): string
    {
        $value = strip_tags((string) $value);

        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
    }
}
