<?php

namespace App\Http\Controllers\API\Farma;

use App\DTO\ProveedoresDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Farma\Proveedores\ProveedoresRequest;
use App\Http\Requests\Farma\Proveedores\ProveedoresUpdateRequest;
use App\Http\Requests\Util\TableSearchRequest;
use App\Http\Resources\Proveedores\ProveedoresResource;
use App\Interfaces\Proveedores\ProveedoresService;
use App\Services\Images\ImageStorage;
use App\Utils\Services\Base64UtilityService;
use Illuminate\Http\JsonResponse;

class ProveedoresController extends Controller
{
    public function __construct(
        private readonly ProveedoresService $service,
        private readonly Base64UtilityService $base64UtilityService
    ) {}

    public function index(TableSearchRequest $request): JsonResponse
    {
        try {
            $list = $this->service->searchPage((string) $request->validated('search', ''), ['nombre', 'telefono', 'direccion'], []);

            return $this->sendResponse(ProveedoresResource::collection($list), 'ok', 200, true);
        } catch (\Throwable $th) {
            $this->logError('ProveedoresController index', $th);

            return $this->sendError('Error al listar proveedores.', null, 500);
        }
    }

    public function store(ProveedoresRequest $request): JsonResponse
    {
        try {
            $dto = ProveedoresDTO::onCreate($request->validated());
            $data = $dto->toArray();

            if ($dto->imagen !== null) {
                $sanitizedImage = $this->base64UtilityService->sanitize($dto->imagen);

                if ($sanitizedImage === null) {
                    return $this->sendError('Imagen inválida', false, 422);
                }

                $data['imagen'] = $sanitizedImage;
            }

            $created = $this->service->create($data);

            if (! $created) {
                return $this->sendError('Error al crear proveedor', false, 422);
            }

            return $this->sendResponse(true, 'Proveedor creado correctamente', 201);
        } catch (\Throwable $th) {
            $this->logError('ProveedoresController store', $th);

            return $this->sendError('Error al crear proveedor.', false, 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $item = $this->service->findById($id);

            if (! $item) {
                return $this->sendError('Proveedor no encontrado', null, 404);
            }

            return $this->sendResponse(ProveedoresResource::make($item), 'ok');
        } catch (\Throwable $th) {
            $this->logError('ProveedoresController show', $th);

            return $this->sendError('Error al buscar proveedor.', null, 500);
        }
    }

    public function update(ProveedoresUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $dto = ProveedoresDTO::fromUpdateRequest($request->validated());
            $data = $dto->toUpdateArray();

            if ($dto->imagen !== null) {
                $sanitizedImage = $this->base64UtilityService->sanitize($dto->imagen);

                if ($sanitizedImage === null) {
                    return $this->sendError('Imagen inválida', false, 422);
                }

                $data['imagen'] = $sanitizedImage;
            }

            $updated = $this->service->update($id, $data);

            if (! $updated) {
                return $this->sendError('No se pudo actualizar el proveedor.', false, 404);
            }

            return $this->sendResponse(true, 'Proveedor actualizado correctamente', 200);
        } catch (\Throwable $th) {
            $this->logError('ProveedoresController update', $th);

            return $this->sendError('Error al actualizar proveedor.', null, 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $deleted = $this->service->delete($id);

            if (! $deleted) {
                return $this->sendError('Proveedor no disponible para eliminar.', false, 404);
            }

            return $this->sendResponse(true, 'Proveedor eliminado correctamente', 200);
        } catch (\Throwable $th) {
            $this->logError('ProveedoresController destroy', $th);

            return $this->sendError('Error al eliminar proveedor.', null, 500);
        }
    }

    public function getImage(int $id): JsonResponse
    {
        try {
            $image = $this->service->getImage($id);
            $image = app(ImageStorage::class)->base64($image);

            if (! $image) {
                return $this->sendError('Imagen no encontrada', null, 404);
            }

            $sanitizedImage = $this->base64UtilityService->validate($image);

            if ($sanitizedImage === null) {
                $sanitizedImage = $this->base64UtilityService->sanitize($image);

                if ($sanitizedImage !== null) {
                    $this->service->update($id, ['imagen' => $sanitizedImage]);
                }
            }

            if ($sanitizedImage === null) {
                return $this->sendError('La imagen almacenada no es válida', null, 422);
            }

            return $this->sendResponse(['image' => $sanitizedImage], 'ok');
        } catch (\Throwable $th) {
            $this->logError('ProveedoresController getImage', $th);

            return $this->sendError('Error al obtener la imagen del proveedor.', null, 500);
        }
    }
}
