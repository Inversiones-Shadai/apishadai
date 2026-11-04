<?php

namespace App\Http\Controllers\API\Farma;

use App\DTO\LaboratorioDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Farma\Laboratorios\LaboratorioRequest;
use App\Http\Requests\Farma\Laboratorios\LaboratorioUpdateRequest;
use App\Http\Requests\Util\TableSearchRequest;
use App\Http\Resources\Laboratorios\LaboratorioResource;
use App\Interfaces\Laboratorios\LaboratorioService;
use App\Services\Images\ImageStorage;
use App\Utils\Services\Base64UtilityService;
use Illuminate\Http\JsonResponse;
use Throwable;

class LaboratoriosController extends Controller
{
    public function __construct(
        private readonly LaboratorioService $service,
        private readonly Base64UtilityService $base64UtilityService,
    ) {}

    public function index(TableSearchRequest $request): JsonResponse
    {
        try {
            return $this->sendResponse(
                LaboratorioResource::collection($this->service->searchPage((string) $request->validated('search', ''), ['nombre', 'telefono', 'direccion'], [])),
                'ok',
                200,
                true,
            );
        } catch (Throwable $throwable) {
            $this->logError('LaboratoriosController index', $throwable);

            return $this->sendError('Error al listar laboratorios.', null, 500);
        }
    }

    public function store(LaboratorioRequest $request): JsonResponse
    {
        try {
            $dto = LaboratorioDTO::onCreate($request->validated());
            $data = $dto->toArray();

            if ($dto->imagen !== null) {
                $image = $this->base64UtilityService->sanitize($dto->imagen);

                if ($image === null) {
                    return $this->sendError('Imagen inválida.', false, 422);
                }

                $data['imagen'] = $image;
            }

            if (! $this->service->create($data)) {
                return $this->sendError('No se pudo crear el laboratorio.', false, 422);
            }

            return $this->sendResponse(true, 'Laboratorio creado correctamente.', 201);
        } catch (Throwable $throwable) {
            $this->logError('LaboratoriosController store', $throwable);

            return $this->sendError('Error al crear laboratorio.', false, 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $laboratorio = $this->service->findById($id);

            if ($laboratorio === null) {
                return $this->sendError('Laboratorio no encontrado.', null, 404);
            }

            return $this->sendResponse(LaboratorioResource::make($laboratorio), 'ok');
        } catch (Throwable $throwable) {
            $this->logError('LaboratoriosController show', $throwable);

            return $this->sendError('Error al buscar laboratorio.', null, 500);
        }
    }

    public function update(LaboratorioUpdateRequest $request): JsonResponse
    {
        try {
            $dto = LaboratorioDTO::fromUpdateRequest($request->validated());
            $data = $dto->toArray();

            if ($dto->imagen !== null) {
                $image = $this->base64UtilityService->sanitize($dto->imagen);

                if ($image === null) {
                    return $this->sendError('Imagen inválida.', false, 422);
                }

                $data['imagen'] = $image;
            }

            if (! $this->service->update((int) $dto->id, $data)) {
                return $this->sendError('No se pudo actualizar el laboratorio.', false, 404);
            }

            return $this->sendResponse(true, 'Laboratorio actualizado correctamente.');
        } catch (Throwable $throwable) {
            $this->logError('LaboratoriosController update', $throwable);

            return $this->sendError('Error al actualizar laboratorio.', null, 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            if (! $this->service->delete($id)) {
                return $this->sendError('Laboratorio no disponible para eliminar.', false, 404);
            }

            return $this->sendResponse(true, 'Laboratorio eliminado correctamente.');
        } catch (Throwable $throwable) {
            $this->logError('LaboratoriosController destroy', $throwable);

            return $this->sendError('Error al eliminar laboratorio.', null, 500);
        }
    }

    public function getImage(int $id): JsonResponse
    {
        try {
            $stored = $this->service->getImage($id);
            $stored = app(ImageStorage::class)->base64($stored);

            if ($stored === null) {
                return $this->sendError('Imagen no encontrada.', null, 404);
            }

            $image = $this->base64UtilityService->validate($stored);

            if ($image === null) {
                $image = $this->base64UtilityService->sanitize($stored);

                if ($image !== null) {
                    $this->service->update($id, ['imagen' => $image]);
                }
            }

            if ($image === null) {
                return $this->sendError('La imagen almacenada no es válida.', null, 422);
            }

            return $this->sendResponse(['image' => $image], 'ok');
        } catch (Throwable $throwable) {
            $this->logError('LaboratoriosController getImage', $throwable);

            return $this->sendError('Error al obtener la imagen del laboratorio.', null, 500);
        }
    }
}
