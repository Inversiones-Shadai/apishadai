<?php

namespace Tests\Feature;

use App\Http\Controllers\API\Farma\TaskController;
use App\Http\Middleware\EnsureTaskPermission;
use App\Models\User;
use App\Services\Farma\TaskService;
use App\Services\Headquarters\ChangeRecorder;
use App\Services\Headquarters\PosTaskService;
use Carbon\Carbon;
use Database\Seeders\TaskPermissionCatalogSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TaskChannelTest extends TestCase
{
    private const CLIENT = '10000000-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        foreach (['device_estado', 'stock_estado'] as $table) {
            Schema::create($table, fn (Blueprint $t) => [$t->id(), $t->string('descripcion')]);
            DB::table($table)->insert(['id' => 1, 'descripcion' => 'ACTIVO']);
        }
        Schema::create('stocks', fn (Blueprint $t) => [$t->id(), $t->integer('estado'), $t->string('descripcion')]);
        DB::table('stocks')->insert([['id' => 1, 'estado' => 1, 'descripcion' => 'TEST A'], ['id' => 2, 'estado' => 1, 'descripcion' => 'TEST B']]);
        Schema::create('devices', fn (Blueprint $t) => [$t->id(), $t->integer('stock'), $t->integer('estado'), $t->string('hq_client_uuid')]);
        DB::table('devices')->insert([['id' => 1, 'stock' => 1, 'estado' => 1, 'hq_client_uuid' => self::CLIENT], ['id' => 2, 'stock' => 2, 'estado' => 1, 'hq_client_uuid' => self::CLIENT]]);
        foreach (['rolespos', 'modulospos', 'vistaspos', 'botonespos', 'usuariospos'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table): void {
                $t->id();
                $t->boolean('activo');
                $t->string('codigo')->nullable();
                $t->string('nombre')->nullable();
                $t->timestamps();
                if ($table === 'usuariospos') {
                    $t->integer('rol_id');
                }
                if ($table === 'vistaspos') {
                    $t->integer('modulo_id');
                }
                if ($table === 'botonespos') {
                    $t->integer('vista_id');
                }
            });
        }
        DB::table('rolespos')->insert(['id' => 1, 'activo' => true]);
        DB::table('usuariospos')->insert([['id' => 7, 'rol_id' => 1, 'activo' => true, 'nombre' => 'TEST User'], ['id' => 8, 'rol_id' => 1, 'activo' => true, 'nombre' => 'TEST Other']]);
        DB::table('modulospos')->insert(['id' => 1, 'codigo' => 'tareas', 'activo' => true]);
        DB::table('vistaspos')->insert(['id' => 1, 'modulo_id' => 1, 'codigo' => 'tareas.asignadas', 'activo' => true]);
        Schema::create('permisosusuariospos', fn (Blueprint $t) => [$t->id(), $t->integer('usuario'), $t->integer('modulo'), $t->integer('vista'), $t->integer('actionvista'), $t->integer('lifetime')->nullable()]);
        foreach ([1 => 'tarea.ver', 2 => 'tarea.estado'] as $id => $action) {
            DB::table('botonespos')->insert(['id' => $id, 'vista_id' => 1, 'codigo' => $action, 'activo' => true]);
            foreach ([7, 8] as $user) {
                DB::table('permisosusuariospos')->insert(['usuario' => $user, 'modulo' => 1, 'vista' => 1, 'actionvista' => $id]);
            }
        }
        (require database_path('migrations/2026_10_17_000001_farma_tasks.php'))->up();
        (require database_path('migrations/2026_10_17_000003_add_task_schedules.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        parent::tearDown();
    }

    private function create(): array
    {
        return app(TaskService::class)->mutate(9, 'crear', null, ['request_id' => (string) Str::uuid(), 'stock_id' => 1,
            'usuario_pos_id' => 7, 'titulo' => 'TEST task', 'descripcion' => 'TEST instructions']);
    }

    private function updatePayload(array $task): array
    {
        return ['version' => 1, 'request_id' => (string) Str::uuid(), 'stock_id' => '1', 'tarea_id' => $task['id'], 'usuario_pos_id' => '7', 'revision' => $task['revision'], 'estado' => 'EN_PROCESO'];
    }

    private function callPos(string $action, array $data, int $device = 1): array
    {
        return app(PosTaskService::class)->execute($device, self::CLIENT, $action, $data);
    }

    public function test_assignment_is_published_only_to_its_stock(): void
    {
        $task = $this->create();
        $page = $this->callPos('listar', ['version' => 1, 'after_id' => '0']);
        $this->assertSame('7', $page['cambios'][0]['tarea']['usuario_pos_id']);
        $this->assertSame($task['id'], $page['cambios'][0]['tarea']['id']);
        $this->assertSame([], $this->callPos('listar', ['version' => 1, 'after_id' => '0'], 2)['cambios']);
    }

    public function test_status_change_is_idempotent_and_checked_against_user_stock_and_revision(): void
    {
        $task = $this->create();
        $payload = $this->updatePayload($task);
        $first = $this->callPos('estado', $payload);
        $this->assertTrue($first['accepted']);
        $this->assertSame($first, $this->callPos('estado', $payload));
        $this->assertSame(2, (int) DB::table('farma_tareas')->value('revision'));
        $this->assertSame(2, DB::table('farma_tarea_cambios')->count());
        $stale = $payload;
        $stale['request_id'] = (string) Str::uuid();
        $this->assertSame('REVISION_CONFLICTO', $this->callPos('estado', $stale)['code']);
        $other = $stale;
        $other['request_id'] = (string) Str::uuid();
        $other['usuario_pos_id'] = '8';
        $this->assertSame('NO_ASIGNADA', $this->callPos('estado', $other)['code']);
    }

    public function test_another_stock_cannot_change_a_task(): void
    {
        $payload = $this->updatePayload($this->create());
        $payload['stock_id'] = '2';
        $this->assertSame('NO_ASIGNADA', $this->callPos('estado', $payload, 2)['code']);
        $this->assertSame('PENDIENTE', DB::table('farma_tareas')->value('estado'));
    }

    public function test_reusing_a_request_with_changed_content_is_rejected(): void
    {
        $payload = $this->updatePayload($this->create());
        $this->callPos('estado', $payload);
        $payload['estado'] = 'COMPLETADA';
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        $this->callPos('estado', $payload);
    }

    public function test_revoked_expired_or_inactive_pos_permissions_do_not_change_tasks(): void
    {
        $task = $this->create();
        DB::table('permisosusuariospos')->where('usuario', 7)->where('actionvista', 2)->update(['lifetime' => now()->timestamp - 1]);
        $payload = $this->updatePayload($task);
        $this->assertSame('SIN_ACCESO', $this->callPos('estado', $payload)['code']);
        DB::table('permisosusuariospos')->where('usuario', 7)->update(['lifetime' => null]);
        DB::table('rolespos')->update(['activo' => false]);
        $payload['request_id'] = (string) Str::uuid();
        $this->assertSame('SIN_ACCESO', $this->callPos('estado', $payload)['code']);
        $this->assertSame('PENDIENTE', DB::table('farma_tareas')->value('estado'));
    }

    public function test_reassignment_retires_the_old_stock_without_exposing_the_new_assignee(): void
    {
        $task = $this->create();
        app(TaskService::class)->mutate(9, 'modificar', (int) $task['id'], ['request_id' => (string) Str::uuid(), 'revision' => 1,
            'stock_id' => 2, 'usuario_pos_id' => 8, 'titulo' => 'TEST new task', 'descripcion' => 'TEST private']);
        $old = $this->callPos('listar', ['version' => 1, 'after_id' => '1']);
        $this->assertFalse($old['cambios'][0]['tarea']['activo']);
        $this->assertArrayNotHasKey('usuario_pos_id', $old['cambios'][0]['tarea']);
        $new = $this->callPos('listar', ['version' => 1, 'after_id' => '0'], 2);
        $this->assertSame('8', $new['cambios'][0]['tarea']['usuario_pos_id']);
        $this->assertSame('NO_ASIGNADA', $this->callPos('estado', $this->updatePayload($task))['code']);
    }

    public function test_cancelled_tasks_are_retired_and_cannot_be_completed(): void
    {
        $task = $this->create();
        app(TaskService::class)->mutate(9, 'cancelar', (int) $task['id'], ['request_id' => (string) Str::uuid(), 'revision' => 1]);
        $this->assertSame('NO_ASIGNADA', $this->callPos('estado', $this->updatePayload($task))['code']);
        $this->assertFalse($this->callPos('listar', ['version' => 1, 'after_id' => '1'])['cambios'][0]['tarea']['activo']);
    }

    public function test_portal_mutation_retry_does_not_create_a_duplicate_and_revision_prevents_lost_edits(): void
    {
        $data = ['request_id' => (string) Str::uuid(), 'stock_id' => 1, 'usuario_pos_id' => 7, 'titulo' => 'TEST', 'descripcion' => 'TEST'];
        $service = app(TaskService::class);
        $task = $service->mutate(9, 'crear', null, $data);
        $this->assertSame($task, $service->mutate(9, 'crear', null, $data));
        $this->assertSame(1, DB::table('farma_tareas')->count());
        $data['request_id'] = (string) Str::uuid();
        $data['revision'] = 1;
        $service->mutate(9, 'modificar', (int) $task['id'], $data);
        $data['request_id'] = (string) Str::uuid();
        $this->expectException(HttpException::class);
        $service->mutate(9, 'modificar', (int) $task['id'], $data);
    }

    public function test_a_device_cannot_claim_another_registered_stock(): void
    {
        $payload = $this->updatePayload($this->create());
        $payload['stock_id'] = '2';
        try {
            $this->callPos('estado', $payload);
            $this->fail('Expected stock rejection');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_portal_permissions_require_the_matching_action_active_parents_and_unexpired_grant(): void
    {
        $this->portalCatalog();
        Route::post('/test/tasks/create', [TaskController::class, 'create'])->middleware(EnsureTaskPermission::class.':tarbtn02');
        Route::get('/test/tasks/list', [TaskController::class, 'index'])->middleware(EnsureTaskPermission::class.':tarbtn01');
        $user = new User;
        $user->id = 9;
        $this->actingAs($user);
        $data = ['request_id' => (string) Str::uuid(), 'stock_id' => 1, 'usuario_pos_id' => 7, 'titulo' => 'TEST', 'descripcion' => 'TEST'];
        $this->postJson('/test/tasks/create', $data, ['Action-Btn' => 'tarbtn02'])->assertForbidden();
        DB::table('permisos')->insert(['usuario' => 9, 'modulo' => 1, 'vista' => 1, 'actionvista' => 2]);
        $this->postJson('/test/tasks/create', $data, ['Action-Btn' => 'tarbtn03'])->assertForbidden();
        $this->postJson('/test/tasks/create', $data, ['Action-Btn' => 'tarbtn02'])->assertCreated();
        $listed = $this->getJson('/test/tasks/list', ['Action-Btn' => 'tarbtn01'])->assertOk();
        $this->assertArrayNotHasKey('descripcion', $listed->json('data.0'));
        $this->getJson('/test/tasks/list?stock_id=1', ['Action-Btn' => 'tarbtn01'])->assertForbidden();
        DB::table('permisos')->where('actionvista', 2)->update(['lifetime' => now()->subMinute()]);
        $data['request_id'] = (string) Str::uuid();
        $this->postJson('/test/tasks/create', $data, ['Action-Btn' => 'tarbtn02'])->assertForbidden();
        DB::table('modulos')->update(['estado' => 2]);
        $this->getJson('/test/tasks/list', ['Action-Btn' => 'tarbtn01'])->assertForbidden();
    }

    public function test_task_catalog_creates_all_actions_without_assigning_permissions_to_users(): void
    {
        $this->portalCatalog();
        $count = DB::table('permisosusuariospos')->count();
        $portalCount = DB::table('permisos')->count();
        app(ChangeRecorder::class)->withoutRecording(fn () => (new TaskPermissionCatalogSeeder)->run());
        $this->assertSame(6, DB::table('actionsvistas')->where('vista', 1)->count());
        $this->assertSame(2, DB::table('botonespos')->where('vista_id', 1)->count());
        $this->assertSame($count, DB::table('permisosusuariospos')->count());
        $this->assertSame($portalCount, DB::table('permisos')->count());
    }

    private function portalCatalog(): void
    {
        foreach (['modulo_estados', 'vista_estados'] as $table) {
            Schema::create($table, fn (Blueprint $t) => [$t->id(), $t->string('descripcion')]);
            DB::table($table)->insert([['id' => 1, 'descripcion' => 'ACTIVO'], ['id' => 2, 'descripcion' => 'INACTIVO']]);
        }
        Schema::create('modulos', fn (Blueprint $t) => [$t->id(), $t->string('codigo'), $t->integer('estado')]);
        Schema::create('vistas', fn (Blueprint $t) => [$t->id(), $t->string('codigo'), $t->string('nombre'), $t->integer('modulo'), $t->integer('estado'), $t->timestamps()]);
        Schema::create('actionsvistas', fn (Blueprint $t) => [$t->id(), $t->string('codigo'), $t->string('nombre'), $t->integer('vista'), $t->timestamps()]);
        Schema::create('permisos', fn (Blueprint $t) => [$t->id(), $t->integer('usuario'), $t->integer('modulo'), $t->integer('vista'), $t->integer('actionvista'), $t->timestamp('lifetime')->nullable()]);
        DB::table('modulos')->insert(['id' => 1, 'codigo' => 'frm', 'estado' => 1]);
        DB::table('vistas')->insert(['id' => 1, 'codigo' => 'tar', 'nombre' => 'Tareas', 'modulo' => 1, 'estado' => 1]);
        foreach ([1 => 'tarbtn01', 2 => 'tarbtn02', 3 => 'tarbtn03'] as $id => $code) {
            DB::table('actionsvistas')->insert(['id' => $id, 'codigo' => $code, 'nombre' => 'TEST', 'vista' => 1]);
        }
        DB::table('permisos')->insert(['usuario' => 9, 'modulo' => 1, 'vista' => 1, 'actionvista' => 1]);
    }

    private function scheduled(array $schedule): array
    {
        return app(TaskService::class)->mutate(9, 'crear', null, $schedule + ['request_id' => (string) Str::uuid(),
            'stock_id' => 1, 'usuario_pos_id' => 7, 'titulo' => 'TEST scheduled', 'descripcion' => 'TEST schedule']);
    }

    public function test_daily_occurrences_have_independent_states_and_retries_do_not_complete_tomorrow(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 09:00:00', 'America/Tegucigalpa'));
        $this->scheduled(['programacion' => 'DIARIA', 'fecha_inicio' => '2026-10-17', 'fecha_fin' => '2026-10-19']);
        $days = DB::table('farma_tarea_dias')->orderBy('fecha')->get();
        $this->assertCount(3, $days);
        $payload = $this->updatePayload(['id' => (string) $days[0]->id, 'revision' => 1]);
        $payload['estado'] = 'COMPLETADA';
        $result = $this->callPos('estado', $payload);
        $this->assertTrue($result['accepted']);
        $this->assertSame($result, $this->callPos('estado', $payload));
        $this->assertSame('PENDIENTE', DB::table('farma_tarea_dias')->where('fecha', '2026-10-18')->value('estado'));
        $future = $this->updatePayload(['id' => (string) $days[1]->id, 'revision' => 1]);
        $this->assertSame('NO_ASIGNADA', $this->callPos('estado', $future)['code']);
        $this->travelTo(Carbon::parse('2026-10-18 09:00:00', 'America/Tegucigalpa'));
        $future['request_id'] = (string) Str::uuid();
        $this->assertTrue($this->callPos('estado', $future)['accepted']);
        $this->assertSame('COMPLETADA', DB::table('farma_tarea_dias')->where('fecha', '2026-10-17')->value('estado'));
    }

    public function test_daily_materialization_rolls_forward_without_duplicates_and_respects_end_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 09:00:00', 'America/Tegucigalpa'));
        $this->scheduled(['programacion' => 'DIARIA', 'fecha_inicio' => '2026-10-17']);
        $this->assertSame(31, DB::table('farma_tarea_dias')->count());
        $this->callPos('listar', ['version' => 1, 'after_id' => '0']);
        $this->assertSame(31, DB::table('farma_tarea_cambios')->count());
        $this->travelTo(Carbon::parse('2026-10-18 09:00:00', 'America/Tegucigalpa'));
        $this->callPos('listar', ['version' => 1, 'after_id' => '31']);
        $this->assertSame(32, DB::table('farma_tarea_dias')->count());
        $this->assertSame('2026-11-17', DB::table('farma_tarea_dias')->max('fecha'));
    }

    public function test_calendar_dates_are_sorted_and_only_selected_dates_are_published(): void
    {
        $task = $this->scheduled(['programacion' => 'FECHAS', 'fechas' => ['2026-12-24', '2026-11-01']]);
        $this->assertSame(['2026-11-01', '2026-12-24'], $task['fechas']);
        $this->assertSame('2026-11-01', $task['fecha_inicio']);
        $this->assertSame(['2026-11-01', '2026-12-24'], DB::table('farma_tarea_dias')->orderBy('fecha')->pluck('fecha')->all());
        $page = $this->callPos('listar', ['version' => 1, 'after_id' => '0']);
        $this->assertSame('2026-11-01', $page['cambios'][0]['tarea']['fecha']);
    }

    public function test_removing_a_future_calendar_date_retires_it_and_reassignment_keeps_private_tombstones(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 09:00:00', 'America/Tegucigalpa'));
        $task = $this->scheduled(['programacion' => 'FECHAS', 'fechas' => ['2026-10-18', '2026-10-19']]);
        app(TaskService::class)->mutate(9, 'modificar', (int) $task['id'], ['request_id' => (string) Str::uuid(), 'revision' => 1,
            'stock_id' => 2, 'usuario_pos_id' => 8, 'titulo' => 'TEST private', 'descripcion' => 'TEST private',
            'programacion' => 'FECHAS', 'fechas' => ['2026-10-19']]);
        $this->assertFalse((bool) DB::table('farma_tarea_dias')->where('fecha', '2026-10-18')->value('activo'));
        $old = $this->callPos('listar', ['version' => 1, 'after_id' => '2']);
        $this->assertCount(2, $old['cambios']);
        $this->assertFalse($old['cambios'][1]['tarea']['activo']);
        $this->assertArrayNotHasKey('usuario_pos_id', $old['cambios'][1]['tarea']);
    }

    public function test_schedule_validation_rejects_empty_dates_impossible_dates_and_reversed_ranges(): void
    {
        foreach ([['programacion' => 'FECHAS', 'fechas' => []], ['programacion' => 'UNICA', 'fecha_inicio' => '2026-02-30'],
            ['programacion' => 'DIARIA', 'fecha_inicio' => '2026-10-18', 'fecha_fin' => '2026-10-17']] as $schedule) {
            try {
                $this->scheduled($schedule);
                $this->fail('Expected invalid schedule');
            } catch (ValidationException) {
                $this->assertSame(0, DB::table('farma_tareas')->count());
            }
        }
    }

    public function test_a_retired_future_date_can_become_a_daily_occurrence_when_its_window_arrives(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 09:00:00', 'America/Tegucigalpa'));
        $task = $this->scheduled(['programacion' => 'FECHAS', 'fechas' => ['2026-12-24']]);
        app(TaskService::class)->mutate(9, 'modificar', (int) $task['id'], ['request_id' => (string) Str::uuid(), 'revision' => 1,
            'stock_id' => 1, 'usuario_pos_id' => 7, 'titulo' => 'TEST daily', 'descripcion' => 'TEST',
            'programacion' => 'DIARIA', 'fecha_inicio' => '2026-12-24', 'fecha_fin' => '2026-12-24']);
        $this->assertFalse((bool) DB::table('farma_tarea_dias')->value('activo'));
        $this->travelTo(Carbon::parse('2026-12-24 09:00:00', 'America/Tegucigalpa'));
        $this->callPos('listar', ['version' => 1, 'after_id' => '0']);
        $this->assertSame(1, DB::table('farma_tarea_dias')->count());
        $this->assertTrue((bool) DB::table('farma_tarea_dias')->value('activo'));
        $this->assertSame(3, (int) DB::table('farma_tarea_dias')->value('revision'));
    }

    public function test_completed_occurrences_cannot_be_reopened_but_original_confirmation_can_be_retried(): void
    {
        $task = $this->create();
        $payload = $this->updatePayload($task);
        $payload['estado'] = 'COMPLETADA';
        $accepted = $this->callPos('estado', $payload);
        $this->assertTrue($accepted['accepted']);
        $this->assertSame($accepted, $this->callPos('estado', $payload));
        foreach (['PENDIENTE', 'EN_PROCESO', 'COMPLETADA'] as $state) {
            $retry = $payload;
            $retry['request_id'] = (string) Str::uuid();
            $retry['revision'] = 2;
            $retry['estado'] = $state;
            $this->assertSame('REVISION_CONFLICTO', $this->callPos('estado', $retry)['code']);
        }
        $this->assertSame('COMPLETADA', DB::table('farma_tarea_dias')->value('estado'));
        $this->assertSame(2, (int) DB::table('farma_tarea_dias')->value('revision'));
    }

    public function test_each_calendar_occurrence_identifies_its_schedule_and_backfill_preserves_state_and_revision(): void
    {
        $task = $this->scheduled(['programacion' => 'FECHAS', 'fechas' => [TaskService::today(), now('America/Tegucigalpa')->addDay()->toDateString()]]);
        $page = $this->callPos('listar', ['version' => 1, 'after_id' => '0']);
        foreach ($page['cambios'] as $change) {
            $this->assertSame($task['id'], $change['tarea']['agenda_id']);
            $this->assertSame('FECHAS', $change['tarea']['programacion']);
        }
        $payload = $this->updatePayload(['id' => $page['cambios'][0]['tarea']['id'], 'revision' => 1]);
        $payload['estado'] = 'COMPLETADA';
        $this->callPos('estado', $payload);
        $before = DB::table('farma_tarea_dias')->orderBy('id')->get()->toJson();
        (require database_path('migrations/2026_10_17_000004_publish_task_calendar_metadata.php'))->up();
        $this->assertSame($before, DB::table('farma_tarea_dias')->orderBy('id')->get()->toJson());
        $new = $this->callPos('listar', ['version' => 1, 'after_id' => '3']);
        $this->assertCount(2, $new['cambios']);
        $this->assertSame('COMPLETADA', $new['cambios'][0]['tarea']['estado']);
        $this->assertSame(2, $new['cambios'][0]['tarea']['revision']);
    }
}
