<?php

namespace App\Models\Farma;

use App\Models\Concerns\PublishesHeadquarters;
use App\Models\Concerns\StoresImageUuid;
use App\Models\Laboratorio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    use PublishesHeadquarters;
    use StoresImageUuid;

    protected $table = 'productos';

    protected $fillable = [
        'categoria',
        'laboratorio',
        'unidad',
        'familia',
        'codigo',
        'codigobar',
        'descripcion',
        'imagen',
        'estado',
        'producto_padre_id',
    ];

    protected $hidden = ['imagen'];

    protected function casts(): array
    {
        return [
            'categoria' => 'integer',
            'laboratorio' => 'integer',
            'unidad' => 'integer',
            'familia' => 'integer',
            'estado' => 'integer',
        ];
    }

    public function padreDetalle(): BelongsTo
    {
        return $this->belongsTo(self::class, 'producto_padre_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(self::class, 'producto_padre_id');
    }

    public function categoriaDetalle(): BelongsTo
    {
        return $this->belongsTo(ProdCategoria::class, 'categoria');
    }

    public function laboratorioDetalle(): BelongsTo
    {
        return $this->belongsTo(Laboratorio::class, 'laboratorio');
    }

    public function unidadDetalle(): BelongsTo
    {
        return $this->belongsTo(ProdUnidad::class, 'unidad');
    }

    public function Familia(): BelongsTo
    {
        return $this->belongsTo(Familia::class, 'familia');
    }

    public function estadoDetalle(): BelongsTo
    {
        return $this->belongsTo(ProdEstado::class, 'estado');
    }

    public function principiosActivos(): BelongsToMany
    {
        return $this->belongsToMany(PrincipalActivo::class, 'prod_activo', 'producto', 'pactivo')
            ->withTimestamps();
    }

    public function asociacionesActivas(): HasMany
    {
        return $this->hasMany(ProdActivo::class, 'producto');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class, 'producto');
    }
}
