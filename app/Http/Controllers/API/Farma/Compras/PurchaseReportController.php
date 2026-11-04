<?php

namespace App\Http\Controllers\API\Farma\Compras;

use App\Http\Controllers\Controller;
use App\Http\Resources\Farma\PurchaseReportExportResource;
use App\Http\Resources\Farma\PurchaseResource;
use App\Http\Resources\Farma\PurchaseTransactionResource;
use App\Interfaces\Farma\PurchaseService;
use App\Interfaces\Farma\PurchaseTransactionService;
use App\Models\Farma\Compra;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PurchaseReportController extends Controller
{
    public function __construct(private readonly PurchaseService $repository) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
            'proveedor' => ['nullable', 'integer', 'min:1', 'exists:proveedores,id'],
            'tipo' => ['nullable', 'integer', 'in:1,2'],
        ]);
        if ($validator->fails()) {
            return $this->sendError($validator->errors()->first(), ['errors' => $validator->errors()->toArray()], 422);
        }
        $data = $validator->validated();
        $report = $this->repository->report(
            (int) ($data['per_page'] ?? 30),
            trim(strip_tags($data['search'] ?? '')),
            isset($data['proveedor']) ? (int) $data['proveedor'] : null,
            isset($data['tipo']) ? (int) $data['tipo'] : null,
        );
        $page = $report['purchases'];

        return $this->sendResponse([
            'items' => PurchaseResource::collection($page->getCollection())->resolve($request),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'totals' => $report['totals'],
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'search' => ['nullable', 'string', 'max:100'],
            'proveedor' => ['nullable', 'integer', 'min:1', 'exists:proveedores,id'],
            'tipo' => ['nullable', 'integer', 'in:1,2'],
            'antes_de' => ['nullable', 'integer', 'min:1'],
        ]);
        if ($validator->fails()) {
            return $this->sendError($validator->errors()->first(), ['errors' => $validator->errors()->toArray()], 422);
        }
        $data = $validator->validated();
        $rows = $this->repository->exportPage(trim(strip_tags($data['search'] ?? '')),
            isset($data['proveedor']) ? (int) $data['proveedor'] : null,
            isset($data['tipo']) ? (int) $data['tipo'] : null,
            isset($data['antes_de']) ? (int) $data['antes_de'] : null);

        return $this->sendResponse([
            'items' => PurchaseReportExportResource::collection($rows)->resolve($request),
            'next_cursor' => $rows->count() === 100 ? (int) $rows->last()->id : null,
        ]);
    }

    public function payments(Request $request, int $id, PurchaseTransactionService $transactions): JsonResponse
    {
        $validator = Validator::make($request->query(), ['page' => ['nullable', 'integer', 'min:1']]);
        if ($validator->fails()) {
            return $this->sendError($validator->errors()->first(), ['errors' => $validator->errors()->toArray()], 422);
        }
        $purchase = Compra::query()->select(['id', 'nro', 'total'])
            ->withSum('transacciones as total_aplicado', 'valor')->find($id);
        if ($purchase === null) {
            return $this->sendError('Compra no encontrada.', null, 404);
        }
        $page = $transactions->paginate(15, $id);
        $applied = (float) ($purchase->total_aplicado ?? 0);

        return $this->sendResponse([
            'items' => PurchaseTransactionResource::collection($page->getCollection())->resolve($request),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'purchase' => ['id' => (int) $purchase->id, 'nro' => (string) $purchase->nro,
                'total_aplicado' => $applied, 'saldo' => round(max(0, (float) $purchase->total - $applied), 2)],
        ]);
    }

    public function providers(Request $request): JsonResponse
    {
        $search = mb_substr(trim(strip_tags((string) $request->query('search', ''))), 0, 100);
        $page = $this->repository->providers(10, $search);
        $page->getCollection()->transform(static fn ($provider): array => ['id' => (int) $provider->id, 'nombre' => (string) $provider->nombre]);

        return $this->sendResponse($page, 'ok', 200, true);
    }
}
