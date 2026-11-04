<?php

namespace App\Models\Farma;

use App\Models\Concerns\PublishesHeadquarters;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Distribucion extends Model
{
    use PublishesHeadquarters;

    protected $table = 'distribucion';

    protected $fillable = ['lote', 'precio', 'isv', 'dto1', 'dto2', 'dto3', 'dto4',
        'promocion_activa', 'promocion_porcentaje', 'promocion_hasta', 'mostrar_encabezado', 'encabezado_texto',
        'promocion_todos_stocks', 'promocion_stock_ids'];

    protected function casts(): array
    {
        return [
            'lote' => 'integer',
            'precio' => 'decimal:2',
            'isv' => 'boolean',
            'dto1' => 'decimal:2',
            'dto2' => 'decimal:2',
            'dto3' => 'decimal:2',
            'dto4' => 'decimal:2',
            'promocion_activa' => 'boolean',
            'promocion_porcentaje' => 'decimal:2',
            'promocion_hasta' => 'date',
            'mostrar_encabezado' => 'boolean',
            'promocion_todos_stocks' => 'boolean',
            'promocion_stock_ids' => 'array',
        ];
    }

    public function loteDetalle(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote');
    }
}
