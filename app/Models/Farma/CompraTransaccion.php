<?php

namespace App\Models\Farma;

use App\Models\Concerns\StoresImageUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompraTransaccion extends Model
{
    use StoresImageUuid;

    protected $table = 'compra_transc';

    protected $fillable = ['nro', 'compra', 'tipo', 'valor', 'img'];

    protected $hidden = ['img'];

    protected function casts(): array
    {
        return [
            'compra' => 'integer',
            'tipo' => 'integer',
            'valor' => 'decimal:2',
        ];
    }

    public function compraDetalle(): BelongsTo
    {
        return $this->belongsTo(Compra::class, 'compra');
    }

    public function tipoDetalle(): BelongsTo
    {
        return $this->belongsTo(TransaccionTipo::class, 'tipo');
    }
}
