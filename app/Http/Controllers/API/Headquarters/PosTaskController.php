<?php

namespace App\Http\Controllers\API\Headquarters;

use App\Http\Controllers\Controller;
use App\Services\Headquarters\PosTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosTaskController extends Controller
{
    public function handle(Request $request, PosTaskService $service, string $action): JsonResponse
    {
        $data = $request->validate(['device_id' => ['required', 'integer', 'min:1'], 'client_uuid' => ['required', 'uuid'], 'payload' => ['present', 'array']]);

        return $this->sendResponse($service->execute($data['device_id'], $data['client_uuid'], $action, $data['payload']), 'ok');
    }
}
