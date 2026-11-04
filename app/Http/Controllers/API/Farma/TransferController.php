<?php

namespace App\Http\Controllers\API\Farma;

use App\Http\Controllers\Controller;
use App\Http\Requests\Farma\Transferencias\TransferSendRequest;
use App\Http\Resources\Farma\LotResource;
use App\Http\Resources\Farma\TransferResource;
use App\Interfaces\Config\DeviceInfoService;
use App\Interfaces\Farma\TransferService;
use App\Models\Farma\TransferenciaTipo;
use App\Services\Farma\TransferProductConversion;
use App\Services\Farma\TransferStockScope;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TransferController extends Controller
{
    public function __construct(
        private readonly TransferService $repository,
        private readonly DeviceInfoService $deviceInfoService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $scope = app(TransferStockScope::class);
        $stockId = $scope->stockId($request, $this->stockId($request));

        if ($stockId === null) {
            return $this->sendError('El dispositivo no tiene un stock válido asignado.', null, 422);
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'direccion' => ['nullable', 'in:todas,enviadas,recibidas'],
            'estado' => ['nullable', 'integer', 'exists:transferencia_estado,id'],
            'estado_recepcion' => ['nullable', 'integer', 'exists:transferencia_estado,id'],
            'tipo' => ['nullable', 'integer', 'exists:transferencias_tipo,id'],
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('fecha_desde') ? ['after_or_equal:fecha_desde'] : []),
        ]);

        $response = $this->sendResponse(
            TransferResource::collection($this->repository->paginate(
                $stockId,
                (int) ($validated['per_page'] ?? 30),
                $validated,
            )),
            'ok',
            200,
            true,
        );

        $payload = $response->getData(true);
        $payload['meta']['tipos'] = $request->boolean('compact') ? [] : TransferenciaTipo::query()->orderBy('descripcion')->get(['id', 'descripcion'])->toArray();

        if (! $request->boolean('compact') && $scope->canFilter($request)) {
            $payload['meta']['stocks'] = $scope->stocks();
        }

        return $response->setData($payload);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $stockId = app(TransferStockScope::class)->stockId($request, $this->stockId($request));

        if ($stockId === null) {
            return $this->sendError('El dispositivo no tiene un stock válido asignado.', null, 422);
        }

        $transfer = $this->repository->findForStock($id, $stockId);

        return $transfer === null
            ? $this->sendError('Transferencia no encontrada para este stock.', null, 404)
            : $this->sendResponse(TransferResource::make($transfer), 'ok');
    }

    public function options(Request $request): JsonResponse
    {
        $stockId = $this->stockId($request);

        return $stockId === null
            ? $this->sendError('El dispositivo no tiene un stock válido asignado.', null, 422)
            : $this->sendResponse($this->repository->options($stockId, $request->boolean('compact')), 'ok');
    }

    public function lots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'producto_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->sendResponse(
            LotResource::collection($this->repository->paginateLots(
                (int) ($validated['per_page'] ?? 30),
                (string) ($validated['search'] ?? ''),
                isset($validated['producto_id']) ? (int) $validated['producto_id'] : null,
            )),
            'ok',
            200,
            true,
        );
    }

    public function send(TransferSendRequest $request): JsonResponse
    {
        $stockId = $this->stockId($request);
        $userId = $request->user()?->getAuthIdentifier();

        if ($stockId === null || $userId === null) {
            return $this->sendError('No fue posible identificar al usuario y stock de origen.', null, 422);
        }

        try {
            $transfer = $this->repository->send($stockId, (int) $userId, $request->validated());

            return $this->sendResponse(TransferResource::make($transfer), 'Transferencia guardada correctamente.', 201);
        } catch (DomainException $exception) {
            return $this->sendError($exception->getMessage(), null, 422);
        } catch (Throwable $throwable) {
            $this->logError('TransferController send', $throwable);

            return $this->sendError('No se pudo guardar la transferencia.', null, 500);
        }
    }

    public function conversionOptions(Request $request, int $id, TransferProductConversion $conversion): JsonResponse
    {
        if ($this->stockId($request) === null) {
            return $this->sendError('El dispositivo no tiene un stock válido asignado.', null, 422);
        }
        try {
            return $this->sendResponse($conversion->options($id, $request->boolean('compact')), 'ok');
        } catch (DomainException $exception) {
            return $this->sendError($exception->getMessage(), null, 422);
        }
    }

    public function convertProduct(Request $request, TransferProductConversion $conversion): JsonResponse
    {
        $stockId = $this->stockId($request);
        $userId = $request->user()?->getAuthIdentifier();
        if ($stockId === null || $userId === null) {
            return $this->sendError('No fue posible identificar al usuario y stock de origen.', null, 422);
        }
        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            'lote_padre' => ['required', 'integer', 'min:1'],
            'producto_hijo' => ['required', 'integer', 'min:1'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        foreach (['lote_padre', 'producto_hijo', 'cantidad'] as $field) {
            $validated[$field] = (int) $validated[$field];
        }
        try {
            return $this->sendResponse($conversion->convert($stockId, (int) $userId, $validated), 'Producto convertido correctamente.');
        } catch (DomainException $exception) {
            return $this->sendError($exception->getMessage(), null, 422);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $stockId = $this->stockId($request);
        $userId = $request->user()?->getAuthIdentifier();

        if ($stockId === null || $userId === null) {
            return $this->sendError('No fue posible identificar al usuario y stock de origen.', null, 422);
        }

        $validated = $request->validate([
            'detalles' => ['sometimes', 'array', 'max:200'],
            'detalles.*.lote' => ['required', 'integer', 'distinct', 'exists:lotes,id'],
            'detalles.*.cantidad' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        try {
            $transfer = $this->repository->updateDetails($id, $stockId, $validated);

            return $this->sendResponse(TransferResource::make($transfer), 'Transferencia actualizada correctamente.');
        } catch (DomainException $exception) {
            return $this->sendError($exception->getMessage(), null, 422);
        } catch (Throwable $throwable) {
            $this->logError('TransferController update', $throwable);

            return $this->sendError('No se pudo actualizar los detalles de la transferencia.', null, 500);
        }
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $stockId = $this->stockId($request);
        $userId = $request->user()?->getAuthIdentifier();

        if ($stockId === null || $userId === null) {
            return $this->sendError('No fue posible identificar al usuario y stock de destino.', null, 422);
        }

        try {
            $transfer = $this->repository->receive($id, $stockId, (int) $userId);

            return $transfer === null
                ? $this->sendError('Transferencia no encontrada.', null, 404)
                : $this->sendResponse(TransferResource::make($transfer), 'Transferencia recibida correctamente.');
        } catch (DomainException $exception) {
            return $this->sendError($exception->getMessage(), null, 422);
        } catch (Throwable $throwable) {
            $this->logError('TransferController receive', $throwable);

            return $this->sendError('No se pudo recibir la transferencia.', null, 500);
        }
    }

    private function stockId(Request $request): ?int
    {
        return $this->deviceInfoService->stockId($request->attributes->get('authenticated_device'));
    }
}
