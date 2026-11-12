<?php

namespace App\Models\Farma;

use App\Models\Concerns\PublishesHeadquarters;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProdCategoria extends Model
{
    use PublishesHeadquarters;

    protected $table = 'prod_categorias';

    protected $fillable = ['nombre'];

    public static function requiresFamily(string $name): bool
    {
        return in_array(mb_strtoupper(trim($name)), ['FARMACIA', 'CONGELADOS', 'CONTROLADOS', 'LECHES'], true);
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'categoria');
    }
}
