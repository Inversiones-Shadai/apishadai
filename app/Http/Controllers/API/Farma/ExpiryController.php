<?php

namespace App\Http\Controllers\API\Farma;

use App\Http\Controllers\Controller;
use App\Http\Requests\Farma\ExpirySearchRequest;
use App\Http\Requests\Farma\SupplierReturnRuleRequest;
use App\Http\Requests\Farma\VerifyExpiryRequest;
use App\Services\Farma\ExpiryService;
use Illuminate\Http\JsonResponse;

class ExpiryController extends Controller
{
    public function __construct(private readonly ExpiryService $service) {}

    public function products(ExpirySearchRequest $request): JsonResponse
    {
        return $this->sendResponse($this->service->products(
            (string) $request->input('search', ''), $request->filled('meses') ? $request->integer('meses') : null, $request->integer('per_page', 15),
        ), 'ok', 200, true);
    }

    public function lots(ExpirySearchRequest $request, int $product): JsonResponse
    {
        return $this->sendResponse($this->service->lots($product, $request->integer('per_page', 20), $request->filled('meses') ? $request->integer('meses') : null), 'ok', 200, true);
    }

    public function providers(ExpirySearchRequest $request): JsonResponse
    {
        return $this->sendResponse($this->service->providers((string) $request->input('search', ''), $request->integer('per_page', 15)), 'ok', 200, true);
    }

    public function policy(int $provider): JsonResponse
    {
        $data = $this->service->policy($provider);

        return $data === null ? $this->sendError('El proveedor no existe.', null, 404) : $this->sendResponse($data);
    }

    public function savePolicy(SupplierReturnRuleRequest $request, int $provider): JsonResponse
    {
        $result = $this->service->savePolicy($provider, $request->validated(), (int) $request->user()->getAuthIdentifier());

        return match ($result) {
            'missing' => $this->sendError('El proveedor no existe.', null, 404),
            'conflict' => $this->sendError('Las reglas cambiaron. Vuelve a abrir el proveedor antes de guardar.', null, 409),
            default => $this->sendResponse($this->service->policy($provider), 'Reglas de devolución guardadas.'),
        };
    }

    public function verify(VerifyExpiryRequest $request): JsonResponse
    {
        return $this->sendResponse($this->service->verify(array_map(static fn (array $item): array => [
            'id' => (int) $item['id'],
        ], $request->validated('lotes'))), 'Verificación completada.');
    }
}
