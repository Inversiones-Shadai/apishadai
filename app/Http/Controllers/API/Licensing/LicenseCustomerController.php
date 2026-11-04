<?php

namespace App\Http\Controllers\API\Licensing;

use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LicenseCustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:160'], 'page' => ['nullable', 'integer', 'min:1']]);
        $counts = DB::table('desktop_licenses')->selectRaw('customer_id, count(*) as license_count')->groupBy('customer_id');
        $customers = DB::table('desktop_license_customers as customers')
            ->leftJoinSub($counts, 'counts', 'customers.id', '=', 'counts.customer_id')
            ->select(['customers.id', 'customers.name'])->selectRaw('coalesce(counts.license_count, 0) as license_count')
            ->when($data['search'] ?? '', fn ($query, $search) => $query->where('customers.name_key', 'like', '%'.mb_strtolower(Str::squish($search)).'%'))
            ->orderBy('customers.name_key')->orderBy('customers.id')->paginate(10);

        return $this->sendResponse($customers, withPagination: true)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, string $customer): JsonResponse
    {
        return $this->save($request, $customer);
    }

    private function save(Request $request, ?string $id = null): JsonResponse
    {
        $id = $id === null ? null : Str::lower($id);
        $data = $request->validate(['name' => ['required', 'string', 'max:160']]);
        $name = Str::squish($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'El nombre del cliente es obligatorio.']);
        }
        $key = mb_strtolower($name);
        try {
            $result = DB::transaction(function () use ($id, $name, $key): array {
                if ($id !== null) {
                    abort_unless(DB::table('desktop_license_customers')->where('id', $id)->lockForUpdate()->first(), 404);
                    DB::table('desktop_license_customers')->where('id', $id)->update(['name' => $name, 'name_key' => $key, 'updated_at' => now('UTC')]);
                } else {
                    $id = (string) Str::uuid();
                    DB::table('desktop_license_customers')->insert(['id' => $id, 'name' => $name, 'name_key' => $key, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                }

                return ['id' => $id, 'name' => $name];
            }, 3);
        } catch (QueryException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw ValidationException::withMessages(['name' => 'Ya existe un cliente de licencias con ese nombre.']);
            }
            throw $error;
        }

        return $this->sendResponse($result, 'Cliente de licencias guardado.')->header('Cache-Control', 'no-store');
    }
}
