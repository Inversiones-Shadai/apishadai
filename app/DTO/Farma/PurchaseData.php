<?php

namespace App\DTO\Farma;

class PurchaseData
{
    /** @param array<int, array<string, mixed>> $detalles */
    public function __construct(
        public readonly int $tipo,
        public readonly int $proveedor,
        public readonly int $plazo,
        public readonly string $nro,
        public readonly ?string $nota,
        public readonly ?string $img,
        public readonly array $detalles,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            tipo: (int) $validated['tipo'],
            proveedor: (int) $validated['proveedor'],
            plazo: (int) $validated['plazo'],
            nro: self::text((string) $validated['nro']),
            nota: filled($validated['nota'] ?? null) ? self::text((string) $validated['nota']) : null,
            img: isset($validated['img']) ? trim((string) $validated['img']) : null,
            detalles: array_map(static fn (array $detail): array => [
                'producto' => (int) $detail['producto'],
                'es_bonificacion' => (bool) ($detail['es_bonificacion'] ?? false),
                'cantidad' => (int) $detail['cantidad'],
                'lote' => self::text((string) $detail['lote']),
                'fecha_elaboracion' => (string) $detail['fecha_elaboracion'],
                'fecha_expiracion' => (string) $detail['fecha_expiracion'],
                'costo' => number_format((float) $detail['costo'], 2, '.', ''),
                'isv' => (bool) ($detail['isv'] ?? false),
                'descuento' => number_format((float) ($detail['descuento'] ?? 0), 2, '.', ''),
            ], $validated['detalles']),
        );
    }

    /** @return array<string, mixed> */
    public function header(int $usuario): array
    {
        $totals = $this->totals();

        $data = [
            'tipo' => $this->tipo,
            'proveedor' => $this->proveedor,
            'usuario' => $usuario,
            'plazo' => $this->plazo,
            'nro' => $this->nro,
            'items' => array_sum(array_column($this->detalles, 'cantidad')),
            'isv' => $totals['isv'],
            'subtotal' => $totals['subtotal'],
            'descuento' => $totals['descuento'],
            'total' => $totals['total'],
            'estado' => 'pendiente',
            'nota' => $this->nota,
        ];

        if ($this->img !== null) {
            $data['img'] = $this->img;
        }

        return $data;
    }

    /** @return array<int, array<string, mixed>> */
    public function detailRows(): array
    {
        return array_map(function (array $detail): array {
            $line = self::lineTotals($detail);

            return [
                ...$detail,
                'total' => $line['total'],
            ];
        }, $this->detalles);
    }

    /** @return array{subtotal: string, isv: string, descuento: string, total: string} */
    private function totals(): array
    {
        $subtotal = 0.0;
        $tax = 0.0;
        $discount = 0.0;
        $total = 0.0;

        foreach ($this->detalles as $detail) {
            $line = self::lineTotals($detail);
            $subtotal += (float) $line['subtotal'];
            $tax += (float) $line['isv'];
            $discount += (float) $line['descuento'];
            $total += (float) $line['total'];
        }

        return [
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'isv' => number_format($tax, 2, '.', ''),
            'descuento' => number_format($discount, 2, '.', ''),
            'total' => number_format($total, 2, '.', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{subtotal: string, isv: string, descuento: string, total: string}
     */
    public static function lineTotals(array $detail): array
    {
        $subtotal = round((int) $detail['cantidad'] * (float) $detail['costo'], 2);
        $unitTax = (bool) $detail['isv']
            ? round((float) $detail['costo'] * max(0, min(1, (float) config('farma.purchase_isv_rate', 0.15))), 2)
            : 0.0;
        $tax = round((int) $detail['cantidad'] * $unitTax, 2);
        $discount = round((int) $detail['cantidad'] * (float) $detail['descuento'], 2);

        return [
            'subtotal' => number_format($subtotal, 2, '.', ''),
            'isv' => number_format($tax, 2, '.', ''),
            'descuento' => number_format($discount, 2, '.', ''),
            'total' => number_format(max(0, $subtotal + $tax - $discount), 2, '.', ''),
        ];
    }

    private static function text(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return trim($value);
    }
}
