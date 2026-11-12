<?php

namespace App\Http\Controllers\API\Farma;

use App\Http\Controllers\Controller;
use App\Services\Headquarters\PosSaleInformation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use stdClass;

class SaleAlertController extends Controller
{
    public function index(): JsonResponse
    {
        $alerts = DB::table('pos_sale_alerts as a')
            ->leftJoin('productos as p', 'p.id', '=', 'a.producto_id')
            ->leftJoin('prod_categorias as c', 'c.id', '=', 'a.categoria_id')
            ->leftJoin('stocks as s', 's.id', '=', 'a.stock_id')
            ->where('a.retirada', false)->orderByDesc('a.id')
            ->paginate(25, ['a.*', 'p.codigo as producto_codigo', 'p.descripcion as producto', 'c.nombre as categoria', 's.descripcion as stock']);
        $alerts->through(fn (stdClass $alert): array => [
            'id' => (string) $alert->id, 'revision' => (string) $alert->revision,
            'producto_id' => $alert->producto_id === null ? null : (string) $alert->producto_id,
            'categoria_id' => $alert->categoria_id === null ? null : (string) $alert->categoria_id,
            'stock_id' => $alert->stock_id === null ? null : (string) $alert->stock_id,
            'producto' => $alert->producto, 'producto_codigo' => $alert->producto_codigo,
            'categoria' => $alert->categoria, 'stock' => $alert->stock,
            'titulo' => $alert->titulo, 'activo' => (bool) $alert->activo,
            'requisitos' => json_decode($alert->requisitos, true, flags: JSON_THROW_ON_ERROR),
        ]);

        return $this->sendResponse($alerts, 'Alertas de venta', 200, true);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->data($request, false);
        $id = DB::transaction(fn () => DB::table('pos_sale_alerts')->insertGetId($this->values($data) + ['created_at' => now()]), 3);

        return $this->sendResponse(['id' => (string) $id], 'Alerta creada.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $this->data($request, true);
        $this->mutate($id, (int) $data['revision'], fn (): array => $this->values($data));

        return $this->sendResponse(['id' => (string) $id], 'Alerta actualizada.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $this->mutate($id, (int) $data['revision'], fn (): array => [
            'retirada' => true, 'activo' => false, 'revision' => PosSaleInformation::nextRevision(), 'updated_at' => now(),
        ]);

        return $this->sendResponse(['id' => (string) $id], 'Alerta retirada.');
    }

    private function mutate(int $id, int $expected, callable $values): void
    {
        DB::transaction(function () use ($id, $expected, $values): void {
            DB::table('pos_sale_information_stream')->where('id', 1)->lockForUpdate()->first();
            $alert = DB::table('pos_sale_alerts')->where('retirada', false)->lockForUpdate()->find($id);
            abort_unless($alert, 404);
            abort_unless((int) $alert->revision === $expected, 409, 'La alerta cambió. Actualiza la lista e inténtalo de nuevo.');
            DB::table('pos_sale_alerts')->where('id', $id)->update($values());
        }, 3);
    }

    private function data(Request $request, bool $editing): array
    {
        return $request->validate([
            'revision' => $editing ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'objetivo' => ['required', Rule::in(['producto', 'categoria'])],
            'producto_id' => ['required_if:objetivo,producto', 'prohibited_unless:objetivo,producto', 'nullable', 'integer', 'min:1', 'exists:productos,id'],
            'categoria_id' => ['required_if:objetivo,categoria', 'prohibited_unless:objetivo,categoria', 'nullable', 'integer', 'min:1', 'exists:prod_categorias,id'],
            'alcance' => ['required', Rule::in(['todos', 'stock'])],
            'stock_id' => ['required_if:alcance,stock', 'prohibited_unless:alcance,stock', 'nullable', 'integer', 'min:1',
                Rule::exists('stocks', 'id')->whereIn('estado', DB::table('stock_estado')->where('descripcion', 'ACTIVO')->pluck('id')->all())],
            'titulo' => ['required', 'string', 'max:150', 'regex:/\S/u'],
            'requisitos' => ['required', 'array', 'list', 'min:1', 'max:15'],
            'requisitos.*' => ['required', 'string', 'max:300', 'regex:/\S/u'],
            'activo' => ['required', 'boolean'],
        ]);
    }

    private function values(array $data): array
    {
        return [
            'stock_id' => $data['alcance'] === 'stock' ? $data['stock_id'] : null,
            'producto_id' => $data['objetivo'] === 'producto' ? $data['producto_id'] : null,
            'categoria_id' => $data['objetivo'] === 'categoria' ? $data['categoria_id'] : null,
            'titulo' => trim($data['titulo']), 'activo' => (bool) $data['activo'],
            'requisitos' => json_encode(array_map('trim', $data['requisitos']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'revision' => PosSaleInformation::nextRevision(), 'updated_at' => now(),
        ];
    }
}
