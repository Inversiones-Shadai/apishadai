<?php

namespace App\Services\Farma;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TaskService
{
    public const STATES = ['PENDIENTE', 'EN_PROCESO', 'COMPLETADA'];

    public static function today(): string
    {
        return now('America/Tegucigalpa')->toDateString();
    }

    public function schedule(array $data): array
    {
        $data = ['programacion' => $data['programacion'] ?? 'UNICA',
            'fecha_inicio' => $data['fecha_inicio'] ?? self::today(), 'fecha_fin' => $data['fecha_fin'] ?? null,
            'fechas' => $data['fechas'] ?? []];
        Validator::make($data, [
            'programacion' => ['required', Rule::in(['UNICA', 'DIARIA', 'FECHAS'])],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:fecha_inicio'],
            'fechas' => ['array', 'max:366', Rule::requiredIf($data['programacion'] === 'FECHAS')],
            'fechas.*' => ['required', 'date_format:Y-m-d', 'distinct'],
        ])->validate();
        sort($data['fechas']);

        return ['programacion' => $data['programacion'],
            'fecha_inicio' => $data['programacion'] === 'FECHAS' ? $data['fechas'][0] : $data['fecha_inicio'],
            'fecha_fin' => $data['programacion'] === 'DIARIA' ? $data['fecha_fin'] : null,
            'fechas' => $data['programacion'] === 'FECHAS' ? json_encode($data['fechas'], JSON_THROW_ON_ERROR) : null];
    }

    public function mutate(int $actor, string $action, ?int $id, array $data): array
    {
        return DB::transaction(function () use ($actor, $action, $id, $data): array {
            $hash = hash('sha256', json_encode([$actor, $action, $id, $data], JSON_THROW_ON_ERROR));
            $previous = DB::table('farma_tarea_solicitudes')->where('request_id', $data['request_id'])->lockForUpdate()->first();
            if ($previous) {
                abort_unless(hash_equals($previous->huella, $hash), 409, 'La solicitud cambió de contenido.');

                return json_decode($previous->respuesta, true, 512, JSON_THROW_ON_ERROR);
            }
            $old = $id === null ? null : DB::table('farma_tareas')->where('id', $id)->lockForUpdate()->first();
            if ($id !== null) {
                abort_unless($old !== null, 404);
                abort_unless((int) $old->revision === (int) $data['revision'] && $old->estado !== 'CANCELADA', 409, 'La tarea cambió. Actualiza la lista antes de continuar.');
            }
            if ($action === 'cancelar') {
                $values = ['estado' => 'CANCELADA', 'modificado_por' => $actor, 'revision' => $old->revision + 1, 'updated_at' => now()];
            } else {
                abort_unless(DB::table('stocks as s')->join('stock_estado as e', 'e.id', '=', 's.estado')
                    ->where('s.id', $data['stock_id'])->where('e.descripcion', 'ACTIVO')->lockForUpdate()->exists(), 422, 'Selecciona un stock activo.');
                abort_unless(DB::table('usuariospos as u')->join('rolespos as r', 'r.id', '=', 'u.rol_id')
                    ->where('u.id', $data['usuario_pos_id'])->where('u.activo', true)->where('r.activo', true)->lockForUpdate()->exists(), 422, 'Selecciona un usuario POS activo.');
                $reassigned = $old && ((int) $old->stock_id !== (int) $data['stock_id'] || (int) $old->usuario_pos_id !== (int) $data['usuario_pos_id']);
                $values = ['stock_id' => $data['stock_id'], 'usuario_pos_id' => $data['usuario_pos_id'],
                    'titulo' => trim($data['titulo']), 'descripcion' => trim($data['descripcion']),
                    'estado' => $reassigned || ! $old ? 'PENDIENTE' : $old->estado,
                    'revision' => $old ? $old->revision + 1 : 1, 'modificado_por' => $actor, 'updated_at' => now()] + $this->schedule($data);
            }
            if ($old) {
                DB::table('farma_tareas')->where('id', $id)->update($values);
            } else {
                $id = DB::table('farma_tareas')->insertGetId($values + ['creado_por' => $actor, 'created_at' => now()]);
            }
            $task = DB::table('farma_tareas')->find($id);
            $this->synchronizeDays($task);
            $result = $this->document($task);
            $this->remember($data['request_id'], $hash, $result);

            return $result;
        }, 3);
    }

    public function document(object $task): array
    {
        return ['id' => (string) $task->id, 'stock_id' => (string) $task->stock_id, 'usuario_pos_id' => (string) $task->usuario_pos_id,
            'titulo' => $task->titulo, 'descripcion' => $task->descripcion, 'estado' => $task->estado,
            'revision' => (int) $task->revision, 'activo' => $task->estado !== 'CANCELADA',
            'programacion' => $task->programacion, 'fecha_inicio' => $task->fecha_inicio, 'fecha_fin' => $task->fecha_fin,
            'fechas' => json_decode($task->fechas ?? '[]', true, 512, JSON_THROW_ON_ERROR),
            'updated_at' => Carbon::parse($task->updated_at, config('app.timezone'))->toIso8601String()];
    }

    /** Materialize the next month so a connected HQ can prepare scheduled work in advance. */
    public function materialize(int $stock): void
    {
        DB::table('farma_tareas')->where('stock_id', $stock)->where('programacion', 'DIARIA')
            ->where('estado', '!=', 'CANCELADA')->orderBy('id')->select('id')->chunkById(100, function ($tasks): void {
                foreach ($tasks as $row) {
                    DB::transaction(function () use ($row): void {
                        $task = DB::table('farma_tareas')->where('id', $row->id)->lockForUpdate()->first();
                        if ($task && $task->estado !== 'CANCELADA') {
                            $this->synchronizeDays($task, false);
                        }
                    }, 3);
                }
            });
    }

    private function dates(object $task): array
    {
        if ($task->programacion === 'FECHAS') {
            return json_decode($task->fechas, true, 512, JSON_THROW_ON_ERROR);
        }
        if ($task->programacion !== 'DIARIA') {
            return [$task->fecha_inicio]; // Null dates preserve assignments created before scheduling existed.
        }
        $dates = [];
        $start = Carbon::parse(max($task->fecha_inicio, self::today()), 'America/Tegucigalpa');
        $end = Carbon::parse(self::today(), 'America/Tegucigalpa')->addDays(30)->toDateString();
        $end = $task->fecha_fin ? min($end, $task->fecha_fin) : $end;
        while ($start->toDateString() <= $end) {
            $dates[] = $start->toDateString();
            $start->addDay();
        }

        return $dates;
    }

    private function synchronizeDays(object $task, bool $editing = true): void
    {
        $dates = $task->estado === 'CANCELADA' ? [] : $this->dates($task);
        $existing = DB::table('farma_tarea_dias')->where('tarea_id', $task->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($existing as $day) {
            if (! $editing && ($day->activo || ! in_array($day->fecha, $dates, true))) {
                continue;
            }
            // Completed past dates retain their original assignment and description as history.
            if ($task->estado !== 'CANCELADA' && $day->fecha !== null && $day->fecha < self::today()) {
                continue;
            }
            $active = in_array($day->fecha, $dates, true);
            $reassigned = (string) $day->stock_id !== (string) $task->stock_id || (string) $day->usuario_pos_id !== (string) $task->usuario_pos_id;
            $values = ['revision' => $day->revision + 1, 'activo' => $active,
                'estado' => $active ? ($reassigned || ! $day->activo ? 'PENDIENTE' : $day->estado) : 'CANCELADA', 'updated_at' => now()];
            if ($active) {
                $values += ['stock_id' => $task->stock_id, 'usuario_pos_id' => $task->usuario_pos_id,
                    'titulo' => $task->titulo, 'descripcion' => $task->descripcion];
            }
            DB::table('farma_tarea_dias')->where('id', $day->id)->update($values);
            $updated = DB::table('farma_tarea_dias')->find($day->id);
            if ($active && (string) $day->stock_id !== (string) $task->stock_id) {
                $this->publish($updated, (int) $day->stock_id, false);
            }
            $this->publish($updated);
        }
        foreach ($dates as $date) {
            if ($existing->contains(fn ($day): bool => $day->fecha === $date)) {
                continue;
            }
            $id = DB::table('farma_tarea_dias')->insertGetId(['tarea_id' => $task->id, 'fecha' => $date,
                'stock_id' => $task->stock_id, 'usuario_pos_id' => $task->usuario_pos_id,
                'titulo' => $task->titulo, 'descripcion' => $task->descripcion, 'estado' => 'PENDIENTE',
                'revision' => 1, 'activo' => true, 'updated_at' => now()]);
            $this->publish(DB::table('farma_tarea_dias')->find($id));
        }
    }

    public function publish(object $task, ?int $stockId = null, ?bool $active = null): void
    {
        $stockId ??= (int) $task->stock_id;
        // Serialize publication per stock: a cursor must never pass an uncommitted lower sequence.
        DB::table('stocks')->where('id', $stockId)->lockForUpdate()->first();
        $schedule = $active === false ? null : DB::table('farma_tareas')->where('id', $task->tarea_id)->value('programacion');
        $document = $active === false ? ['id' => (string) $task->id, 'stock_id' => (string) $stockId,
            'revision' => (int) $task->revision, 'activo' => false] : [
                'id' => (string) $task->id, 'stock_id' => (string) $task->stock_id, 'usuario_pos_id' => (string) $task->usuario_pos_id,
                'titulo' => $task->titulo, 'descripcion' => $task->descripcion, 'estado' => $task->estado,
                'revision' => (int) $task->revision, 'activo' => (bool) $task->activo, 'fecha' => $task->fecha,
                'agenda_id' => (string) $task->tarea_id, 'programacion' => $schedule,
                'updated_at' => Carbon::parse($task->updated_at, config('app.timezone'))->toIso8601String(),
            ];
        DB::table('farma_tarea_cambios')->insert(['stock_id' => $stockId, 'tarea_id' => $task->id,
            'datos' => json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }

    public function remember(string $request, string $hash, array $response): void
    {
        DB::table('farma_tarea_solicitudes')->insert(['request_id' => $request, 'huella' => $hash,
            'respuesta' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }
}
