<?php

namespace App\Http\Controllers\API;

use App\DTO\StockDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\StockRequest;
use App\Http\Requests\StockUpdateRequest;
use App\Http\Requests\Util\TableSearchRequest;
use App\Http\Resources\Stock\StockEstadoResource;
use App\Http\Resources\Stock\StocksResource;
use App\Interfaces\Config\StockRepositoryInterface;
use Illuminate\Http\JsonResponse;

class StocksController extends Controller
{
    public function __construct(private StockRepositoryInterface $service) {}

    /**
     * Display a listing of the resource.
     */
    public function index(TableSearchRequest $request): JsonResponse
    {
        try {
            $list = $this->service->searchPage((string) $request->validated('search', ''), ['descripcion', 'telefono', 'ubicacion'], ['Estado' => ['descripcion']]);

            return $this->sendResponse(StocksResource::collection($list), 'ok', 200, true);
        } catch (\Throwable $th) {
            $this->logError('StocksController index', $th);

            return $this->sendError('Error al listar stocks.', null, 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StockRequest $request): JsonResponse
    {
        try {
            $dto = StockDTO::onCreate($request->validated());
            $created = $this->service->create($dto->toArray());
            if (! $created) {
                return $this->sendError('Error al crear stock', false, 422);
            }

            return $this->sendResponse(true, 'Stock creado correctamente', 201);
        } catch (\Throwable $th) {
            $this->logError('StocksController store', $th);

            return $this->sendError('Error al crear stock.', false, 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $item = $this->service->findById($id);
            if (! $item) {
                return $this->sendError('Stock no encontrado', null, 404);
            }

            return $this->sendResponse(StocksResource::make($item), 'ok');
        } catch (\Throwable $th) {
            $this->logError('StocksController show', $th);

            return $this->sendError('Error al buscar stock.', null, 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StockUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $dto = StockDTO::fromUpdateRequest($request->validated());
            $updated = $this->service->update($id, $dto->toUpdateArray());

            if (! $updated) {
                return $this->sendError('No se pudo actualizar el stock.', false, 404);
            }

            return $this->sendResponse(true, 'Stock actualizado correctamente', 200);
        } catch (\Throwable $th) {
            $this->logError('StocksController update', $th);

            return $this->sendError('Error al actualizar stock.', null, 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $deleted = $this->service->delete($id);
            if (! $deleted) {
                return $this->sendError('Stock no disponible para eliminar.', false, 404);
            }

            return $this->sendResponse(true, 'Stock eliminado correctamente', 200);
        } catch (\Throwable $th) {
            $this->logError('StocksController destroy', $th);

            return $this->sendError('Error al eliminar stock.', null, 500);
        }
    }

    public function estadosList(): JsonResponse
    {
        try {
            $list = $this->service->get_estadosList();

            return $this->sendResponse(
                StockEstadoResource::collection($list),
                'ok',
                200,
                false
            );
        } catch (\Throwable $th) {
            $this->logError('StocksController estadosList', $th);

            return $this->sendError('Error al listar estados de stock.', null, 500);
        }
    }
}
