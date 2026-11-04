<?php

namespace App\Models;

use App\Models\Concerns\PublishesHeadquarters;
use App\Models\Concerns\StoresImageUuid;
use Illuminate\Database\Eloquent\Model;

class Laboratorio extends Model
{
    use PublishesHeadquarters;
    use StoresImageUuid;

    protected $table = 'laboratorios';

    protected $fillable = [
        'nombre',
        'telefono',
        'direccion',
        'imagen',
    ];
}
