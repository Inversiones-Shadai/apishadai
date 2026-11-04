<?php

namespace App\Services\Headquarters;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InventoryControlService
{
    public function execute(int $deviceId, string $clientUuid, array $payload): array
    {
        $id = ['required', 'string', 'regex:/\A[1-9][0-9]{0,19}\z/'];
        $signed = ['required', 'string', 'regex:/\A-?[0-9]{1,16}(\.[0-9]{1,4})?\z/'];
        $withCosts = ($payload['version'] ?? null) === 2;
        Validator::make($payload, [
            'version' => ['required', 'integer', 'in:1,2'], 'request_id' => ['required', 'uuid'],
            'stock_id' => $id, 'usuario_pos_id' => $id, 'inventario_uuid' => ['required', 'uuid'],
            'nombre' => ['required', 'string', 'max:200'], 'creado_at' => ['required', 'date'],
            'finalizado_at' => ['required', 'date', 'after_or_equal:creado_at'],
            'letras' => ['required', 'array', 'min:1', 'max:26'],
            'letras.*' => ['required', 'array:letra,productos'],
            'letras.*.letra' => ['required', 'string', 'regex:/\A[A-Z]\z/', 'distinct:strict'],
            'letras.*.productos' => ['required', 'array', 'min:1'],
            'letras.*.productos.*' => ['required', 'array:producto_id,lote_id,codigo,nombre,lote,cantidad_sistema,cantidad_conteo,diferencia'.($withCosts ? ',costo_unitario' : '')],
            'letras.*.productos.*.costo_unitario' => $withCosts ? ['present', 'nullable', 'string', 'regex:/\A[0-9]{1,15}(\.[0-9]{1,4})?\z/'] : ['prohibited'],
            'letras.*.productos.*.producto_id' => $id, 'letras.*.productos.*.lote_id' => $id,
            'letras.*.productos.*.codigo' => ['present', 'nullable', 'string', 'max:200'],
            'letras.*.productos.*.nombre' => ['present', 'nullable', 'string', 'max:500'],
            'letras.*.productos.*.lote' => ['present', 'nullable', 'string', 'max:200'],
            'letras.*.productos.*.cantidad_sistema' => $signed,
            'letras.*.productos.*.cantidad_conteo' => ['required', 'string', 'regex:/\A[0-9]{1,15}(\.[0-9]{1,4})?\z/'],
            'letras.*.productos.*.diferencia' => $signed,
        ])->validate();
        abort_unless(in_array($payload['version'] ?? null, [1, 2], true) && extension_loaded('bcmath'), 422);
        abort_unless(array_diff(array_keys($payload), ['version', 'request_id', 'stock_id', 'usuario_pos_id', 'inventario_uuid', 'nombre', 'creado_at', 'finalizado_at', 'letras']) === [], 422);
        $lots = [];
        foreach ($payload['letras'] as $letter) {
            foreach ($letter['productos'] as $line) {
                abort_if(isset($lots[$line['lote_id']]), 422);
                $lots[$line['lote_id']] = true;
                abort_unless(bccomp(bcsub($line['cantidad_conteo'], $line['cantidad_sistema'], 4), $line['diferencia'], 4) === 0, 422);
            }
        }

        return DB::transaction(function () use ($deviceId, $clientUuid, $payload): array {
            $device = Device::with(['Estado', 'Stock.Estado'])->lockForUpdate()->find($deviceId);
            abort_unless($device && $device->hq_client_uuid === $clientUuid
                && strtoupper((string) $device->Estado?->descripcion) === 'ACTIVO'
                && strtoupper((string) $device->Stock?->Estado?->descripcion) === 'ACTIVO', 401);
            $stock = (string) $device->stock;
            abort_unless($payload['stock_id'] === $stock, 403);
            DB::table('stocks')->where('id', $stock)->lockForUpdate()->firstOrFail();
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', $json);
            $previous = DB::table('stock_control_inventario_solicitudes')->where('request_id', $payload['request_id'])->lockForUpdate()->first();
            if ($previous) {
                abort_unless((string) $previous->stock_id === $stock && (int) $previous->device_id === $deviceId && hash_equals($previous->huella, $hash), 409);

                return json_decode($previous->respuesta, true, 512, JSON_THROW_ON_ERROR);
            }
            $code = 'ACEPTADA';
            if (! $this->authorized($payload['usuario_pos_id'], 'control.ver') || ! $this->authorized($payload['usuario_pos_id'], 'control.finalizar')) {
                $code = 'SIN_ACCESO';
            } elseif (DB::table('stock_control_inventario')->where('inventario_uuid', $payload['inventario_uuid'])->lockForUpdate()->exists()) {
                $code = 'INVENTARIO_CONFLICTO';
            }
            if ($code === 'ACEPTADA') {
                DB::table('stock_control_inventario')->insert([
                    'inventario_uuid' => $payload['inventario_uuid'], 'stock_id' => $stock, 'device_id' => $deviceId,
                    'usuario_pos_id' => $payload['usuario_pos_id'], 'nombre' => $payload['nombre'],
                    'datos' => $json, 'created_at' => now(),
                ]);
            }
            $response = ['version' => 1, 'request_id' => $payload['request_id'], 'stock_id' => $stock,
                'inventario_uuid' => $payload['inventario_uuid'], 'accepted' => $code === 'ACEPTADA', 'code' => $code];
            DB::table('stock_control_inventario_solicitudes')->insert([
                'request_id' => $payload['request_id'], 'stock_id' => $stock, 'device_id' => $deviceId,
                'huella' => $hash, 'datos' => $json, 'respuesta' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return $response;
        }, 3);
    }

    private function authorized(string $user, string $action): bool
    {
        if (! DB::table('usuariospos as u')->join('rolespos as r', 'r.id', '=', 'u.rol_id')
            ->where('u.id', $user)->where('u.activo', true)->where('r.activo', true)->lockForUpdate()->exists()) {
            return false;
        }

        return DB::table('permisosusuariospos as p')->join('modulospos as m', 'm.id', '=', 'p.modulo')
            ->join('vistaspos as v', fn ($j) => $j->on('v.id', '=', 'p.vista')->on('v.modulo_id', '=', 'm.id'))
            ->join('botonespos as b', fn ($j) => $j->on('b.id', '=', 'p.actionvista')->on('b.vista_id', '=', 'v.id'))
            ->where('p.usuario', $user)->where('m.codigo', 'inventario')->where('v.codigo', 'inventario.control')
            ->where('b.codigo', $action)->where('m.activo', true)->where('v.activo', true)->where('b.activo', true)
            ->where(fn ($q) => $q->whereNull('p.lifetime')->orWhere('p.lifetime', '>', now()->timestamp))->lockForUpdate()->exists();
    }
}
