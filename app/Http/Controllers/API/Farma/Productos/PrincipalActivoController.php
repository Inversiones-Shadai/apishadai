<?php

namespace App\Http\Controllers\API\Farma\Productos;

use App\DTO\Farma\PrincipalActivoData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Farma\Productos\ConcentracionFilterRequest;
use App\Http\Requests\Farma\Productos\PrincipalActivoFilterRequest;
use App\Http\Requests\Farma\Productos\PrincipalActivoRequest;
use App\Http\Requests\Util\TableSearchRequest;
use App\Http\Resources\Farma\ConcentracionResource;
use App\Http\Resources\Farma\PrincipalActivoResource;
use App\Interfaces\Farma\ConcentracionService;
use App\Interfaces\Farma\PrincipalActivoService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Throwable;

class PrincipalActivoController extends Controller
{
    public function __construct(
        private readonly PrincipalActivoService $repository,
        private readonly ConcentracionService $concentraciones,
    ) {}

    public function index(TableSearchRequest $request): JsonResponse
    {
        return $this->sendResponse(
            PrincipalActivoResource::collection($this->repository->searchPage((string) $request->validated('search', ''), ['id', 'nombre'], ['concentracionDetalle' => ['valor']])),
            'ok',
            200,
            true,
        );
    }

    public function concentraciones(): JsonResponse
    {
        return $this->sendResponse(
            ConcentracionResource::collection($this->concentraciones->paginate()),
            'ok',
            200,
            true,
        );
    }

    public function filterPrincipiosActivos(PrincipalActivoFilterRequest $request): JsonResponse
    {
        return $this->sendResponse(
            PrincipalActivoResource::collection($this->repository->filterAll($request->toFilterModel())),
            'ok',
        );
    }

    public function filterConcentraciones(ConcentracionFilterRequest $request): JsonResponse
    {
        return $this->sendResponse(
            ConcentracionResource::collection($this->concentraciones->filterAll($request->toFilterModel())),
            'ok',
        );
    }

    public function store(PrincipalActivoRequest $request): JsonResponse
    {
        try {
            $data = PrincipalActivoData::fromValidated($request->validated());
            if (! $this->repository->create($data->toArray())) {
                return $this->sendError('No se pudo crear el registro.', false, 422);
            }

            return $this->sendResponse(
                true,
                'Registro creado correctamente.',
                201,
            );
        } catch (Throwable $throwable) {
            $this->logError('PrincipalActivoController store', $throwable);

            return $this->databaseError($throwable, 'No se pudo crear el registro.');
        }
    }

    public function show(int $id): JsonResponse
    {
        $model = $this->repository->findById($id);

        return $model === null
            ? $this->sendError('Registro no encontrado.', null, 404)
            : $this->sendResponse(PrincipalActivoResource::make($model), 'ok');
    }

    public function update(PrincipalActivoRequest $request, int $id): JsonResponse
    {
        try {
            $data = PrincipalActivoData::fromValidated($request->validated());
            $updated = $this->repository->update($id, $data->toArray());

            return ! $updated
                ? $this->sendError('Registro no encontrado.', false, 404)
                : $this->sendResponse(true, 'Registro actualizado correctamente.');
        } catch (Throwable $throwable) {
            $this->logError('PrincipalActivoController update', $throwable);

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
            $this->logError('PrincipalActivoController destroy', $throwable);

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
