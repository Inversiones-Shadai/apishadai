<?php

namespace App\Http\Controllers\API\Headquarters;

use App\Http\Controllers\Controller;
use App\Services\Headquarters\InventoryMatrixService;
use App\Services\Headquarters\PosSaleInformation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryMatrixController extends Controller
{
    public function branches(Request $request, PosSaleInformation $service): JsonResponse
    {
        $data = $request->validate(['device_id' => ['required', 'integer', 'min:1'],
            'client_uuid' => ['required', 'uuid'], 'payload' => ['required', 'array']]);

        return $this->sendResponse($service->exchange($data['device_id'], $data['client_uuid'], $data['payload']));
    }

    public function handle(Request $request, InventoryMatrixService $service): JsonResponse
    {
        $data = $request->validate(['device_id' => ['required', 'integer', 'min:1'],
            'client_uuid' => ['required', 'uuid'], 'payload' => ['required', 'array']]);

        return $this->sendResponse($service->execute($data['device_id'], $data['client_uuid'], $data['payload']));
    }
}
