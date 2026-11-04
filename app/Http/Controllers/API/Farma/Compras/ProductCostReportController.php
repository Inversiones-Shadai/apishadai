<?php

namespace App\Http\Controllers\API\Farma\Compras;

use App\Http\Controllers\Controller;
use App\Services\Farma\ProductCostReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProductCostReportController extends Controller
{
    public function __invoke(Request $request, ProductCostReport $report): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'producto_id' => ['required', 'integer', 'min:1', 'exists:productos,id'],
            'proveedor_id' => ['nullable', 'integer', 'min:1', 'exists:proveedores,id'],
            'fecha_desde' => ['nullable', 'date_format:Y-m-d'],
            'fecha_hasta' => array_merge(['nullable', 'date_format:Y-m-d'], $request->filled('fecha_desde') ? ['after_or_equal:fecha_desde'] : []),
        ], ['producto_id.required' => 'Selecciona un producto.', 'producto_id.exists' => 'El producto no está disponible.',
            'fecha_hasta.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.']);
        if ($validator->fails()) {
            return $this->sendError($validator->errors()->first(), ['errors' => $validator->errors()->toArray()], 422);
        }

        try {
            return $this->sendResponse($report->report($validator->validated()));
        } catch (ValidationException $error) {
            return $this->sendError(collect($error->errors())->flatten()->first(), ['errors' => $error->errors()], 422);
        } catch (NotFoundHttpException) {
            return $this->sendError('El producto ya no está disponible.', null, 404);
        }
    }
}
