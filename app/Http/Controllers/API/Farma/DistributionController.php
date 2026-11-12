<?php

namespace App\Http\Controllers\API\Farma;

use App\Http\Controllers\Controller;
use App\Http\Requests\Farma\Distribucion\CatalogLotRequest;
use App\Http\Requests\Farma\Distribucion\DistributionRequest;
use App\Http\Resources\Farma\DistributionLotResource;
use App\Http\Resources\Farma\DistributionProductResource;
use App\Http\Resources\Farma\DistributionResource;
use App\Interfaces\Farma\DistributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DistributionController extends Controller
{
    public function __construct(private readonly DistributionService $repository) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->sendResponse(
            DistributionProductResource::collection($this->repository->paginateProducts(
                (int) ($validated['per_page'] ?? 30),
                isset($validated['search']) ? (string) $validated['search'] : null,
            )),
            'ok',
            200,
            true,
        );
    }

    public function productLots(int $product): JsonResponse
    {
        $lots = $this->repository->productLots($product);

        return $lots->isEmpty()
            ? $this->sendError('El producto no tiene lotes registrados.', null, 404)
            : $this->sendResponse(DistributionLotResource::collection($lots), 'Lotes encontrados.');
    }

    public function save(DistributionRequest $request, int $lot): JsonResponse
    {
        $distribution = $this->repository->saveForLot($lot, $request->validated());

        return $distribution === null
            ? $this->sendError('El lote seleccionado no existe.', null, 404)
            : $this->sendResponse(new DistributionResource($distribution), 'Precios y descuentos guardados.');
    }

    public function createLot(CatalogLotRequest $request): JsonResponse
    {
        try {
            $lot = $this->repository->createCatalogLot($request->validated());
        } catch (ValidationException $exception) {
            return $this->sendError('Revisa los datos del lote.', ['errors' => $exception->errors()], 422);
        }

        return $this->sendResponse(new DistributionLotResource($lot), 'Lote registrado en el catálogo. Las existencias de los stocks no se modificaron.', 201);
    }
}
