<?php

namespace App\Http\Controllers\API\Farma\Productos;

use App\DTO\Farma\FamiliaData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Farma\Productos\FamiliaRequest;
use App\Http\Requests\Util\TableSearchRequest;
use App\Http\Resources\Farma\FamiliaOptionResource;
use App\Http\Resources\Farma\FamiliaResource;
use App\Interfaces\Farma\FamiliaService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Throwable;

use function in_array;

class FamiliaController extends Controller
{
    public function __construct(private readonly FamiliaService $repository) {}

    public function index(TableSearchRequest $request): JsonResponse
    {
        return $this->sendResponse(
            FamiliaResource::collection($this->repository->searchPage((string) $request->validated('search', ''), ['id', 'descripcion'], ['Presentacion' => ['descripcion'], 'Administracion' => ['descripcion']])),
            'ok',
            200,
            true,
        );
    }

    public function administraciones(): JsonResponse
    {
        return $this->sendResponse(
            FamiliaOptionResource::collection($this->repository->administraciones()),
            'ok',
        );
    }

    public function presentaciones(): JsonResponse
    {
        return $this->sendResponse(
            FamiliaOptionResource::collection($this->repository->presentaciones()),
            'ok',
        );
    }

    public function store(FamiliaRequest $request): JsonResponse
    {
        try {
            $data = FamiliaData::fromValidated($request->validated());
            if (! $this->repository->create($data->toArray())) {
                return $this->sendError('No se pudo crear el registro.', false, 422);
            }

            return $this->sendResponse(
                true,
                'Registro creado correctamente.',
                201,
            );
        } catch (Throwable $throwable) {
            $this->logError('FamiliaController store', $throwable);

            return $this->databaseError($throwable, 'No se pudo crear el registro.');
        }
    }

    public function show(int $id): JsonResponse
    {
        $model = $this->repository->findById($id);

        return $model === null
            ? $this->sendError('Registro no encontrado.', null, 404)
            : $this->sendResponse(FamiliaResource::make($model), 'ok');
    }

    public function update(FamiliaRequest $request, int $id): JsonResponse
    {
        try {
            $data = FamiliaData::fromValidated($request->validated());
            $updated = $this->repository->update($id, $data->toArray());

            return ! $updated
                ? $this->sendError('Registro no encontrado.', false, 404)
                : $this->sendResponse(true, 'Registro actualizado correctamente.');
        } catch (Throwable $throwable) {
            $this->logError('FamiliaController update', $throwable);

            return $this->databaseError($throwable, 'No se pudo actualizar el registro.');
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            return $this->repository->delete($id)
                ? $this->sendResponse(true, 'Registro eliminado correctamente.')
                : $this->sendError('Registro no encontrado.', false, 404);
        } catch (Throwable $throwable) {
            $this->logError('FamiliaController destroy', $throwable);

            return $this->databaseError($throwable, 'No se pudo eliminar el registro.');
        }
    }

    private function databaseError(Throwable $throwable, string $fallback): JsonResponse
    {
        if ($throwable instanceof QueryException && in_array((string) $throwable->getCode(), ['23000', '23503'], true)) {
            return $this->sendError(
                'El registro está duplicado o se encuentra relacionado con otros datos.',
                false,
                409,
            );
        }

        return $this->sendError($fallback, false, 500);
    }
}
