<?php

namespace App\Http\Controllers;

use App\Http\Requests\AppConfigRequest;
use App\Http\Requests\VersionRequest;
use App\Interfaces\Config\AppConfigService;
use App\Models\AppConfig;
use Illuminate\Http\JsonResponse;

class AppConfigController extends Controller
{
    public function __construct(private AppConfigService $appConfigService) {}

    public function checkVersion(VersionRequest $request)
    {
        $exists = $this->appConfigService->existVersion($request->version);

        return $this->sendResponse($exists, $exists ? 'Version exists' : 'Version does not exist');
    }

    public function index(): JsonResponse
    {
        return $this->sendResponse(AppConfig::query()->orderBy('id')->paginate(15), withPagination: true);
    }

    public function show(int $id): JsonResponse
    {
        $config = AppConfig::query()->find($id);

        return $config
            ? $this->sendResponse($config)
            : $this->sendError('Configuración no encontrada.', null, 404);
    }

    public function store(AppConfigRequest $request): JsonResponse
    {
        return $this->sendResponse(AppConfig::query()->create($request->validated()), 'Configuración creada correctamente.', 201);
    }

    public function update(AppConfigRequest $request, int $id): JsonResponse
    {
        $config = AppConfig::query()->find($id);
        if (! $config) {
            return $this->sendError('Configuración no encontrada.', null, 404);
        }

        $config->update($request->validated());

        return $this->sendResponse($config->fresh(), 'Configuración actualizada correctamente.');
    }
}
