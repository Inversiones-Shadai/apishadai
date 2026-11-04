<?php

namespace App\Http\Controllers\API\Farma\Compras;

use App\DTO\Farma\PurchaseTransactionData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Farma\Compras\PurchaseTransactionRequest;
use App\Http\Resources\Farma\PurchaseTransactionResource;
use App\Interfaces\Farma\PurchaseTransactionService;
use App\Services\Images\InvoiceStorage;
use App\Utils\Services\Base64UtilityService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PurchaseTransactionController extends Controller
{
    public function __construct(
        private readonly PurchaseTransactionService $repository,
        private readonly Base64UtilityService $base64Utility,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $purchase = $request->filled('compra') ? $request->integer('compra') : null;

        return $this->sendResponse(PurchaseTransactionResource::collection(
            $this->repository->paginate($request->integer('per_page', 30), $purchase, $request->string('search')->toString()),
        ), 'ok', 200, true);
    }

    public function options(): JsonResponse
    {
        return $this->sendResponse($this->repository->options(), 'ok');
    }

    public function store(PurchaseTransactionRequest $request): JsonResponse
    {
        return $this->persist($request);
    }

    public function show(int $id): JsonResponse
    {
        $transaction = $this->repository->find($id);

        return $transaction === null
            ? $this->sendError('Transacción no encontrada.', null, 404)
            : $this->sendResponse(PurchaseTransactionResource::make($transaction), 'ok');
    }

    public function update(PurchaseTransactionRequest $request): JsonResponse
    {
        return $this->persist($request, $request->integer('id'));
    }

    public function destroy(int $id): JsonResponse
    {
        return $this->repository->delete($id)
            ? $this->sendResponse(true, 'Transacción eliminada correctamente.')
            : $this->sendError('Transacción no encontrada.', null, 404);
    }

    public function document(int $id): JsonResponse
    {
        $stored = $this->repository->document($id);
        $stored = app(InvoiceStorage::class)->base64($stored);

        if ($stored === null) {
            return $this->sendError('Documento no encontrado.', null, 404);
        }

        $document = $this->base64Utility->validate($stored) ?? $this->base64Utility->sanitize($stored);

        return $document === null
            ? $this->sendError('El documento almacenado no es válido.', null, 422)
            : $this->sendResponse(['image' => $document], 'ok');
    }

    private function persist(PurchaseTransactionRequest $request, ?int $id = null): JsonResponse
    {
        try {
            $dto = PurchaseTransactionData::fromValidated($request->validated());
            $data = $dto->toArray();

            if ($dto->img !== null) {
                $data['img'] = $this->base64Utility->sanitize($dto->img);

                if ($data['img'] === null) {
                    return $this->sendError('El documento escaneado no es válido.', null, 422);
                }
            }

            $transaction = $id === null
                ? $this->repository->create($data)
                : $this->repository->update($id, $data);

            if ($transaction === null) {
                return $this->sendError('Transacción no encontrada.', null, 404);
            }

            return $this->sendResponse(
                PurchaseTransactionResource::make($transaction),
                $id === null ? 'Transacción registrada correctamente.' : 'Transacción actualizada correctamente.',
                $id === null ? 201 : 200,
            );
        } catch (DomainException $exception) {
            return $this->sendError($exception->getMessage(), null, 422);
        } catch (Throwable $throwable) {
            $this->logError('PurchaseTransactionController persist', $throwable);

            return $this->sendError('No se pudo guardar la transacción.', null, 500);
        }
    }
}
