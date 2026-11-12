<?php

namespace App\DTO\Farma;

class ProductData
{
    public function __construct(
        public readonly int $categoria,
        public readonly int $laboratorio,
        public readonly int $unidad,
        public readonly ?int $familia,
        public readonly string $codigo,
        public readonly ?string $codigobar,
        public readonly string $descripcion,
        public readonly int $estado,
        public readonly ?string $imagen,
        /** @var array<int, int>|null */
        public readonly ?array $principiosActivos,
        public readonly ?string $productoPadreId = null,
        public readonly bool $actualizarPadre = false,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            productoPadreId: isset($validated['producto_padre_id']) ? (string) $validated['producto_padre_id'] : null,
            actualizarPadre: array_key_exists('producto_padre_id', $validated),
            categoria: (int) $validated['categoria']['id'],
            laboratorio: (int) $validated['laboratorio']['id'],
            unidad: (int) $validated['unidad']['id'],
            familia: isset($validated['familia']['id']) ? (int) $validated['familia']['id'] : null,
            codigo: self::text((string) $validated['codigo']),
            codigobar: filled($validated['codigobar'] ?? null)
                ? self::text((string) $validated['codigobar'])
                : null,
            descripcion: self::text((string) $validated['descripcion']),
            estado: (int) $validated['estado']['id'],
            imagen: isset($validated['imagen']) ? trim((string) $validated['imagen']) : null,
            principiosActivos: array_key_exists('principios_activos', $validated)
                ? array_values(array_unique(array_map(
                    static fn (mixed $id): int => (int) $id,
                    (array) $validated['principios_activos'],
                )))
                : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'categoria' => $this->categoria,
            'laboratorio' => $this->laboratorio,
            'unidad' => $this->unidad,
            'familia' => $this->familia,
            'codigo' => $this->codigo,
            'codigobar' => $this->codigobar,
            'descripcion' => $this->descripcion,
            'estado' => $this->estado,
        ];

        if ($this->actualizarPadre) {
            $data['producto_padre_id'] = $this->productoPadreId;
        }

        if ($this->imagen !== null) {
            $data['imagen'] = $this->imagen;
        }

        return $data;
    }

    private static function text(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';

        return trim($value);
    }
}
