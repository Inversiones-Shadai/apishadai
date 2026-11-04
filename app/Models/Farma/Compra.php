<?php

namespace App\Models\Farma;

use App\Models\Concerns\StoresImageUuid;
use App\Models\Proveedores;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Compra extends Model
{
    use StoresImageUuid;

    protected $table = 'compras';

    protected $fillable = [
        'tipo', 'proveedor', 'usuario', 'plazo', 'nro', 'items',
        'isv', 'subtotal', 'descuento', 'total', 'estado', 'nota', 'img',
        'kardex_enviado_at', 'kardex_usuario',
    ];

    protected $hidden = ['img'];

    protected function casts(): array
    {
        return [
            'tipo' => 'integer',
            'proveedor' => 'integer',
            'usuario' => 'integer',
            'plazo' => 'integer',
            'items' => 'integer',
            'isv' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'descuento' => 'decimal:2',
            'total' => 'decimal:2',
            'kardex_enviado_at' => 'datetime',
            'kardex_usuario' => 'integer',
        ];
    }

    public function tipoDetalle(): BelongsTo
    {
        return $this->belongsTo(CompraTipo::class, 'tipo', 'int');
    }

    public function proveedorDetalle(): BelongsTo
    {
        return $this->belongsTo(Proveedores::class, 'proveedor');
    }

    public function usuarioDetalle(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario');
    }

    public function kardexUsuarioDetalle(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kardex_usuario');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(CompraDetalle::class, 'compra');
    }

    public function transacciones(): HasMany
    {
        return $this->hasMany(CompraTransaccion::class, 'compra');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class, 'compra');
    }
}
