<?php

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\Laboratorios\LaboratorioResource;
use App\Models\Laboratorio;
use Illuminate\Http\Request;
use Tests\TestCase;

class LaboratorioResourceTest extends TestCase
{
    public function test_it_serializes_a_laboratory_without_exposing_the_image_blob(): void
    {
        $laboratory = (new Laboratorio)->forceFill([
            'id' => 5,
            'nombre' => 'Laboratorio Central',
            'telefono' => null,
            'direccion' => 'Tegucigalpa',
            'imagen' => 'base64-content',
        ]);

        $data = (new LaboratorioResource($laboratory))
            ->toArray(Request::create('/'));

        $this->assertSame(5, $data['id']);
        $this->assertSame('Laboratorio Central', $data['nombre']);
        $this->assertTrue($data['tiene_imagen']);
        $this->assertArrayNotHasKey('imagen', $data);
    }
}
