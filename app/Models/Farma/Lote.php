<?php

namespace App\Models\Farma;

use App\Models\Concerns\PublishesHeadquarters;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lote extends Model
{
    use PublishesHeadquarters;

    protected $table = 'lotes';

    protected $fillable = ['compra', 'producto', 'lote', 'fecha_elab', 'fecha_exp', 'cantidad', 'costo', 'isv', 'es_bonificacion'];

    protected function casts(): array
    {
        return [
            'compra' => 'integer',
            'producto' => 'integer',
            'fecha_elab' => 'date:Y-m-d',
            'fecha_exp' => 'date:Y-m-d',
            'cantidad' => 'decimal:4',
            'costo' => 'decimal:4',
            'isv' => 'boolean',
            'es_bonificacion' => 'boolean',
        ];
    }

    public function productoDetalle(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto');
    }

    public function compraDetalle(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra');
    }

    public function distribucionDetalle(): HasOne
    {
        return $this->hasOne(Distribucion::class, 'lote');
    }
}
