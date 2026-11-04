<?php

namespace App\Interfaces\Config;

use App\Interfaces\RepositoryInterface;

interface VistaRepositoryInterface extends RepositoryInterface
{
    public function exist_samenameWhithModuleId(string $name, int $module);

    public function findbyModule(int $moduleId, string $search = '');

    public function findByModuloId(int $moduloId);

    public function estadosList();

    public function modulosList();

    public function acctionList(int $vistaId);

    public function deleteAccion(int $id);

    public function createAccion(array $data);
}
