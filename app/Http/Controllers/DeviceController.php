<?php

namespace App\Http\Controllers;

use App\DTO\DeviceDTO;
use App\Http\Requests\Device\DeviceRequest;
use App\Http\Requests\Device\DeviceUpdateRequest;
use App\Http\Requests\Util\TableSearchRequest;
use App\Http\Resources\Device\DeviceResource;
use App\Http\Resources\DeviceEstadoResource;
use App\Http\Resources\Stock\StockResourceCbx;
use App\Interfaces\Config\DeviceService;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceService $deviceService
    ) {}

    public function index(TableSearchRequest $request): JsonResponse
    {
        try {
            $paginator = $this->deviceService->searchPage((string) $request->validated('search', ''), ['displayname', 'hq_pairing_uuid'], ['Stock' => ['descripcion'], 'Estado' => ['descripcion']]);

            $devices = DeviceResource::collection($paginator);

            return $this->sendResponse(
                $devices,
                'ok',
                200,
                true
            );
        } catch (\Exception $e) {
            $this->logError('DeviceController@index', $e);

            return $this->sendError('Error al obtener los dispositivos: '.$e->getMessage(), null, 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $device = $this->deviceService->findById($id);
            if (! $device) {
                return $this->sendError('Dispositivo no encontrado', null, 404);
            }

            return $this->sendResponse(
                DeviceResource::make($device),
                'ok',
                200
            );
        } catch (\Exception $e) {
            $this->logError('DeviceController@show', $e);

            return $this->sendError('Error al buscar el dispositivo', null, 500);
        }
    }

    public function store(DeviceRequest $request): JsonResponse
    {
        try {
            $dto = DeviceDTO::onCreate($request->validated());
            $created = $this->deviceService->create($dto->toArray());

            if (! $created) {
                return $this->sendError(
                    'No se pudo crear el dispositivo.',
                    false,
                    422
                );
            }

            return $this->sendResponse(true, 'Dispositivo creado correctamente', 201);
        } catch (\Exception $e) {
            $this->logError('DeviceController@store', $e);

            return $this->sendError('Error al crear el dispositivo', false, 500);
        }
    }

    public function update(DeviceUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $dto = DeviceDTO::fromUpdateRequest($request->validated(), $request['id']);
            $updated = $this->deviceService->update($id, $dto->toUpdateArray());

            if (! $updated) {
                return $this->sendError(
                    'No se pudo actualizar el dispositivo.',
                    false,
                    404
                );
            }

            return $this->sendResponse(true, 'Dispositivo actualizado correctamente', 200);
        } catch (\Exception $e) {
            $this->logError('DeviceController@update', $e);

            return $this->sendError("Error al actualizar el dispositivo:{$request['id']}", null, 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $deleted = $this->deviceService->delete($id);

            if (! $deleted) {
                return $this->sendError(
                    'Dispositivo no encontrado o no se pudo eliminar',
                    false,
                    404
                );
            }

            return $this->sendResponse(true, 'Dispositivo eliminado correctamente', 200);
        } catch (\Exception $e) {
            $this->logError('DeviceController@destroy', $e);

            return $this->sendError('Error al eliminar el dispositivo', null, 500);
        }
    }

    public function stockList(): JsonResponse
    {
        try {
            $list = $this->deviceService->get_stocksList();

            return $this->sendResponse(
                StockResourceCbx::collection($list),
                'ok',
                200,
                false
            );
        } catch (\Exception $e) {
            $this->logError('DeviceController@stockList', $e);

            return $this->sendError('Error al buscar dispositivos por stock', null, 500);
        }
    }

    public function estadosList(): JsonResponse
    {
        try {
            $list = $this->deviceService->get_estadosList();

            return $this->sendResponse(
                DeviceEstadoResource::collection($list),
                'ok',
                200,
                false
            );
        } catch (\Exception $e) {
            $this->logError('DeviceController@estadosList', $e);

            return $this->sendError('Error al buscar dispositivos por estado', null, 500);
        }
    }
}
