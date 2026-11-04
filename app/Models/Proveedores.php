<?php

namespace App\Models;

use App\Models\Concerns\PublishesHeadquarters;
use Illuminate\Database\Eloquent\Model;

class Proveedores extends Model
{
    use \App\Models\Concerns\StoresImageUuid;

    use PublishesHeadquarters;

    protected $table = 'proveedores';

    protected $fillable = [
        'nombre',
        'telefono',
        'direccion',
        'imagen',
    ];
}
