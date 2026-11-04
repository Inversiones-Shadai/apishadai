<?php

namespace App\Repositories\Farma;

use App\DTO\Farma\FamiliaData;
use App\Interfaces\Farma\FamAdministracionService;
use App\Interfaces\Farma\FamiliaService;
use App\Interfaces\Farma\FamPresentacionService;
use App\Models\Farma\Familia;
use App\Repositories\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class FamiliaRepository extends Repository implements FamiliaService
{
    public function __construct(
        Familia $model,
        private readonly FamAdministracionService $administracionService,
        private readonly FamPresentacionService $presentacionService,
    ) {
        parent::__construct($model);
        $this->perPage = 30;
        $this->orderBy = ['id', 'DESC'];
        $this->defaultRelations = ['Presentacion', 'Administracion'];
    }

    public function lookupPage(string $search, int $perPage = 30): LengthAwarePaginator
    {
        $this->setPerPage(max(1, min(50, $perPage)));

        return $this->searchPage($search, ['descripcion'], [
            'Presentacion' => ['descripcion'],
            'Administracion' => ['descripcion'],
        ]);
    }

    public function administraciones(): Collection
    {
        return $this->administracionService->getAll();
    }

    public function presentaciones(): Collection
    {
        return $this->presentacionService->getAll();
    }

    /** @param array<string, mixed> $data */
    protected function sanitizeData(array $data): array
    {
        return FamiliaData::sanitize($data);
    }
}
