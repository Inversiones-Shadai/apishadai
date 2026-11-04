<?php

namespace App\Http\Controllers\API\Licensing;

use App\Http\Controllers\Controller;
use App\Services\Licensing\LicenseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LicenseAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:160'], 'page' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'uuid', 'exists:desktop_license_customers,id']]);
        $licenses = DB::table('desktop_licenses as licenses')->join('desktop_license_customers as customers', 'licenses.customer_id', '=', 'customers.id')
            ->select(['licenses.id', 'licenses.label', 'licenses.product', 'licenses.key_suffix', 'licenses.seats', 'licenses.enabled', 'licenses.expires_at',
                'licenses.term_months', 'licenses.term_started_at', 'licenses.created_at', 'licenses.customer_id', 'customers.name as customer_name'])
            ->selectRaw('NOT EXISTS (SELECT 1 FROM desktop_license_devices WHERE license_id = licenses.id AND revoked_at IS NULL) as can_delete')
            ->when($data['customer_id'] ?? null, fn ($q, $customer) => $q->where('licenses.customer_id', $customer))
            ->when($data['search'] ?? '', fn ($q, $search) => $q->where(fn ($searchQuery) => $searchQuery->where('licenses.label', 'like', '%'.$search.'%')
                ->orWhere('customers.name_key', 'like', '%'.mb_strtolower($search).'%')))
            ->orderBy('customers.name_key')->orderBy('customers.id')->orderByDesc('licenses.created_at')->orderBy('licenses.id')->paginate(20);
        $counts = DB::table('desktop_license_devices')->whereIn('license_id', $licenses->pluck('id'))->where(fn ($q) => $q->whereNull('revoked_at')->orWhere('reserved_until', '>', now('UTC')))
            ->selectRaw('license_id, count(*) as total')->groupBy('license_id')->pluck('total', 'license_id');
        $licenses->getCollection()->transform(function ($license) use ($counts): object {
            $license->used_seats = (int) ($counts[$license->id] ?? 0);
            $license->can_delete = (bool) $license->can_delete;

            return $license;
        });

        return $this->sendResponse($licenses, withPagination: true)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, LicenseManager $manager): JsonResponse
    {
        $data = $request->validate($this->rules() + ['product' => ['required', Rule::in(config('licensing.products'))],
            'term_months' => ['required', 'integer', Rule::in(config('licensing.term_months'))], 'renewal_months' => ['prohibited']]);

        return $this->sendResponse($manager->issue($data, $request->user()->getAuthIdentifier()), 'Licencia generada. Guarda la clave; solo se muestra una vez.')->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $id, LicenseManager $manager): JsonResponse
    {
        $manager->update($id, $request->validate($this->rules() + ['enabled' => ['required', 'boolean'], 'product' => ['prohibited'], 'term_months' => ['prohibited'],
            'renewal_months' => ['sometimes', 'required', 'integer', Rule::in(config('licensing.term_months'))],
            'expected_expires_at' => ['required_with:renewal_months', 'date']]), $request->user()->getAuthIdentifier());

        return $this->sendResponse(null, 'Licencia actualizada.');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $request->validate(['devices_page' => ['nullable', 'integer', 'min:1'], 'events_page' => ['nullable', 'integer', 'min:1']]);
        abort_unless(DB::table('desktop_licenses')->where('id', $id)->exists(), 404);

        return $this->sendResponse([
            'devices' => DB::table('desktop_license_devices')->where('license_id', $id)->select(['id', 'device_name', 'device_hash', 'created_at', 'last_validated_at', 'reserved_until', 'revoked_at'])->orderByDesc('created_at')->orderBy('id')->paginate(20, ['*'], 'devices_page'),
            'events' => DB::table('desktop_license_events')->where('license_id', $id)->orderByDesc('id')->paginate(50, ['*'], 'events_page'),
        ])->header('Cache-Control', 'no-store');
    }

    public function unlink(Request $request, string $id, string $activation, LicenseManager $manager): JsonResponse
    {
        $manager->unlink($id, $activation, $request->user()->getAuthIdentifier());

        return $this->sendResponse(null, 'Equipo desvinculado. El cupo queda reservado hasta vencer su autorización sin conexión (máximo 72 horas).');
    }

    public function destroy(Request $request, string $id, LicenseManager $manager): JsonResponse
    {
        $manager->delete($id, $request->user()->getAuthIdentifier());

        return $this->sendResponse(null, 'Licencia eliminada.');
    }

    private function rules(): array
    {
        return [
            'customer_id' => ['required', 'uuid', 'exists:desktop_license_customers,id'],
            'label' => ['required', 'string', 'max:160'], 'seats' => ['required', 'integer', 'min:1', 'max:10000'],
            'expires_at' => ['prohibited'],
        ];
    }
}
