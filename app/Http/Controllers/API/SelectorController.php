<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SelectorLookupRequest;
use App\Interfaces\Config\DeviceInfoService;
use App\Services\Selectors\SelectorCatalog;
use Illuminate\Http\JsonResponse;

final class SelectorController extends Controller
{
    public function __invoke(SelectorLookupRequest $request, string $source, SelectorCatalog $catalog): JsonResponse
    {
        $definition = $catalog->definition($source);
        if (! $catalog->allows($request, $definition)) {
            return $this->sendError('No tienes permiso para consultar este selector.', null, 403);
        }
        if (! app()->environment(['local', 'testing']) && ! $request->isSecure()
            && collect($definition['permissions'])->contains(fn ($codes) => $codes[0] === 'pos')) {
            return $this->sendError('Esta consulta POS requiere HTTPS.', null, 400);
        }
        $data = $request->validated();
        $search = trim($data['search'] ?? '');
        if (($definition['allow_search'] ?? true) === false && $search !== '') {
            return $this->sendError('Usa la búsqueda de clientes con su permiso correspondiente.', null, 403);
        }
        if (mb_strlen($search) < ($definition['min_search'] ?? 0)) {
            return $this->sendError('Escribe al menos dos caracteres para buscar clientes.', null, 422);
        }
        if (in_array($definition['scope'] ?? null, ['transfer-stocks', 'conversion-children'], true)) {
            $data['device_stock'] = app(DeviceInfoService::class)->stockId($request->attributes->get('authenticated_device'));
            if (! $data['device_stock']) {
                return $this->sendError('El dispositivo no tiene un stock válido asignado.', null, 422);
            }
        }

        return $this->sendResponse($catalog->page($definition, $data), 'ok', 200, true);
    }
}
