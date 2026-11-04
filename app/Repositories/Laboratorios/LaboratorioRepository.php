<?php

namespace App\Repositories\Laboratorios;

use App\Interfaces\Laboratorios\LaboratorioService;
use App\Models\Laboratorio;
use App\Repositories\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

use function array_key_exists;
use function is_string;

class LaboratorioRepository extends Repository implements LaboratorioService
{
    private const int IMAGE_MAX_LENGTH = 2796204;

    public function __construct(Laboratorio $model)
    {
        parent::__construct($model);
        $this->perPage = 30;
        $this->orderBy = ['id', 'DESC'];
    }

    public function paginate(): LengthAwarePaginator
    {
        return $this->buildBaseQuery()
            ->orderByDesc('id')
            ->paginate($this->perPage);
    }

    protected function buildBaseQuery(): Builder
    {
        return parent::buildBaseQuery()
            ->select(['id', 'nombre', 'telefono', 'direccion', 'created_at', 'updated_at'])
            ->selectRaw('imagen IS NOT NULL AND imagen <> \'\' AS tiene_imagen, CASE WHEN LENGTH(imagen) = 36 THEN imagen ELSE NULL END AS imagen_uuid');
    }

    public function update(int $id, array $data): bool
    {
        return DB::transaction(function () use ($id, $data): bool {
            $laboratorio = $this->model->newQuery()->lockForUpdate()->find($id);

            if ($laboratorio === null) {
                return false;
            }

            return $laboratorio->update($this->sanitizeData($data));
        });
    }

    public function getImage(int $id): ?string
    {
        $image = $this->model->newQuery()->whereKey($id)->value('imagen');

        return is_string($image) && $image !== '' ? $image : null;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function sanitizeData(array $data): array
    {
        $sanitized = [];

        foreach (['nombre', 'telefono', 'direccion'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = trim(strip_tags((string) $data[$field]));
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
            $sanitized[$field] = mb_substr(
                $value,
                0,
                $field === 'nombre' ? 180 : ($field === 'telefono' ? 20 : 255),
            );
        }

        if (array_key_exists('imagen', $data)) {
            $image = trim((string) $data['imagen']);

            if ($image !== '' && strlen($image) <= self::IMAGE_MAX_LENGTH) {
                $sanitized['imagen'] = $image;
            }
        }

        return $sanitized;
    }
}
