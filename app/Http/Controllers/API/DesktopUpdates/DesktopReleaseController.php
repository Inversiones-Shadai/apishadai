<?php

namespace App\Http\Controllers\API\DesktopUpdates;

use App\Http\Controllers\Controller;
use App\Services\DesktopUpdates\DesktopReleaseCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DesktopReleaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['product' => ['nullable', Rule::in(array_keys(config('desktop_updates.products')))],
            'status' => ['nullable', Rule::in(['draft', 'pilot', 'stable', 'withdrawn'])],
            'page' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100']]);
        $query = DB::table('desktop_releases as releases')->leftJoin('desktop_release_tracks as tracks', function ($join): void {
            $join->on('tracks.product', '=', 'releases.product')->on('tracks.architecture', '=', 'releases.architecture');
        })->select(['releases.*', 'tracks.pilot_release_id', 'tracks.stable_release_id'])
            ->when($data['product'] ?? '', fn ($q, $product) => $q->where('releases.product', $product))
            ->when($data['status'] ?? '', fn ($q, $status) => $q->where('releases.status', $status))
            ->when($data['search'] ?? '', fn ($q, $search) => $q->where('releases.version', 'like', '%'.$search.'%'))
            ->orderBy('releases.product')->orderBy('releases.architecture')->orderByDesc('releases.version_sort')->paginate(20);
        $counts = DB::table('desktop_release_pilot_devices')->whereIn('release_id', $query->pluck('id'))
            ->selectRaw('release_id, count(*) as total')->groupBy('release_id')->pluck('total', 'release_id');
        $query->getCollection()->transform(function ($row) use ($counts): object {
            $row->pilot_count = (int) ($counts[$row->id] ?? 0);
            $row->current_pilot = $row->pilot_release_id === $row->id;
            $row->current_stable = $row->stable_release_id === $row->id;
            unset($row->pilot_release_id, $row->stable_release_id);

            return $row;
        });

        return $this->sendResponse($query, withPagination: true)->header('Cache-Control', 'no-store');
    }

    public function artifacts(Request $request): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'],
            'product' => ['nullable', Rule::in(array_keys(config('desktop_updates.products')))],
            'search' => ['nullable', 'string', 'max:100']]);
        $query = DB::table('desktop_release_artifacts as artifacts')->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('desktop_releases')->whereColumn('desktop_releases.artifact_id', 'artifacts.id');
        })->select(['artifacts.id', 'artifacts.product', 'artifacts.version', 'artifacts.architecture', 'artifacts.size_bytes', 'artifacts.sha256', 'artifacts.verified_at'])
            ->when($data['product'] ?? '', fn ($q, $product) => $q->where('artifacts.product', $product))
            ->when($data['search'] ?? '', fn ($q, $search) => $q->where('artifacts.version', 'like', '%'.$search.'%'))
            ->orderByDesc('artifacts.created_at')->orderBy('artifacts.id')->paginate(10);

        return $this->sendResponse($query, withPagination: true)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, DesktopReleaseCatalog $catalog): JsonResponse
    {
        $data = $request->validate($this->commonRules() + $this->draftRules() + ['artifact_id' => ['required', 'uuid']]);
        $data = $this->normalizeDraft($data);

        return $this->sendResponse($catalog->create($data, (int) $request->user()->getAuthIdentifier()), 'Borrador creado.')->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $id, DesktopReleaseCatalog $catalog): JsonResponse
    {
        $data = $request->validate($this->commonRules() + $this->draftRules() + [
            'expected_revision' => ['required', 'integer', 'min:1'], 'artifact_id' => ['prohibited']]);

        return $this->sendResponse($catalog->change($id, 'edit', $this->normalizeDraft($data), (int) $request->user()->getAuthIdentifier()), 'Borrador actualizado.')->header('Cache-Control', 'no-store');
    }

    public function pilotDevices(Request $request, string $id, DesktopReleaseCatalog $catalog): JsonResponse
    {
        $release = $catalog->record($id);
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100']]);
        $devices = $catalog->eligibleDevices($release->product)->select(['devices.id', 'devices.device_name', 'licenses.label as license_label'])
            ->when($data['search'] ?? '', fn ($q, $search) => $q->where(fn ($q) => $q->where('devices.device_name', 'like', '%'.$search.'%')->orWhere('licenses.label', 'like', '%'.$search.'%')))
            ->orderBy('devices.device_name')->orderBy('devices.id')->paginate(10);

        return $this->sendResponse(['revision' => (int) $release->revision, 'devices' => $devices,
            'selected' => DB::table('desktop_release_pilot_devices')->where('release_id', $id)->orderBy('activation_id')->pluck('activation_id')->all()])->header('Cache-Control', 'no-store');
    }

    public function setPilots(Request $request, string $id, DesktopReleaseCatalog $catalog): JsonResponse
    {
        $data = $request->validate($this->commonRules() + ['expected_revision' => ['required', 'integer', 'min:1'],
            'activation_ids' => ['present', 'array', 'max:500'], 'activation_ids.*' => ['required', 'uuid', 'distinct']]);
        $data['activation_ids'] = array_map('strtolower', $data['activation_ids']);
        sort($data['activation_ids']);
        $data['expected_revision'] = (int) $data['expected_revision'];

        return $this->sendResponse($catalog->change($id, 'pilots', $data, (int) $request->user()->getAuthIdentifier()), 'Equipos piloto guardados.')->header('Cache-Control', 'no-store');
    }

    public function promotePilot(Request $request, string $id, DesktopReleaseCatalog $catalog): JsonResponse
    {
        return $this->transition($request, $id, 'pilot', $catalog);
    }

    public function promoteStable(Request $request, string $id, DesktopReleaseCatalog $catalog): JsonResponse
    {
        return $this->transition($request, $id, 'stable', $catalog);
    }

    public function withdraw(Request $request, string $id, DesktopReleaseCatalog $catalog): JsonResponse
    {
        return $this->transition($request, $id, 'withdraw', $catalog);
    }

    public function events(Request $request, string $id, DesktopReleaseCatalog $catalog): JsonResponse
    {
        $catalog->record($id);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return $this->sendResponse(DB::table('desktop_release_events')->where('release_id', $id)->orderByDesc('id')->paginate(50), withPagination: true)->header('Cache-Control', 'no-store');
    }

    private function transition(Request $request, string $id, string $action, DesktopReleaseCatalog $catalog): JsonResponse
    {
        $data = $request->validate($this->commonRules() + ['expected_revision' => ['required', 'integer', 'min:1']]
            + ($action === 'withdraw' ? ['reason' => ['required', 'string', 'min:3', 'max:500']] : []));
        $data['expected_revision'] = (int) $data['expected_revision'];

        return $this->sendResponse($catalog->change($id, $action, $data, (int) $request->user()->getAuthIdentifier()), 'Estado de la versión actualizado.')->header('Cache-Control', 'no-store');
    }

    private function commonRules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'product' => ['prohibited'], 'version' => ['prohibited'],
            'architecture' => ['prohibited'], 'object_id' => ['prohibited'], 'object_key' => ['prohibited'], 'status' => ['prohibited']];
    }

    private function draftRules(): array
    {
        return ['notes' => ['required', 'string', 'min:3', 'max:10000'], 'min_api_contract' => ['required', 'integer', 'min:1', 'max:65535'],
            'min_schema_version' => ['required', 'integer', 'min:0', 'max:65535'],
            'max_schema_version' => ['nullable', 'integer', 'gte:min_schema_version', 'max:65535'], 'required' => ['required', 'boolean']];
    }

    private function normalizeDraft(array $data): array
    {
        $data['min_api_contract'] = (int) $data['min_api_contract'];
        $data['min_schema_version'] = (int) $data['min_schema_version'];
        $data['max_schema_version'] = isset($data['max_schema_version']) ? (int) $data['max_schema_version'] : null;
        $data['required'] = (bool) $data['required'];
        if (isset($data['expected_revision'])) {
            $data['expected_revision'] = (int) $data['expected_revision'];
        }

        return $data;
    }
}
