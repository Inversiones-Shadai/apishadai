<?php

namespace App\Http\Controllers\API\Headquarters;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PosLostSaleController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $id = ['required', 'string', 'regex:/\A[1-9][0-9]{0,19}\z/'];
        $data = $request->validate([
            'device_id' => ['required', 'integer', 'min:1'],
            'client_uuid' => ['required', 'uuid'],
            'payload' => ['required', 'array:stock_id,rows'],
            'payload.stock_id' => $id,
            'payload.rows' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'payload.rows.*' => ['required', 'array:request_id,producto_id,usuario_pos_id,reported_at'],
            'payload.rows.*.request_id' => ['required', 'uuid', 'distinct:ignore_case'],
            'payload.rows.*.producto_id' => $id,
            'payload.rows.*.usuario_pos_id' => $id,
            'payload.rows.*.reported_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
        ]);

        $device = Device::with(['Estado', 'Stock.Estado'])->find($data['device_id']);
        abort_unless($device && $device->hq_client_uuid === $data['client_uuid']
            && strtoupper((string) $device->Estado?->descripcion) === 'ACTIVO'
            && strtoupper((string) $device->Stock?->Estado?->descripcion) === 'ACTIVO', 401);

        $stock = (string) $device->stock;
        abort_unless($data['payload']['stock_id'] === $stock, 403);
        $rows = $data['payload']['rows'];

        $accepted = DB::transaction(function () use ($stock, $rows): array {
            DB::table('stocks')->where('id', $stock)->lockForUpdate()->first();
            $products = DB::table('productos')->whereIn('id', array_column($rows, 'producto_id'))
                ->get(['id', 'codigo', 'descripcion'])->keyBy('id');
            $users = DB::table('usuariospos')->whereIn('id', array_column($rows, 'usuario_pos_id'))->pluck('id')->flip();
            $requestIds = array_map('strtolower', array_column($rows, 'request_id'));
            $previous = DB::table('pos_lost_sales')->whereIn('request_id', $requestIds)->get()->keyBy('request_id');
            $results = [];

            foreach ($rows as $row) {
                $requestId = strtolower($row['request_id']);
                $timestamp = Carbon::parse($row['reported_at'])->utc()->toDateTimeString();
                $event = $previous->get($requestId);
                if ($event !== null) {
                    abort_unless((string) $event->stock_id === $stock && (string) $event->producto_id === $row['producto_id']
                        && (string) $event->usuario_pos_id === $row['usuario_pos_id'] && $event->reported_at === $timestamp, 409);
                    $results[] = ['request_id' => $requestId, 'accepted' => true];

                    continue;
                }

                $product = $products->get($row['producto_id']);
                if ($product === null || ! $users->has($row['usuario_pos_id'])) {
                    $results[] = ['request_id' => $requestId, 'accepted' => false];

                    continue;
                }

                DB::table('pos_lost_sales')->insert([
                    'request_id' => $requestId, 'stock_id' => $stock,
                    'producto_id' => $row['producto_id'], 'usuario_pos_id' => $row['usuario_pos_id'],
                    'producto_codigo' => $product->codigo, 'producto' => $product->descripcion,
                    'reported_at' => $timestamp,
                ]);
                $results[] = ['request_id' => $requestId, 'accepted' => true];
            }

            return $results;
        }, 3);

        return $this->sendResponse(['stock_id' => $stock, 'results' => $accepted], 'Ventas perdidas recibidas.');
    }
}
