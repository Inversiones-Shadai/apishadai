<?php

namespace App\Repositories\Config;

use App\Interfaces\Config\AccionesVistaService;
use App\Interfaces\Config\ModulosRepositoryInterface;
use App\Interfaces\Config\VistaEstadosService;
use App\Interfaces\Config\VistaRepositoryInterface;
use App\Models\Vistas;
use App\Repositories\Repository;
use App\Support\TableSearch;

class VistaRepository extends Repository implements VistaRepositoryInterface
{
    public function __construct(
        Vistas $model,
        private readonly VistaEstadosService $vistaEstadosService,
        private readonly ModulosRepositoryInterface $modulosService,
        private readonly AccionesVistaService $accionesVistaService
    ) {
        parent::__construct($model);
        $this->defaultRelations = ['estado', 'modulo'];
    }

    public function exist_samenameWhithModuleId(string $name, int $module): ?Vistas
    {
        return $this->whereFirst(['nombre' => $name, 'modulo' => $module]);
    }

    public function findbyModule(int $moduleId, string $search = '')
    {
        return TableSearch::apply($this->buildBaseQuery()->where('modulo', $moduleId), $search,
            ['nombre', 'codigo'], ['Estado' => ['descripcion'], 'Modulo' => ['nombre']])->get();
    }

    public function findByModuloId(int $moduloId)
    {
        return $this->whereList(['modulo' => $moduloId]);
    }

    public function estadosList()
    {
        return $this->vistaEstadosService->getAll();
    }

    public function modulosList()
    {
        return $this->modulosService->getAll();
    }

    public function acctionList(int $vistaId)
    {
        return $this->accionesVistaService->findByVista($vistaId);
    }

    public function deleteAccion(int $id)
    {
        return $this->accionesVistaService->delete($id);
    }

    public function createAccion(array $data)
    {
        return $this->accionesVistaService->create($data);
    }
}
