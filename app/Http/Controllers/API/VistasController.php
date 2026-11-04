<?php

namespace App\Http\Controllers\API;

use App\DTO\AccionesVistaDTO;
use App\DTO\VistaDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccionesVistaRequest;
use App\Http\Requests\Util\TableSearchRequest;
use App\Http\Requests\Vista\VistaRequest;
use App\Http\Requests\Vista\VistaUpdateRequest;
use App\Http\Resources\ActionsVistasResource;
use App\Http\Resources\Modulos\ModulosResourceCbx;
use App\Http\Resources\VistaEstadosResource;
use App\Http\Resources\Vistas\VistasResource;
use App\Interfaces\Config\AccionesVistaService;
use App\Interfaces\Config\VistaRepositoryInterface;
use Illuminate\Http\JsonResponse;

class VistasController extends Controller
{
    public function __construct(
        private VistaRepositoryInterface $service,
        private AccionesVistaService $accionesVistaService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(TableSearchRequest $request): JsonResponse
    {
        $list = $this->service->searchPage((string) $request->validated('search', ''), ['nombre', 'codigo'], ['Estado' => ['descripcion'], 'Modulo' => ['nombre']]);
        if (! $list) {
            return $this->sendResponse(null, 'No se encontraron informacion', 404);
        }

        return $this->sendResponse(
            VistasResource::collection($list),
            'success',
            200,
            true
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(VistaRequest $request): JsonResponse
    {
        $dto = VistaDTO::fromRequest($request->Validated());

        if ($this->service->exist_samenameWhithModuleId($dto->nombre, $dto->modulo) != null) {
            return $this->sendResponse(false, 'Ya existe una vista con el mismo nombre y modulo', 422);
        }

        try {
            $vista = $this->service->create($dto->toArray());
            if ($vista) {
                return $this->sendResponse(true, 'Vista creada');
            }

            return $this->sendResponse(false, 'No se pudo crear la informacion', 500);
        } catch (\Throwable $e) {
            $this->logError('Error al crear la vista: ', $e);

            return $this->sendResponse(false, 'No se pudo crear la informacion', 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $obj = $this->service->findById($id);
        if (! $obj) {
            return $this->sendResponse(null, 'No se encontro informacion', 404);
        }

        return $this->sendResponse(VistasResource::make($obj), 'success');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(VistaUpdateRequest $request, string $id): JsonResponse
    {
        try {
            $dto = VistaDTO::fromUpdateRequest($request->Validated());
            $update = $this->service->update($id, $dto->toUpdateArray());

            if ($update) {
                return $this->sendResponse(true, 'Vista actualizada');
            }

            return $this->sendResponse(false, 'No se econtro informacion', 500);
        } catch (\Throwable $th) {
            $this->logError('Error al actualizar la vista: ', $th);

            return $this->sendResponse(false, 'No se pudo actualizar la informacion', 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $delete = $this->service->delete($id);
        if (! $delete) {
            return $this->sendResponse(false, 'No se pudo eliminar la informacion', 500);
        }

        return $this->sendResponse(true, 'Vista eliminada');
    }

    public function findbyModule(TableSearchRequest $request, string $id): JsonResponse
    {
        $list = $this->service->findByModule((int) $id, (string) $request->validated('search', ''));
        if (! $list) {
            return $this->sendResponse(null, 'No se encontraron informacion', 404);
        }

        return $this->sendResponse(
            VistasResource::collection($list),
            'success',
            200,
            true
        );
    }

    public function estadosList(): JsonResponse
    {
        $list = $this->service->estadosList();
        if (! $list) {
            return $this->sendResponse(null, 'No se encontraron informacion', 404);
        }

        return $this->sendResponse(
            VistaEstadosResource::collection($list),
            'success',
            200,
            false
        );
    }

    public function modulosList(): JsonResponse
    {
        $list = $this->service->modulosList();
        if (! $list) {
            return $this->sendResponse(null, 'No se encontraron informacion', 404);
        }

        return $this->sendResponse(
            ModulosResourceCbx::collection($list),
            'success',
            200,
            false
        );
    }

    public function acctionList($vistaId): JsonResponse
    {
        $list = $this->service->acctionList($vistaId);
        if (! $list) {
            return $this->sendResponse(null, 'No se encontraron informacion', 404);
        }

        return $this->sendResponse(
            ActionsVistasResource::collection($list),
            'success',
            200,
            false
        );
    }

    public function deleteAccion($id): JsonResponse
    {
        $delete = $this->service->deleteAccion($id);
        if (! $delete) {
            return $this->sendResponse(false, 'No se pudo eliminar la informacion', 500);
        }

        return $this->sendResponse(true, 'Accion eliminada');
    }

    public function createAccion(AccionesVistaRequest $request): JsonResponse
    {
        try {
            $dto = AccionesVistaDTO::fromRequest($request->Validated());

            $existcodigo = $this->accionesVistaService->existCodigoEnVista($dto->vista, $dto->codigo);
            if ($existcodigo) {
                return $this->sendResponse(false, 'Ya existe una accion con el mismo codigo para esta vista', 422);
            }

            $existnombre = $this->accionesVistaService->existNombreEnVista($dto->vista, $dto->nombre);

            if ($existnombre) {
                return $this->sendResponse(false, 'Ya existe una accion con el mismo nombre para esta vista', 422);
            }

            $create = $this->service->createAccion($dto->toArray());

            if (! $create) {
                return $this->sendResponse(false, 'No se pudo crear la informacion', 500);
            }

            return $this->sendResponse(true, 'Accion creada');
        } catch (\Throwable $th) {
            $this->logError('Error al crear la accion de la vista: ', $th);

            return $this->sendResponse(false, 'No se pudo crear la informacion: '.$th->getMessage(), 500);
        }
    }
}
