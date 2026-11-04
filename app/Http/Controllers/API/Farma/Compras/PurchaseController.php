<?php

namespace App\Http\Controllers\API\Farma\Compras;

use App\DTO\Farma\PurchaseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Farma\Compras\PurchaseDraftDetailRequest;
use App\Http\Requests\Farma\Compras\PurchaseDraftRequest;
use App\Http\Requests\Farma\Compras\PurchaseRequest;
use App\Http\Resources\Farma\PurchaseResource;
use App\Interfaces\Farma\PurchaseKardexService;
use App\Interfaces\Farma\PurchaseService;
use App\Services\Images\ImageStorage;
use App\Services\Images\InvoiceStorage;
use App\Utils\Services\Base64UtilityService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class PurchaseController extends Controller
{
    public function __construct(
        private readonly PurchaseService $repository,
        private readonly PurchaseKardexService $kardexRepository,
        private readonly Base64UtilityService $base64Utility,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim(strip_tags((string) $request->query('search', '')));

        return $this->sendResponse(PurchaseResource::collection(
            $this->repository->paginate(
                $request->integer('per_page', 30),
                mb_substr($search, 0, 100),
            ),
        ), 'ok', 200, true);
    }

    public function options(Request $request): JsonResponse
    {
        return $this->sendResponse($this->repository->options($request->boolean('compact')), 'ok');
    }

    public function latestCosts(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'productos' => ['required', 'array', 'min:1', 'max:200'],
            'productos.*' => ['required', 'integer', 'min:1', 'distinct'],
            'excluir_compra' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->sendError($validator->errors()->first(), ['errors' => $validator->errors()->toArray()], 422);
        }
        $data = $validator->validated();

        return $this->sendResponse($this->repository->latestCosts(
            array_map('intval', $data['productos']),
            isset($data['excluir_compra']) ? (int) $data['excluir_compra'] : null,
        ), 'ok');
    }

    public function providers(Request $request): JsonResponse
    {
        $search = mb_substr(trim(strip_tags((string) $request->query('search', ''))), 0, 100);
        $page = $this->repository->providers($request->integer('per_page', 10), $search);
        $page->getCollection()->transform(static fn ($provider): array => [
            'id' => (int) $provider->id,
            'nombre' => (string) $provider->nombre,
            'tiene_imagen' => (bool) $provider->tiene_imagen,
            'imagen_url' => app(ImageStorage::class)->url($provider->imagen_uuid ?? $provider->imagen),
        ]);

        return $this->sendResponse($page, 'ok', 200, true);
    }

    public function providerImage(int $id): JsonResponse
    {
        $stored = $this->repository->providerImage($id);
        $stored = app(ImageStorage::class)->base64($stored);

        if ($stored === null) {
            return $this->sendError('Imagen del proveedor no encontrada.', null, 404);
        }

        $image = $this->base64Utility->validate($stored) ?? $this->base64Utility->sanitize($stored);

        return $image === null
            ? $this->sendError('La imagen almacenada del proveedor no es válida.', null, 422)
            : $this->sendResponse(['image' => $image], 'ok');
    }

    public function store(PurchaseRequest $request): JsonResponse
    {
        return $this->persist($request);
    }

    public function storeDraft(PurchaseDraftRequest $request): JsonResponse
    {
        try {
            $header = $this->draftHeader($request);
            $header['usuario'] = (int) $request->user()->getAuthIdentifier();
            $purchase = $this->repository->createDraft($header);

            return $this->sendResponse(PurchaseResource::make($purchase), 'Borrador creado y sincronización activada.', 201);
        } catch (Throwable $throwable) {
            $this->logError('PurchaseController storeDraft', $throwable);

            return $this->sendError($throwable instanceof QueryException ? 'La factura ya está registrada para este proveedor.' : 'No se pudo crear el borrador.', null, $throwable instanceof QueryException ? 409 : 500);
        }
    }

    public function syncDraft(PurchaseDraftRequest $request, int $id): JsonResponse
    {
        try {
            $purchase = $this->repository->syncHeader($id, $this->draftHeader($request));

            return $purchase === null
                ? $this->sendError('Compra no encontrada.', null, 404)
                : $this->sendResponse(PurchaseResource::make($purchase), 'Encabezado sincronizado.');
        } catch (DomainException $exception) {
            return $this->sendError($exception->getMessage(), null, 422);
        }
    }

    public function syncDetail(PurchaseDraftDetailRequest $request, int $purchase): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['lote'] = (string) ($data['lote'] ?? '');
            $purchaseRecord = $this->repository->syncDetail($purchase, $data);

            return $purchaseRecord === null
                ? $this->sendError('Compra no encontrada.', null, 404)
                : $this->sendResponse(PurchaseResource::make($purchaseRecord), 'Producto sincronizado.');
        } catch (DomainException $exception) {
            return $this->sendError($exception->getMessage(), null, 422);
        }
    }

    public function deleteDetail(int $purchase, int $detail): JsonResponse
    {
        try {
            $purchaseRecord = $this->repository->deleteDetail($purchase, $detail);

            return $purchaseRecord === null
                ? $this->sendError('Compra no encontrada.', null, 404)
                : $this->sendResponse(PurchaseResource::make($purchaseRecord), 'Producto eliminado de la compra.');
        } catch (DomainException $exception) {
            return $this->sendError($exception->getMessage(), null, 422);
        }
    }

    public function finalize(int $id): JsonResponse
    {
        try {
            $purchase = $this->repository->finalize($id);

            return $purchase === null
                ? $this->sendError('Compra no encontrada.', null, 404)
                : $this->sendResponse(PurchaseResource::make($purchase), 'Compra finalizada y pendiente de pago.');
        } catch (DomainException $exception) {
            return $this->sendError($exception->getMessage(), null, 422);
        }
    }

    public function show(int $id): JsonResponse
    {
        $purchase = $this->repository->find($id);

        return $purchase === null
            ? $this->sendError('Compra no encontrada.', null, 404)
            : $this->sendResponse(PurchaseResource::make($purchase), 'ok');
    }

    public function report(int $id): JsonResponse
    {
        $purchase = $this->repository->find($id);

        return $purchase === null
            ? $this->sendError('Compra no encontrada.', null, 404)
            : $this->sendResponse(PurchaseResource::make($purchase), 'Reporte de compra generado.');
    }

    public function sendToKardex(Request $request, int $id): JsonResponse
    {
        try {
            $purchase = $this->kardexRepository->send(
                $id,
                (int) $request->user()->getAuthIdentifier(),
            );

            return $purchase === null
                ? $this->sendError('Compra no encontrada.', null, 404)
                : $this->sendResponse(PurchaseResource::make($purchase), 'Compra enviada al Kardex correctamente.');
        } catch (DomainException $exception) {
            return $this->sendError($exception->getMessage(), null, 422);
        } catch (Throwable $throwable) {
            $this->logError('PurchaseController sendToKardex', $throwable);

            return $this->sendError('No se pudo enviar la compra al Kardex.', null, 500);
        }
    }

    public function update(PurchaseRequest $request): JsonResponse
    {
        return $this->persist($request, $request->integer('id'));
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            return $this->repository->delete($id)
                ? $this->sendResponse(true, 'Compra eliminada correctamente.')
                : $this->sendError('La compra no existe, tiene transacciones relacionadas o ya fue enviada al Kardex.', null, 409);
        } catch (Throwable $throwable) {
            $this->logError('PurchaseController destroy', $throwable);

            return $this->sendError('No se pudo eliminar la compra.', null, 409);
        }
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

    private function persist(PurchaseRequest $request, ?int $id = null): JsonResponse
    {
        try {
            $data = PurchaseData::fromValidated($request->validated());
            $header = $data->header((int) $request->user()->getAuthIdentifier());

            if ($id !== null) {
                unset($header['usuario']);
            }

            if ($data->img !== null) {
                $header['img'] = $this->base64Utility->sanitize($data->img);

                if ($header['img'] === null) {
                    return $this->sendError('El documento escaneado no es válido.', null, 422);
                }
            }

            $purchase = $id === null
                ? $this->repository->create($header, $data->detailRows())
                : $this->repository->update($id, $header, $data->detailRows());

            if ($purchase === null) {
                return $this->sendError('Compra no encontrada.', null, 404);
            }

            return $this->sendResponse(
                PurchaseResource::make($purchase),
                $id === null ? 'Compra creada correctamente.' : 'Compra actualizada correctamente.',
                $id === null ? 201 : 200,
            );
        } catch (Throwable $throwable) {
            $this->logError('PurchaseController persist', $throwable);
            $message = $throwable instanceof QueryException
                ? 'La factura está duplicada o contiene relaciones inválidas.'
                : 'No se pudo guardar la compra.';

            return $this->sendError($message, null, $throwable instanceof QueryException ? 409 : 500);
        }
    }

    /** @return array<string, mixed> */
    private function draftHeader(PurchaseDraftRequest $request): array
    {
        $validated = $request->validated();
        $header = [
            'tipo' => (int) $validated['tipo'],
            'proveedor' => (int) $validated['proveedor'],
            'plazo' => (int) $validated['plazo'],
            'nro' => (string) $validated['nro'],
            'nota' => $validated['nota'] ?? null,
        ];

        if (isset($validated['img'])) {
            $header['img'] = $this->base64Utility->sanitize((string) $validated['img']);

            if ($header['img'] === null) {
                throw new DomainException('El documento escaneado no es válido.');
            }
        }

        return $header;
    }
}
