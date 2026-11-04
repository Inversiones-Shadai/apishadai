<?php

namespace App\Services\Headquarters;

use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InventoryMatrixService
{
    public function execute(int $deviceId, string $clientUuid, array $payload): array
    {
        $id = ['required', 'string', 'regex:/\A[1-9][0-9]{0,19}\z/'];
        $quantity = ['required', 'string', 'regex:/\A[0-9]{1,15}(\.[0-9]{1,4})?\z/'];
        $balances = ($payload['version'] ?? null) === 2 && ($payload['tipo'] ?? null) === 'EXISTENCIAS';
        $legacy = ($payload['version'] ?? null) === 1;
        $publication = ($payload['version'] ?? null) === 4;
        $withdraw = $publication && ($payload['tipo'] ?? null) === 'RETIRAR_REORDEN';
        Validator::make($payload, [
            'version' => ['required', 'integer', 'in:1,2,3,4'], 'request_id' => ['required', 'uuid'],
            'tipo' => $publication ? ['required', 'in:REORDEN,RETIRAR_REORDEN'] : ($balances ? ['required', 'in:EXISTENCIAS'] : ['prohibited']),
            'stock_id' => $id, 'producto_id' => $id, 'usuario_pos_id' => $balances || $withdraw ? ['prohibited'] : $id,
            'revision' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'revision_configuracion' => $publication && ! $withdraw ? ['required', 'integer', 'min:1', 'max:9007199254740991'] : ['prohibited'],
            'minimo' => $balances || $withdraw ? ['prohibited'] : $quantity,
            'maximo' => $balances || $withdraw ? ['prohibited'] : $quantity,
            'punto_reorden' => $legacy ? $quantity : ['prohibited'],
            'existencia_total' => $balances || ($publication && ! $withdraw) ? $quantity : ['prohibited'],
        ])->validate();
        abort_unless(extension_loaded('bcmath'), 503);
        if (! $balances && ! $withdraw) {
            abort_unless(bccomp($payload['minimo'], $payload['maximo'], 4) <= 0, 422);
            if ($legacy) {
                abort_unless(bccomp($payload['minimo'], $payload['punto_reorden'], 4) <= 0
                    && bccomp($payload['punto_reorden'], $payload['maximo'], 4) <= 0, 422);
            }
        }
        if ($publication && ! $withdraw) {
            abort_unless(bccomp($payload['minimo'], $payload['existencia_total'], 4) > 0
                || (bccomp($payload['existencia_total'], '0', 4) === 0 && bccomp($payload['maximo'], '0', 4) > 0), 422);
        }

        return DB::transaction(function () use ($deviceId, $clientUuid, $payload, $balances, $publication, $withdraw): array {
            $device = Device::with(['Estado', 'Stock.Estado'])->lockForUpdate()->find($deviceId);
            abort_unless($device && $device->hq_client_uuid === $clientUuid
                && strtoupper((string) $device->Estado?->descripcion) === 'ACTIVO'
                && strtoupper((string) $device->Stock?->Estado?->descripcion) === 'ACTIVO', 401);
            $stock = (string) $device->stock;
            abort_unless($payload['stock_id'] === $stock, 403);
            DB::table('stocks')->where('id', $stock)->lockForUpdate()->firstOrFail();
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', $json);
            $previous = DB::table('stock_matriz_solicitudes')->where('request_id', $payload['request_id'])->lockForUpdate()->first();
            if ($previous) {
                abort_unless((string) $previous->stock_id === $stock && (int) $previous->device_id === $deviceId
                    && hash_equals($previous->huella, $hash), 409);

                return json_decode($previous->respuesta, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($publication) {
                return $this->publish($deviceId, $stock, $payload, $withdraw, $json, $hash);
            }
            $code = 'ACEPTADA';
            if (! $balances && (! $this->authorized($payload['usuario_pos_id'], 'matriz.ver')
                || ! $this->authorized($payload['usuario_pos_id'], 'matriz.modificar'))) {
                $code = 'SIN_ACCESO';
            } elseif (! DB::table('productos')->where('id', $payload['producto_id'])->exists()) {
                $code = 'PRODUCTO_AUSENTE';
            }
            $key = ['stock_id' => $stock, 'producto_id' => $payload['producto_id']];
            $table = $balances ? 'stock_matriz_existencias' : 'stock_matriz_inventario';
            $current = DB::table($table)->where($key)->lockForUpdate()->first();
            if ($code === 'ACEPTADA' && ($balances || (int) $payload['version'] === 3
                ? (int) $payload['revision'] <= (int) ($current?->revision ?? 0)
                : (int) $payload['revision'] !== (int) ($current?->revision ?? 0) + 1)) {
                $code = 'REVISION_CONFLICTO';
            }
            $accepted = $code === 'ACEPTADA';
            if ($accepted) {
                $values = $balances ? ['existencia_total' => $payload['existencia_total']] : [
                    'minimo' => $payload['minimo'], 'maximo' => $payload['maximo'],
                    'usuario_pos_id' => $payload['usuario_pos_id'], 'punto_reorden' => null,
                ];
                DB::table($table)->updateOrInsert($key, $values + [
                    'revision' => $payload['revision'],
                    'device_id' => $deviceId, 'updated_at' => now(),
                ]);
            }
            $response = ['version' => 1, 'request_id' => $payload['request_id'], 'stock_id' => $stock,
                'producto_id' => $payload['producto_id'], 'revision' => (int) $payload['revision'],
                'accepted' => $accepted, 'code' => $code];
            DB::table('stock_matriz_solicitudes')->insert([
                'request_id' => $payload['request_id'], 'stock_id' => $stock, 'device_id' => $deviceId,
                'huella' => $hash, 'datos' => $json, 'respuesta' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);

            return $response;
        }, 3);
    }

    private function publish(int $deviceId, string $stock, array $payload, bool $withdraw, string $json, string $hash): array
    {
        $key = ['stock_id' => $stock, 'producto_id' => $payload['producto_id']];
        $balance = DB::table('stock_matriz_existencias')->where($key)->lockForUpdate()->first();
        $settings = DB::table('stock_matriz_inventario')->where($key)->lockForUpdate()->first();
        $code = 'ACEPTADA';
        if (! $withdraw && ! DB::table('productos')->where('id', $payload['producto_id'])->exists()) {
            $code = 'PRODUCTO_AUSENTE';
        } elseif ((int) $payload['revision'] <= (int) ($balance?->revision ?? 0)) {
            $code = 'REVISION_CONFLICTO';
        } elseif (! $withdraw) {
            $configRevision = (int) $payload['revision_configuracion'];
            if ($settings && ($configRevision < (int) $settings->revision || ($configRevision === (int) $settings->revision
                && (bccomp($payload['minimo'], (string) $settings->minimo, 4) !== 0 || bccomp($payload['maximo'], (string) $settings->maximo, 4) !== 0)))) {
                $code = 'REVISION_CONFLICTO';
            } elseif ((! $settings || $configRevision > (int) $settings->revision)
                && (! $this->authorized($payload['usuario_pos_id'], 'matriz.ver') || ! $this->authorized($payload['usuario_pos_id'], 'matriz.modificar'))) {
                $code = 'SIN_ACCESO';
            }
        }
        if ($code === 'ACEPTADA') {
            if (! $withdraw) {
                DB::table('stock_matriz_inventario')->updateOrInsert($key, [
                    'minimo' => $payload['minimo'], 'maximo' => $payload['maximo'], 'punto_reorden' => null,
                    'revision' => $payload['revision_configuracion'],
                    'usuario_pos_id' => $settings && (int) $settings->revision === (int) $payload['revision_configuracion'] ? $settings->usuario_pos_id : $payload['usuario_pos_id'],
                    'device_id' => $deviceId, 'updated_at' => now(),
                ]);
            }
            DB::table('stock_matriz_existencias')->updateOrInsert($key, [
                'existencia_total' => $withdraw ? $balance?->existencia_total : $payload['existencia_total'],
                'reorden_activo' => ! $withdraw, 'revision' => $payload['revision'], 'device_id' => $deviceId, 'updated_at' => now(),
            ]);
        }
        $response = ['version' => 1, 'request_id' => $payload['request_id'], 'stock_id' => $stock,
            'producto_id' => $payload['producto_id'], 'revision' => (int) $payload['revision'], 'accepted' => $code === 'ACEPTADA', 'code' => $code];
        DB::table('stock_matriz_solicitudes')->insert([
            'request_id' => $payload['request_id'], 'stock_id' => $stock, 'device_id' => $deviceId, 'huella' => $hash,
            'datos' => $json, 'respuesta' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);

        return $response;
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
            ->where('p.usuario', $user)->where('m.codigo', 'inventario')->where('v.codigo', 'inventario.matriz')
            ->where('b.codigo', $action)->where('m.activo', true)->where('v.activo', true)->where('b.activo', true)
            ->where(fn ($q) => $q->whereNull('p.lifetime')->orWhere('p.lifetime', '>', now()->timestamp))->lockForUpdate()->exists();
    }
}
