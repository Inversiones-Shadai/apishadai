<?php

namespace App\Http\Controllers\API\Farma\Compras;

use App\Http\Controllers\Controller;
use App\Http\Requests\Farma\Compras\PurchaseCatalogRequest;
use App\Interfaces\Farma\PurchaseCatalogService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseCatalogController extends Controller
{
    public function __construct(private readonly PurchaseCatalogService $repository) {}

    public function index(Request $request, string $catalogoCompra): JsonResponse
    {
        $records = $this->repository
            ->paginate($catalogoCompra, $request->integer('per_page', 30), $request->string('search')->toString())
            ->through(fn (object $record): array => $this->record($record));

        return $this->sendResponse($records, 'ok', 200, true);
    }

    public function store(PurchaseCatalogRequest $request, string $catalogoCompra): JsonResponse
    {
        $record = $this->repository->create($catalogoCompra, $request->string('descripcion')->toString());

        return $this->sendResponse($this->record($record), 'Registro creado correctamente.', 201);
    }

    public function show(string $catalogoCompra, int $id): JsonResponse
    {
        $record = $this->repository->find($catalogoCompra, $id);

        return $record === null ? $this->sendError('Registro no encontrado.', null, 404) : $this->sendResponse($this->record($record), 'ok');
    }

    public function update(PurchaseCatalogRequest $request, string $catalogoCompra): JsonResponse
    {
        $record = $this->repository->update($catalogoCompra, $request->integer('id'), $request->string('descripcion')->toString());

        return $record === null ? $this->sendError('Registro no encontrado.', null, 404) : $this->sendResponse($this->record($record), 'Registro actualizado correctamente.');
    }

    public function destroy(string $catalogoCompra, int $id): JsonResponse
    {
        try {
            return $this->repository->delete($catalogoCompra, $id)
                ? $this->sendResponse(true, 'Registro eliminado correctamente.')
                : $this->sendError('Registro no encontrado.', null, 404);
        } catch (QueryException) {
            return $this->sendError('El registro está relacionado con compras o transacciones.', null, 409);
        }
    }

    private function record(object $record): array
    {
        return ['id' => (int) $record->getKey(), 'descripcion' => (string) $record->getAttribute('descripcion')];
    }
}
