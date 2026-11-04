<?php

namespace App\Http\Controllers\API\Headquarters;

use App\Http\Controllers\Controller;
use App\Services\Headquarters\InventoryControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryControlController extends Controller
{
    public function handle(Request $request, InventoryControlService $service): JsonResponse
    {
        $data = $request->validate(['device_id' => ['required', 'integer', 'min:1'],
            'client_uuid' => ['required', 'uuid'], 'payload' => ['required', 'array']]);

        return $this->sendResponse($service->execute($data['device_id'], $data['client_uuid'], $data['payload']));
    }
}
