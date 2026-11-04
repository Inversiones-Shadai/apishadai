<?php

namespace App\Services\Headquarters;

use App\Models\Device;
use App\Services\Farma\TaskService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PosTaskService
{
    public function execute(int $deviceId, string $clientUuid, string $action, array $payload): array
    {
        $id = ['required', 'string', 'regex:/\A[1-9][0-9]{0,19}\z/'];
        $rules = $action === 'listar' ? ['after_id' => ['required', 'string', 'regex:/\A[0-9]{1,20}\z/']] : [
            'request_id' => ['required', 'uuid'], 'stock_id' => $id, 'tarea_id' => $id, 'usuario_pos_id' => $id,
            'revision' => ['required', 'integer', 'min:1', 'max:9007199254740991'], 'estado' => ['required', 'in:'.implode(',', TaskService::STATES)],
        ];
        $data = Validator::make($payload, ['version' => ['required', 'integer', 'in:1']] + $rules)->validate();
        abort_unless(count($payload) === count($data), 422);

        return DB::transaction(function () use ($deviceId, $clientUuid, $action, $data): array {
            $device = Device::with(['Estado', 'Stock.Estado'])->lockForUpdate()->find($deviceId);
            abort_unless($device && $device->hq_client_uuid === $clientUuid
                && strtoupper((string) $device->Estado?->descripcion) === 'ACTIVO'
                && strtoupper((string) $device->Stock?->Estado?->descripcion) === 'ACTIVO', 401);
            $stock = (string) $device->stock;
            DB::table('stocks')->where('id', $stock)->lockForUpdate()->first();
            if ($action === 'listar') {
                app(TaskService::class)->materialize((int) $stock);
                $changes = DB::table('farma_tarea_cambios')->where('stock_id', $stock)->where('id', '>', $data['after_id'])->orderBy('id')->limit(201)->get();
                $page = $changes->take(200);

                return ['version' => 1, 'stock_id' => $stock, 'after_id' => $data['after_id'],
                    'next_id' => (string) ($page->last()?->id ?? $data['after_id']), 'has_more' => $changes->count() > 200,
                    'cambios' => $page->map(fn ($row): array => ['secuencia' => (string) $row->id, 'tarea' => json_decode($row->datos, true, 512, JSON_THROW_ON_ERROR)])->values()->all()];
            }
            abort_unless($action === 'estado' && $data['stock_id'] === $stock, 403);
            $hash = hash('sha256', json_encode(['POS', $stock, $data], JSON_THROW_ON_ERROR));
            $previous = DB::table('farma_tarea_solicitudes')->where('request_id', $data['request_id'])->lockForUpdate()->first();
            if ($previous) {
                abort_unless(hash_equals($previous->huella, $hash), 409);

                return json_decode($previous->respuesta, true, 512, JSON_THROW_ON_ERROR);
            }
            $parentId = DB::table('farma_tarea_dias')->where('id', $data['tarea_id'])->value('tarea_id');
            $parent = $parentId ? DB::table('farma_tareas')->where('id', $parentId)->lockForUpdate()->first() : null;
            $task = DB::table('farma_tarea_dias')->where('id', $data['tarea_id'])->lockForUpdate()->first();
            $allowed = $this->authorized($data['usuario_pos_id'], 'tarea.ver') && $this->authorized($data['usuario_pos_id'], 'tarea.estado');
            $code = ! $allowed ? 'SIN_ACCESO' : (! $task || (string) $task->stock_id !== $stock
                || (string) $task->usuario_pos_id !== $data['usuario_pos_id'] || ! $task->activo || ! $parent || $parent->estado === 'CANCELADA'
                || ($task->fecha !== null && $task->fecha > TaskService::today()) ? 'NO_ASIGNADA'
                : ((int) $task->revision !== $data['revision'] || $task->estado === 'COMPLETADA' ? 'REVISION_CONFLICTO' : 'ACEPTADA'));
            $tasks = app(TaskService::class);
            if ($code === 'ACEPTADA') {
                DB::table('farma_tarea_dias')->where('id', $task->id)->update(['estado' => $data['estado'], 'revision' => $task->revision + 1, 'updated_at' => now()]);
                DB::table('farma_tareas')->where('id', $parentId)->update(['revision' => $parent->revision + 1, 'updated_at' => now(),
                    'estado' => $task->fecha === null || $task->fecha === TaskService::today() ? $data['estado'] : $parent->estado]);
                $tasks->publish(DB::table('farma_tarea_dias')->find($task->id));
            }
            $result = ['version' => 1, 'request_id' => $data['request_id'], 'stock_id' => $stock,
                'tarea_id' => $data['tarea_id'], 'usuario_pos_id' => $data['usuario_pos_id'],
                'accepted' => $code === 'ACEPTADA', 'code' => $code];
            $tasks->remember($data['request_id'], $hash, $result);

            return $result;
        }, 3);
    }

    private function authorized(string $user, string $action): bool
    {
        return DB::table('usuariospos as u')->join('rolespos as r', 'r.id', '=', 'u.rol_id')
            ->join('permisosusuariospos as p', 'p.usuario', '=', 'u.id')
            ->join('modulospos as m', 'm.id', '=', 'p.modulo')
            ->join('vistaspos as v', fn ($join) => $join->on('v.id', '=', 'p.vista')->on('v.modulo_id', '=', 'm.id'))
            ->join('botonespos as b', fn ($join) => $join->on('b.id', '=', 'p.actionvista')->on('b.vista_id', '=', 'v.id'))
            ->where('u.id', $user)->where('u.activo', true)->where('r.activo', true)
            ->where('m.codigo', 'tareas')->where('v.codigo', 'tareas.asignadas')->where('b.codigo', $action)
            ->where('m.activo', true)->where('v.activo', true)->where('b.activo', true)
            ->where(fn ($query) => $query->whereNull('p.lifetime')->orWhere('p.lifetime', '>', now()->timestamp))->lockForUpdate()->exists();
    }
}
