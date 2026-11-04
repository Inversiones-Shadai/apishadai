<?php

namespace App\Models\Farma;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraDetalle extends Model
{
    protected $table = 'compra_detalle';

    protected $fillable = ['compra', 'producto', 'cantidad', 'lote', 'fecha_elaboracion', 'fecha_expiracion', 'costo', 'isv', 'descuento', 'total', 'es_bonificacion'];

    protected function casts(): array
    {
        return [
            'compra' => 'integer',
            'producto' => 'integer',
            'cantidad' => 'integer',
            'es_bonificacion' => 'boolean',
            'fecha_elaboracion' => 'date:Y-m-d',
            'fecha_expiracion' => 'date:Y-m-d',
            'costo' => 'decimal:2',
            'isv' => 'boolean',
            'descuento' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function compraDetalle(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra');
    }

    public function productoDetalle(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto');
    }
}
