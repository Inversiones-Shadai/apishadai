<?php

namespace App\Http\Controllers\API\Farma;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTaskPermission;
use App\Services\Farma\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['stock_id' => ['nullable', 'integer', 'min:1'], 'usuario_pos_id' => ['nullable', 'integer', 'min:1'],
            'estado' => ['nullable', 'in:PENDIENTE,EN_PROCESO,COMPLETADA,CANCELADA'], 'buscar' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        if (array_filter($data, fn ($value, $key): bool => $key !== 'page' && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH)) {
            abort_unless(app(EnsureTaskPermission::class)->allows($request, 'tarbtn06'), 403);
        }
        $query = DB::table('farma_tareas as t')->join('stocks as s', 's.id', '=', 't.stock_id')
            ->join('usuariospos as u', 'u.id', '=', 't.usuario_pos_id')
            ->select('t.id', 't.stock_id', 't.usuario_pos_id', 't.titulo', 't.revision', 't.updated_at',
                't.programacion', 't.fecha_inicio', 't.fecha_fin', 't.fechas', 's.descripcion as stock_nombre', 'u.nombre as usuario_nombre')
            ->selectRaw("CASE WHEN t.estado = 'CANCELADA' THEN 'CANCELADA' ELSE COALESCE((SELECT d.estado FROM farma_tarea_dias d WHERE d.tarea_id = t.id AND d.activo = 1 AND (d.fecha = ? OR d.fecha IS NULL) ORDER BY d.id DESC LIMIT 1), 'PENDIENTE') END as estado", [TaskService::today()])
            ->selectRaw('EXISTS(SELECT 1 FROM farma_tarea_dias d WHERE d.tarea_id = t.id AND d.activo = 1 AND (d.fecha = ? OR d.fecha IS NULL)) as programada_hoy', [TaskService::today()]);
        foreach (['stock_id', 'usuario_pos_id'] as $field) {
            if (! empty($data[$field])) {
                $query->where('t.'.$field, $data[$field]);
            }
        }
        if (! empty($data['estado'])) {
            $query->whereRaw("CASE WHEN t.estado = 'CANCELADA' THEN 'CANCELADA' ELSE COALESCE((SELECT d.estado FROM farma_tarea_dias d WHERE d.tarea_id = t.id AND d.activo = 1 AND (d.fecha = ? OR d.fecha IS NULL) ORDER BY d.id DESC LIMIT 1), 'PENDIENTE') END = ?", [TaskService::today(), $data['estado']]);
        }
        if (($search = trim($data['buscar'] ?? '')) !== '') {
            $query->where(fn ($q) => $q->where('t.titulo', 'like', '%'.$search.'%')->orWhere('t.descripcion', 'like', '%'.$search.'%'));
        }

        return $this->sendResponse($query->orderByDesc('t.id')->paginate(50), 'ok', 200, true);
    }

    public function options(): JsonResponse
    {
        return $this->sendResponse([
            'stocks' => DB::table('stocks as s')->join('stock_estado as e', 'e.id', '=', 's.estado')
                ->where('e.descripcion', 'ACTIVO')->orderBy('s.descripcion')->get(['s.id', 's.descripcion']),
            'usuarios' => DB::table('usuariospos as u')->join('rolespos as r', 'r.id', '=', 'u.rol_id')
                ->where('u.activo', true)->where('r.activo', true)->orderBy('u.nombre')->get(['u.id', 'u.nombre']),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $task = DB::table('farma_tareas')->find($id);
        abort_unless($task, 404);

        return $this->sendResponse(app(TaskService::class)->document($task) + [
            'dias' => DB::table('farma_tarea_dias')->where('tarea_id', $id)->orderByDesc('fecha')->limit(366)
                ->get(['fecha', 'estado', 'activo', 'usuario_pos_id', 'stock_id'])->all(),
        ]);
    }

    public function create(Request $request, TaskService $service): JsonResponse
    {
        return $this->sendResponse($service->mutate((int) $request->user()->getAuthIdentifier(), 'crear', null, $this->data($request)), 'Tarea creada y asignada.', 201);
    }

    public function update(Request $request, TaskService $service, int $id): JsonResponse
    {
        return $this->sendResponse($service->mutate((int) $request->user()->getAuthIdentifier(), 'modificar', $id, $this->data($request, true)), 'Tarea actualizada.');
    }

    public function cancel(Request $request, TaskService $service, int $id): JsonResponse
    {
        $data = $request->validate(['request_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1']]);

        return $this->sendResponse($service->mutate((int) $request->user()->getAuthIdentifier(), 'cancelar', $id, $data), 'Tarea cancelada.');
    }

    private function data(Request $request, bool $editing = false): array
    {
        return $request->validate(['request_id' => ['required', 'uuid'], 'revision' => $editing ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'stock_id' => ['required', 'integer', 'min:1'], 'usuario_pos_id' => ['required', 'integer', 'min:1'],
            'titulo' => ['required', 'string', 'max:200', 'regex:/\S/u'], 'descripcion' => ['required', 'string', 'max:4000', 'regex:/\S/u'],
            'programacion' => ['sometimes', 'in:UNICA,DIARIA,FECHAS'], 'fecha_inicio' => ['nullable', 'date_format:Y-m-d'],
            'fecha_fin' => ['nullable', 'date_format:Y-m-d'], 'fechas' => ['sometimes', 'array', 'max:366'],
            'fechas.*' => ['required', 'date_format:Y-m-d', 'distinct']]);
    }
}
