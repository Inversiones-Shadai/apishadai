<?php

namespace Tests\Feature\Farma;

use App\DTO\Farma\ProdUnidadData;
use App\Http\Middleware\EnsureProductParentPermission;
use App\Http\Requests\Farma\Productos\ProdUnidadRequest;
use App\Http\Resources\Farma\ProductActiveResource;
use App\Http\Resources\Farma\ProductResource;
use App\Interfaces\Farma\FamAdministracionService;
use App\Interfaces\Farma\FamPresentacionService;
use App\Interfaces\Farma\ProdCategoriaService;
use App\Interfaces\Farma\ProdEstadoService;
use App\Interfaces\Farma\ProductService;
use App\Interfaces\RepositoryInterface;
use App\Jobs\PushHeadquartersOutboxJob;
use App\Models\Farma\Concentracion;
use App\Models\Farma\FamAdministracion;
use App\Models\Farma\Familia;
use App\Models\Farma\FamPresentacion;
use App\Models\Farma\PrincipalActivo;
use App\Models\Farma\ProdActivo;
use App\Models\Farma\ProdCategoria;
use App\Models\Farma\ProdEstado;
use App\Models\Farma\Producto;
use App\Models\Farma\ProdUnidad;
use App\Models\Laboratorio;
use App\Models\SyncChange;
use App\Repositories\Farma\ProductActiveRepository;
use App\Repositories\Farma\ProductRepository;
use App\Repositories\Repository;
use App\Services\Headquarters\EntityContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductDomainTest extends TestCase
{
    public function test_product_active_picker_pages_and_searches_names_and_concentrations_in_the_database(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        $concentration = Concentracion::create(['valor' => '875/125 mg']);
        foreach (range(1, 24) as $number) {
            PrincipalActivo::create(['nombre' => 'Principio '.$number, 'concentracion' => $concentration->id]);
        }
        $path = $this->productResourceBase('ProductController').'/principios_activos';
        $first = $this->getJson($path.'?per_page=10')->assertOk()->assertJsonCount(10, 'data');
        $second = $this->getJson($path.'?per_page=10&page=2')->assertOk()->assertJsonCount(10, 'data');
        $this->assertEmpty(array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
        $this->getJson($path.'?per_page=10&search=875%2F125')->assertOk()
            ->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 24);
        $this->getJson($path.'?per_page=10&search=PRINCIPIO%2024')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Principio 24');
        $this->getJson($path.'?per_page=51')->assertUnprocessable();
        $this->getJson($path.'?search='.str_repeat('a', 256))->assertUnprocessable();
    }

    public function test_product_deletion_removes_its_principle_links_and_publishes_one_hq_tombstone(): void
    {
        $this->withoutMiddleware();
        [$parent, $association] = $this->seedProductDomain();
        $product = $parent->replicate();
        $product->fill(['codigo' => 'DELETE-ONLY', 'codigobar' => null])->save();
        $product->principiosActivos()->attach($association->pactivo);
        (require database_path('migrations/2026_10_01_000024_create_sync_changes_table.php'))->up();
        (require database_path('migrations/2026_10_01_000030_create_headquarters_outbox.php'))->up();
        Schema::create('devices', fn (Blueprint $table) => $table->integer('id', true));
        (require database_path('migrations/2026_10_01_000032_add_headquarters_device_links_and_stock_scope.php'))->up();
        config(['headquarters.publishing_enabled' => true, 'headquarters.send_immediately' => false]);

        $path = $this->productResourceBase('ProductController').'/eliminar/'.$product->id;
        $this->deleteJson($path)->assertOk()->assertJsonPath('data', true);
        $this->assertDatabaseMissing('productos', ['id' => $product->id]);
        $this->assertDatabaseMissing('prod_activo', ['producto' => $product->id]);
        $this->assertDatabaseHas('principal_activos', ['id' => $association->pactivo]);
        $this->assertDatabaseHas('prod_activo', ['producto' => $parent->id, 'pactivo' => $association->pactivo]);
        $change = SyncChange::sole();
        $this->assertSame('productos', $change->entity);
        $this->assertEquals($product->id, $change->entity_id);
        $this->assertSame('DELETE', $change->action);
        $this->assertDatabaseHas('hq_outbox', ['sync_change_id' => $change->id]);
        $this->deleteJson($path)->assertNotFound()->assertJsonPath('message', 'Producto no encontrado.');
        $this->assertDatabaseCount('sync_changes', 1);
    }

    #[DataProvider('productDeletionReferences')]
    public function test_referenced_product_returns_conflict_and_preserves_its_principles(string $reference): void
    {
        $this->withoutMiddleware();
        [$product, $association] = $this->seedProductDomain();
        if ($reference !== 'purchase') {
            DB::table('compra_detalle')->where('producto', $product->id)->delete();
        }
        if ($reference === 'child') {
            $child = $product->replicate();
            $child->fill(['codigo' => 'CHILD-DELETE', 'codigobar' => null, 'producto_padre_id' => $product->id])->save();
        }
        if ($reference === 'lot') {
            Schema::create('lotes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('producto')->constrained('productos')->restrictOnDelete();
            });
            DB::table('lotes')->insert(['producto' => $product->id]);
        }
        $this->deleteJson($this->productResourceBase('ProductController').'/eliminar/'.$product->id)
            ->assertConflict()->assertJsonPath('message', 'No se puede eliminar el producto porque tiene lotes, compras, productos hijos u otros registros relacionados.');
        $this->assertDatabaseHas('productos', ['id' => $product->id]);
        $this->assertDatabaseHas('prod_activo', ['producto' => $product->id, 'pactivo' => $association->pactivo]);
        $table = match ($reference) {
            'purchase' => 'compra_detalle', 'lot' => 'lotes', 'child' => 'productos'
        };
        $column = $reference === 'child' ? 'producto_padre_id' : 'producto';
        $this->assertDatabaseHas($table, [$column => $product->id]);
    }

    public static function productDeletionReferences(): array
    {
        return [['purchase'], ['lot'], ['child']];
    }

    public function test_hq_failure_restores_deleted_product_and_principle_links(): void
    {
        $this->withoutMiddleware();
        [$product, $association] = $this->seedProductDomain();
        DB::table('compra_detalle')->where('producto', $product->id)->delete();
        (require database_path('migrations/2026_10_01_000024_create_sync_changes_table.php'))->up();
        (require database_path('migrations/2026_10_01_000030_create_headquarters_outbox.php'))->up();
        Schema::create('devices', fn (Blueprint $table) => $table->integer('id', true));
        (require database_path('migrations/2026_10_01_000032_add_headquarters_device_links_and_stock_scope.php'))->up();
        DB::unprepared("CREATE TRIGGER reject_product_delete_event BEFORE INSERT ON sync_changes WHEN NEW.action = 'DELETE' BEGIN SELECT RAISE(ABORT, 'Test HQ failure'); END;");
        config(['headquarters.publishing_enabled' => true, 'headquarters.send_immediately' => false]);
        try {
            (new ProductRepository(new Producto))->delete($product->id);
            $this->fail('La eliminación debe fallar cuando no puede registrar su evento HQ.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Test HQ failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('productos', ['id' => $product->id]);
        $this->assertDatabaseHas('prod_activo', ['producto' => $product->id, 'pactivo' => $association->pactivo]);
        $this->assertDatabaseCount('sync_changes', 0);
        $this->assertDatabaseCount('hq_outbox', 0);
        $this->deleteJson($this->productResourceBase('ProductController').'/eliminar/'.$product->id)
            ->assertStatus(500)->assertJsonPath('message', 'No se pudo eliminar el producto.');
        $this->assertDatabaseHas('productos', ['id' => $product->id]);
        $this->assertDatabaseHas('prod_activo', ['producto' => $product->id, 'pactivo' => $association->pactivo]);
    }

    public function test_child_permission_migration_registers_its_own_action_and_preserves_assignments(): void
    {
        foreach (['modulos', 'vistas', 'actionsvistas'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->string('codigo')->unique();
                $table->string('nombre');
                if ($name === 'vistas') {
                    $table->integer('modulo');
                }
                if ($name === 'actionsvistas') {
                    $table->integer('vista');
                }
                $table->timestamps();
            });
        }
        Schema::create('permisos', function (Blueprint $table): void {
            $table->id();
            $table->integer('actionvista');
        });
        DB::table('modulos')->insert(['id' => 4, 'codigo' => 'frm', 'nombre' => 'farma']);
        DB::table('vistas')->insert(['id' => 7, 'codigo' => 'prd', 'nombre' => 'productos', 'modulo' => 4]);
        DB::table('actionsvistas')->insert(['codigo' => 'prdbtn02', 'nombre' => 'crear', 'vista' => 7]);
        DB::table('actionsvistas')->insert(['codigo' => 'prdbtn13', 'nombre' => 'listar categorias', 'vista' => 7]);
        $migration = require database_path('migrations/2026_10_27_000003_register_product_child_permission.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseCount('actionsvistas', 3);
        $this->assertDatabaseCount('permisos', 0);
        $this->assertDatabaseHas('actionsvistas', ['codigo' => 'prdbtn19', 'nombre' => 'Convertir producto padre a hijo', 'vista' => 7]);
        $migration->down();
        $this->assertDatabaseMissing('actionsvistas', ['codigo' => 'prdbtn19']);
        $this->assertDatabaseHas('actionsvistas', ['codigo' => 'prdbtn02']);
        $this->assertDatabaseHas('actionsvistas', ['codigo' => 'prdbtn13', 'nombre' => 'listar categorias']);
        $migration->up();
        $action = DB::table('actionsvistas')->where('codigo', 'prdbtn19')->value('id');
        DB::table('permisos')->insert(['actionvista' => $action]);
        $migration->down();
        $this->assertDatabaseHas('actionsvistas', ['id' => $action]);
        $this->assertDatabaseHas('permisos', ['actionvista' => $action]);
        DB::table('actionsvistas')->where('id', $action)->update(['nombre' => 'Otra acción']);
        try {
            $migration->up();
            $this->fail('La migración debe rechazar un código ocupado por otra acción.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('otra acción', $exception->getMessage());
        }
        $migration->down();
        $this->assertDatabaseHas('actionsvistas', ['id' => $action, 'nombre' => 'Otra acción']);
        DB::table('actionsvistas')->where('id', $action)->update(['vista' => 99]);
        $this->expectException(\LogicException::class);
        $migration->up();
    }

    public function test_child_creation_copies_the_catalog_and_publishes_complete_headquarters_dependencies(): void
    {
        $this->withoutMiddleware();
        [$parent, $active] = $this->seedProductDomain();
        $originalParent = $parent->fresh()->getRawOriginal();
        (require database_path('migrations/2026_10_24_000001_allow_shared_product_barcodes.php'))->up();
        (require database_path('migrations/2026_10_01_000024_create_sync_changes_table.php'))->up();
        (require database_path('migrations/2026_10_01_000030_create_headquarters_outbox.php'))->up();
        Schema::create('devices', fn (Blueprint $table) => $table->integer('id', true));
        (require database_path('migrations/2026_10_01_000032_add_headquarters_device_links_and_stock_scope.php'))->up();
        config(['headquarters.publishing_enabled' => true, 'headquarters.send_immediately' => false]);

        $base = $this->productResourceBase('ProductController');
        $this->postJson($base.'/crear-hijo/'.$parent->id, [
            'descripcion' => ' <b>Tableta individual</b> ',
            'codigo' => 'IGNORAR', 'unidad' => $parent->unidad, 'producto_padre_id' => 999,
        ])->assertCreated()->assertJsonPath('data.codigo', 'PRD-001H')
            ->assertJsonPath('data.producto_padre_id', (string) $parent->id);

        $child = Producto::where('codigo', 'PRD-001H')->firstOrFail();
        $this->assertSame('Tableta individual', $child->descripcion);
        $this->assertSame($parent->only(['categoria', 'laboratorio', 'familia', 'codigobar', 'estado']),
            $child->only(['categoria', 'laboratorio', 'familia', 'codigobar', 'estado']));
        $this->assertSame($parent->getRawOriginal('imagen'), $child->getRawOriginal('imagen'));
        $this->assertSame([$active->pactivo], $child->principiosActivos()->pluck('principal_activos.id')->all());
        $this->assertSame(['abreviatura_c' => 'UND', 'abreviatura_v' => 'UND', 'cantidad_c' => 1, 'cantidad_v' => 1],
            $child->unidadDetalle->only(['abreviatura_c', 'abreviatura_v', 'cantidad_c', 'cantidad_v']));
        $this->assertSame($originalParent, $parent->fresh()->getRawOriginal());

        $changes = SyncChange::where('entity', 'productos')->where('entity_id', $child->id)->get();
        $this->assertCount(1, $changes);
        $this->assertSame('CREATE', $changes[0]->action);
        $this->assertSame([$active->pactivo], $changes[0]->payload['principios_activos']);
        $this->assertSame((string) $parent->id, (string) $changes[0]->payload['producto_padre_id']);
        $this->assertSame('imagen-del-producto', base64_decode($changes[0]->payload['imagen_base64'], true));
        $available = [];
        foreach (SyncChange::orderBy('id')->get() as $change) {
            foreach (EntityContract::references($change->entity, $change->payload) as $reference) {
                $this->assertArrayHasKey($reference['entity'].':'.$reference['entity_id'], $available);
            }
            $available[$change->entity.':'.$change->entity_id] = true;
            $this->assertDatabaseHas('hq_outbox', ['sync_change_id' => $change->id]);
        }
        $this->postJson($base.'/crear-hijo/'.$parent->id, ['descripcion' => 'Duplicado'])
            ->assertUnprocessable()->assertJsonValidationErrors('codigo');
        $this->assertSame(1, SyncChange::where('entity', 'productos')->where('entity_id', $child->id)->count());
    }

    public function test_child_creation_reuses_unit_and_rejects_invalid_parents_descriptions_and_codes(): void
    {
        $this->withoutMiddleware();
        [$parent] = $this->seedProductDomain();
        $parent->codigobar = null;
        $parent->save();
        $unit = ProdUnidad::create(['abreviatura_c' => 'UND', 'abreviatura_v' => 'UND', 'cantidad_c' => 1, 'cantidad_v' => 1]);
        $base = $this->productResourceBase('ProductController').'/crear-hijo/';
        foreach ([[], ['descripcion' => ' <b></b> '], ['descripcion' => str_repeat('a', 151)], ['descripcion' => []]] as $input) {
            $this->postJson($base.$parent->id, $input)->assertUnprocessable()->assertJsonValidationErrors('descripcion', 'data.errors');
        }
        $this->postJson($base.'999999', ['descripcion' => 'Hijo'])->assertNotFound();
        foreach (['PADREH', 'PADREh'] as $code) {
            $parent->update(['codigo' => $code]);
            $this->postJson($base.$parent->id, ['descripcion' => 'Hijo'])->assertUnprocessable()
                ->assertJsonValidationErrors('producto_padre_id');
        }
        $parent->update(['codigo' => str_repeat('a', 20)]);
        $this->postJson($base.$parent->id, ['descripcion' => 'Hijo'])->assertUnprocessable()->assertJsonValidationErrors('codigo');
        $parent->update(['codigo' => 'PRD-001']);
        $this->postJson($base.$parent->id, ['descripcion' => 'Hijo'])->assertCreated();
        $child = Producto::where('codigo', 'PRD-001H')->firstOrFail();
        $this->assertSame($unit->id, $child->unidad);
        $this->assertNull($child->codigobar);
        $child->update(['codigo' => 'SIN-SUFIJO']);
        $this->postJson($base.$child->id, ['descripcion' => 'Nieto'])->assertUnprocessable()
            ->assertJsonValidationErrors('producto_padre_id');
        $child->update(['codigo' => 'PRD-001h']);
        $this->postJson($base.$parent->id, ['descripcion' => 'Hijo'])->assertUnprocessable()->assertJsonValidationErrors('codigo');
        $this->assertDatabaseCount('productos', 2);
        $this->assertDatabaseCount('prod_unidades', 2);
    }

    public function test_child_creation_rolls_back_product_unit_and_principles_if_hq_recording_fails(): void
    {
        $this->withoutMiddleware();
        [$parent] = $this->seedProductDomain();
        $parent->update(['codigobar' => null]);
        (require database_path('migrations/2026_10_01_000024_create_sync_changes_table.php'))->up();
        (require database_path('migrations/2026_10_01_000030_create_headquarters_outbox.php'))->up();
        Schema::create('devices', fn (Blueprint $table) => $table->integer('id', true));
        (require database_path('migrations/2026_10_01_000032_add_headquarters_device_links_and_stock_scope.php'))->up();
        DB::unprepared("CREATE TRIGGER reject_child_event BEFORE INSERT ON sync_changes WHEN NEW.entity = 'productos' AND NEW.entity_id <> ".$parent->id." BEGIN SELECT RAISE(ABORT, 'Test HQ failure'); END;");
        config(['headquarters.publishing_enabled' => true, 'headquarters.send_immediately' => false]);

        $this->postJson($this->productResourceBase('ProductController').'/crear-hijo/'.$parent->id, ['descripcion' => 'Hijo'])
            ->assertStatus(409);
        $this->assertDatabaseCount('productos', 1);
        $this->assertDatabaseCount('prod_unidades', 1);
        $this->assertDatabaseCount('prod_activo', 1);
        $this->assertDatabaseCount('sync_changes', 0);
        $this->assertDatabaseCount('hq_outbox', 0);
    }

    public function test_shared_barcodes_are_accepted_on_create_and_update_but_product_codes_remain_unique(): void
    {
        $this->withoutMiddleware();
        [$product] = $this->seedProductDomain();
        $migration = require database_path('migrations/2026_10_24_000001_allow_shared_product_barcodes.php');
        $migration->up();
        $migration->up();
        $input = $product->only(['categoria', 'laboratorio', 'unidad', 'familia', 'codigo', 'codigobar', 'descripcion', 'estado']);
        $base = $this->productResourceBase('ProductController');
        $this->postJson($base.'/crear', [...$input, 'codigo' => 'BARCODE-SHARED'])->assertCreated();
        $other = Producto::where('codigo', 'BARCODE-SHARED')->firstOrFail();
        $this->putJson($this->updatePath($base, $other->id), [...$input, 'codigo' => $other->codigo])->assertOk();
        $this->assertSame(2, Producto::where('codigobar', $product->codigobar)->count());
        $this->postJson($base.'/crear', $input)->assertUnprocessable();
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_product_family_is_required_only_for_the_designated_categories(): void
    {
        $this->withoutMiddleware();
        [$product] = $this->seedProductDomain();
        (require database_path('migrations/2026_11_11_000001_allow_products_without_family.php'))->up();
        $base = $this->productResourceBase('ProductController');
        $input = [...$product->only(['categoria', 'laboratorio', 'unidad', 'descripcion', 'estado']), 'codigo' => 'SIN-FAMILIA'];

        foreach (['Farmacia', 'CONGELADOS', 'controlados', 'Leches'] as $name) {
            $category = ProdCategoria::query()->create(['nombre' => $name]);
            $this->postJson($base.'/crear', [...$input, 'categoria' => ['id' => $category->id]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['familia.id' => 'Selecciona una familia para esta categoría.'], 'data.errors');
        }

        $this->postJson($base.'/crear', [...$input, 'familia' => ['id' => 999]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['familia.id'], 'data.errors');
        $this->postJson($base.'/crear', $input)->assertCreated();
        $created = Producto::query()->where('codigo', 'SIN-FAMILIA')->firstOrFail();
        $this->assertNull($created->familia);
        $this->getJson($base.'/buscar/'.$created->id)->assertOk()->assertJsonPath('data.familia_id', null);
        $this->putJson($this->updatePath($base, $created->id), $input)->assertOk();
        $update = $this->updatePath($base, $product->id);
        $updateInput = [...$input, 'codigo' => $product->codigo];
        $this->putJson($update, $updateInput)->assertOk();
        $this->assertSame($product->familia, $product->fresh()->familia);
        $this->putJson($update, [...$updateInput, 'familia' => null])->assertOk();
        $this->assertNull($product->fresh()->familia);

        $this->postJson($base.'/crear', [
            ...$input, 'codigo' => 'CON-FAMILIA', 'categoria' => $category->id, 'familia' => ['id' => $product->familia],
        ])->assertCreated();
        $this->assertDatabaseHas('productos', ['codigo' => 'CON-FAMILIA', 'familia' => $product->familia]);
    }

    public function test_headquarters_product_changes_include_the_final_principles_and_image(): void
    {
        [$product, $active] = $this->seedProductDomain();
        (require database_path('migrations/2026_10_01_000024_create_sync_changes_table.php'))->up();
        (require database_path('migrations/2026_10_01_000030_create_headquarters_outbox.php'))->up();
        Schema::create('devices', fn (Blueprint $table) => $table->integer('id', true));
        (require database_path('migrations/2026_10_01_000032_add_headquarters_device_links_and_stock_scope.php'))->up();
        config(['headquarters.publishing_enabled' => true, 'headquarters.send_immediately' => false]);
        $repository = new ProductRepository(new Producto);
        $this->assertTrue($repository->update($product->id, ['descripcion' => 'Nueva descripción'], [$active->pactivo]));
        $change = SyncChange::where('entity', 'productos')->firstOrFail();
        $this->assertSame([$active->pactivo], $change->payload['principios_activos']);
        $this->assertSame('Nueva descripción', $change->payload['descripcion']);
        $this->assertSame('imagen-del-producto', base64_decode($change->payload['imagen_base64'], true));
        $this->assertArrayHasKey('producto_padre_id', $change->payload);
        $this->assertNull($change->payload['producto_padre_id']);
        $this->assertSame(1, SyncChange::where('entity', 'productos')->count());
        $available = [];
        foreach (SyncChange::orderBy('id')->get() as $record) {
            foreach (EntityContract::references($record->entity, $record->payload) as $reference) {
                $this->assertArrayHasKey($reference['entity'].':'.$reference['entity_id'], $available);
            }
            $available[$record->entity.':'.$record->entity_id] = true;
        }
        $this->assertTrue($repository->removeActivePrinciple($product->id, $active->pactivo));
        $this->assertSame([], SyncChange::orderByDesc('id')->firstOrFail()->payload['principios_activos']);
        $this->assertTrue($repository->addActivePrinciple($product->id, $active->pactivo));
        $this->assertSame([$active->pactivo], SyncChange::orderByDesc('id')->firstOrFail()->payload['principios_activos']);
        $this->assertSame([1, 2, 3], SyncChange::where('entity', 'productos')->orderBy('id')->pluck('version')->all());
    }

    public function test_product_creation_returns_success_without_waiting_for_headquarters(): void
    {
        $this->withoutMiddleware();
        [$product, $active] = $this->seedProductDomain();
        (require database_path('migrations/2026_10_01_000024_create_sync_changes_table.php'))->up();
        (require database_path('migrations/2026_10_01_000030_create_headquarters_outbox.php'))->up();
        Schema::create('devices', fn (Blueprint $table) => $table->integer('id', true));
        (require database_path('migrations/2026_10_01_000032_add_headquarters_device_links_and_stock_scope.php'))->up();
        config(['headquarters.publishing_enabled' => true, 'headquarters.send_immediately' => true, 'queue.default' => 'sync']);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(fn () => throw new RuntimeException('The product request must never contact HQ'));

        $payload = $product->only(['categoria', 'laboratorio', 'unidad', 'familia', 'descripcion', 'estado']);
        $this->postJson($this->productResourceBase('ProductController').'/crear', [
            ...$payload, 'codigo' => 'ASYNC-HQ', 'principios_activos' => [$active->pactivo],
        ])->assertCreated()->assertJsonPath('data', true);

        $created = Producto::where('codigo', 'ASYNC-HQ')->sole();
        $change = SyncChange::where('entity', 'productos')->where('entity_id', $created->id)->sole();
        $this->assertSame([$active->pactivo], $change->payload['principios_activos']);
        $this->assertDatabaseHas('hq_outbox', ['sync_change_id' => $change->id, 'sent_at' => null, 'attempts' => 0]);
        Queue::assertPushed(PushHeadquartersOutboxJob::class);
        Http::assertNothingSent();
    }

    public function test_direct_product_principle_changes_publish_both_affected_products(): void
    {
        [$product, $active] = $this->seedProductDomain();
        $other = $product->replicate();
        $other->codigo = 'OTHER';
        $other->codigobar = null;
        $other->save();
        (require database_path('migrations/2026_10_01_000024_create_sync_changes_table.php'))->up();
        (require database_path('migrations/2026_10_01_000030_create_headquarters_outbox.php'))->up();
        Schema::create('devices', fn (Blueprint $table) => $table->integer('id', true));
        (require database_path('migrations/2026_10_01_000032_add_headquarters_device_links_and_stock_scope.php'))->up();
        config(['headquarters.publishing_enabled' => true, 'headquarters.send_immediately' => false]);
        $active->updateQuietly(['producto' => $other->id]);
        $changes = SyncChange::where('entity', 'productos')->orderBy('id')->get();
        $this->assertSame([$product->id, $other->id], $changes->pluck('entity_id')->all());
        $this->assertSame([], $changes[0]->payload['principios_activos']);
        $this->assertSame([$active->pactivo], $changes[1]->payload['principios_activos']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
        ]);
        DB::purge('sqlite');

        $this->createSchema();
        (require database_path('migrations/2026_10_11_000006_product_parent.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_models_resolve_relationships_without_colliding_with_foreign_keys(): void
    {
        [$product, $active] = $this->seedProductDomain();

        $product = Producto::query()
            ->with([
                'categoriaDetalle',
                'laboratorioDetalle',
                'unidadDetalle',
                'Familia.Presentacion',
                'Familia.Administracion',
                'estadoDetalle',
                'principiosActivos.concentracionDetalle',
            ])
            ->findOrFail($product->id);

        $this->assertIsInt($product->categoria);
        $this->assertInstanceOf(ProdCategoria::class, $product->categoriaDetalle);
        $this->assertInstanceOf(Laboratorio::class, $product->laboratorioDetalle);
        $this->assertInstanceOf(ProdUnidad::class, $product->unidadDetalle);
        $this->assertInstanceOf(Familia::class, $product->Familia);
        $this->assertInstanceOf(FamPresentacion::class, $product->Familia->Presentacion);
        $this->assertInstanceOf(FamAdministracion::class, $product->Familia->Administracion);
        $this->assertInstanceOf(ProdEstado::class, $product->estadoDetalle);
        $this->assertInstanceOf(PrincipalActivo::class, $product->principiosActivos->first());
        $this->assertInstanceOf(Concentracion::class, $product->principiosActivos->first()->concentracionDetalle);
        $this->assertInstanceOf(Producto::class, $active->fresh()->productoDetalle);
    }

    public function test_product_repository_excludes_blobs_and_returns_safe_resource_data(): void
    {
        [$seededProduct] = $this->seedProductDomain();
        $repository = new ProductRepository(new Producto);

        $product = $repository->findById($seededProduct->id);

        $this->assertNotNull($product);
        $this->assertTrue((bool) $product->tiene_imagen);
        $this->assertFalse(array_key_exists('imagen', $product->getAttributes()));
        $this->assertFalse(array_key_exists('imagen', $product->laboratorioDetalle->getAttributes()));

        $payload = json_decode(
            json_encode(ProductResource::make($product)->resolve(request()), JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame($product->categoria, $payload['categoria_id']);
        $this->assertSame(['id' => $product->categoria, 'nombre' => 'Categoría prueba', 'requires_family' => false], $payload['categoria']);
        $this->assertSame($product->laboratorio, $payload['laboratorio']['id']);
        $this->assertSame('Tegucigalpa', $payload['laboratorio']['direccion']);
        $this->assertArrayHasKey('nombre', $payload['laboratorio']);
        $this->assertSame($product->unidad, $payload['unidad']['id']);
        $this->assertSame(20, $payload['unidad']['cantidad_v']);
        $this->assertArrayHasKey('abreviatura_c', $payload['unidad']);
        $this->assertTrue($payload['tiene_imagen']);
        $this->assertSame([
            'id' => $product->familia,
            'descripcion' => $product->Familia->descripcion,
            'presentacion' => ['id' => $product->Familia->Presentacion->id, 'descripcion' => 'Tableta'],
            'administracion' => ['id' => $product->Familia->Administracion->id, 'descripcion' => 'Oral'],
        ], $payload['familia']);
        $this->assertSame(['id' => $product->estado, 'descripcion' => 'Activo'], $payload['estado']);
        $this->assertArrayNotHasKey('imagen', $payload);
        $this->assertArrayNotHasKey('imagen', $payload['laboratorio']);
        $this->assertArrayNotHasKey('pivot', $payload['principios_activos'][0]);
    }

    public function test_catalog_rules_reject_composite_duplicates_after_sanitizing_input(): void
    {
        ProdUnidad::query()->create([
            'abreviatura_c' => 'CAJ',
            'abreviatura_v' => 'UND',
            'cantidad_c' => 1,
            'cantidad_v' => 20,
        ]);

        $input = ProdUnidadData::sanitize([
            'abreviatura_c' => ' <b>CAJ</b> ',
            'abreviatura_v' => ' UND ',
            'cantidad_c' => '1',
            'cantidad_v' => '20',
        ]);
        $validator = Validator::make(
            $input,
            ProdUnidadRequest::create('/api/farma/productos/catalogos/unidades/crear', 'POST', $input)->rules(),
        );

        $this->assertSame('CAJ', $input['abreviatura_c']);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('cantidad_v', $validator->errors()->toArray());
    }

    public function test_active_repository_returns_compact_product_data(): void
    {
        [$product, $seededActive] = $this->seedProductDomain();
        $repository = new ProductActiveRepository(new ProdActivo);

        $active = $repository->findById($seededActive->id);
        $payload = json_decode(
            json_encode(ProductActiveResource::make($active)->resolve(request()), JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame($product->id, $payload['producto_id']);
        $this->assertSame(
            ['id', 'codigo', 'codigobar', 'descripcion'],
            array_keys($payload['producto']),
        );
        $this->assertSame('500 mg', $payload['principio_activo']['concentracion_detalle']['valor']);
    }

    #[DataProvider('catalogEndpoints')]
    public function test_each_catalog_exposes_its_own_crud(string $catalog, string $table, array $input): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        $base = $this->catalogBase($catalog);

        $this->postJson($base.'/crear', $input)
            ->assertCreated()
            ->assertJsonPath('data', true)
            ->assertJsonPath('message', 'Registro creado correctamente.');
        $id = DB::table($table)->where($input)->value('id');
        $this->assertIsInt($id);
        $this->assertDatabaseHas($table, ['id' => $id, ...$input]);

        $this->getJson($base.'/listar')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $id);
        $this->getJson($base.'/buscar/'.$id)
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        // Updating the same row must not fail its own unique constraint.
        $this->putJson($this->updatePath($base, $id), $input)
            ->assertOk()
            ->assertJsonPath('data', true);
        $this->assertCatalogValidationFails('POST', $base.'/crear', $input);
        $this->assertCatalogValidationFails('PUT', $this->updatePath($base, '999999'), ['id' => $id, ...$input], 'id');
        $this->putJson($this->updatePath($base, 'not-a-number'), $input)->assertNotFound();
        $this->putJson($this->updatePath($base, ''), ['id' => $id, ...$input])->assertNotFound();

        foreach ($input as $field => $value) {
            if (is_string($value)) {
                $input[$field] = 'Nuevo';
                break;
            }
        }
        $original = (array) DB::table($table)->where('id', 1)->first();
        $this->putJson($this->updatePath($base, $id), ['id' => 1, ...$input])
            ->assertOk()->assertJsonPath('data', true);
        $this->assertDatabaseHas($table, ['id' => $id, ...$input]);
        $this->assertSame($original, (array) DB::table($table)->where('id', 1)->first());

        $this->deleteJson($base.'/eliminar/'.$id)
            ->assertOk()
            ->assertJsonPath('data', true);
        $this->assertDatabaseMissing($table, ['id' => $id]);
        $this->getJson($base.'/buscar/'.$id)->assertNotFound();
        $this->deleteJson($base.'/eliminar/'.$id)->assertNotFound()->assertJsonPath('data', false);
    }

    public static function catalogEndpoints(): array
    {
        return [
            'unidades' => ['unidades', 'prod_unidades', [
                'abreviatura_c' => 'PAQ', 'abreviatura_v' => 'UND', 'cantidad_c' => 1, 'cantidad_v' => 10,
            ]],
            'estados' => ['estados', 'prod_estado', ['descripcion' => 'Inactivo']],
            'categorias' => ['categorias', 'prod_categorias', ['nombre' => 'Vitaminas']],
            'presentaciones' => ['presentaciones', 'fam_presentacion', ['descripcion' => 'Cápsula']],
            'administraciones' => ['administraciones', 'fam_administracion', ['descripcion' => 'Tópica']],
            'familias' => ['familias', 'familia', [
                'presentacion' => 1, 'administracion' => 1, 'descripcion' => 'Antibiótico',
            ]],
            'concentraciones' => ['concentraciones', 'concentraciones', ['valor' => '250 mg']],
            'principios-activos' => ['principios-activos', 'principal_activos', [
                'nombre' => 'Ibuprofeno', 'concentracion' => 1,
            ]],
        ];
    }

    #[DataProvider('familyCombinations')]
    public function test_family_uniqueness_checks_the_entire_combination(
        string $method,
        bool $differentDescription,
        bool $differentAdministration,
        bool $differentPresentation,
        bool $nestedRelations,
    ): void {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        $administration = FamAdministracion::query()->create(['descripcion' => 'Tópica']);
        $presentation = FamPresentacion::query()->create(['descripcion' => 'Crema']);
        $base = $this->catalogBase('familias');
        $input = [
            'descripcion' => $differentDescription ? 'Antibiótico' : 'Analgésico',
            'administracion' => $differentAdministration ? $administration->id : 1,
            'presentacion' => $differentPresentation ? $presentation->id : 1,
        ];
        $path = $base.'/crear';
        $target = null;

        if ($method === 'PUT') {
            $target = Familia::query()->create([
                'descripcion' => 'Familia por actualizar',
                'administracion' => 1,
                'presentacion' => 1,
            ]);
            $path = $this->updatePath($base, $target->id);
        }

        $count = Familia::query()->count();
        // Exercise the same sanitization used by real create and update requests.
        $payload = [
            ...$input,
            'descripcion' => ' <b>'.$input['descripcion'].'</b> ',
        ];
        if ($nestedRelations) {
            $payload['administracion'] = ['id' => $input['administracion']];
            $payload['presentacion'] = ['id' => $input['presentacion']];
        }
        $response = $this->json($method, $path, $payload);

        if (! $differentDescription && ! $differentAdministration && ! $differentPresentation) {
            $response->assertStatus(422)->assertJsonPath('message',
                'Ya existe una familia con la misma descripcion, tipo de administracion y tipo de presentacion.',
            );
            $this->assertDatabaseCount('familia', $count);
            if ($target !== null) {
                $this->assertSame('Familia por actualizar', $target->fresh()->descripcion);
            }

            return;
        }

        $response->assertStatus($method === 'POST' ? 201 : 200)->assertJsonPath('data', true);
        $this->assertDatabaseHas('familia', $target === null ? $input : ['id' => $target->id, ...$input]);
        $this->assertDatabaseCount('familia', $count + ($method === 'POST' ? 1 : 0));
    }

    public function test_family_update_accepts_its_own_combination_with_nested_and_scalar_ids(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();

        $this->putJson($this->updatePath($this->catalogBase('familias'), 1), [
            'descripcion' => 'Analgésico',
            'presentacion' => ['id' => '1', 'descripcion' => 'Tableta'],
            'administracion' => '1',
        ])->assertOk()->assertJsonPath('data', true);
        $this->assertDatabaseCount('familia', 1);
        $this->assertDatabaseHas('familia', [
            'id' => 1, 'descripcion' => 'Analgésico', 'presentacion' => 1, 'administracion' => 1,
        ]);
    }

    public function test_family_rejects_missing_or_invalid_related_ids_without_writing(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        $base = $this->catalogBase('familias');
        $original = (array) DB::table('familia')->where('id', 1)->first();

        foreach (['POST' => $base.'/crear', 'PUT' => $this->updatePath($base, 1)] as $method => $path) {
            foreach (['presentacion', 'administracion'] as $field) {
                foreach ([[], ['descripcion' => 'Sin ID'], ['id' => []], ['id' => true], ['id' => '1abc'], ['id' => 1.5], ['id' => 999999]] as $invalid) {
                    $this->assertCatalogValidationFails($method, $path, [
                        'descripcion' => 'Nueva familia',
                        'presentacion' => 1,
                        'administracion' => 1,
                        $field => $invalid,
                    ], $field);
                }
            }
        }

        $this->assertDatabaseCount('familia', 1);
        $this->assertSame($original, (array) DB::table('familia')->where('id', 1)->first());
    }

    public static function familyCombinations(): array
    {
        $cases = [];
        foreach (['POST', 'PUT'] as $method) {
            foreach ([false, true] as $description) {
                foreach ([false, true] as $administration) {
                    foreach ([false, true] as $presentation) {
                        $name = sprintf('%s description=%d administration=%d presentation=%d',
                            $method, $description, $administration, $presentation);
                        $cases[$name.' scalar'] = [$method, $description, $administration, $presentation, false];
                        $cases[$name.' nested'] = [$method, $description, $administration, $presentation, true];
                    }
                }
            }
        }

        return $cases;
    }

    public function test_product_catalog_lists_return_paginated_records_and_family_relations(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        $base = $this->productResourceBase('ProductController');

        $this->getJson($base.'/laboratorios')->assertOk()
            ->assertJsonPath('data.0.nombre', 'Laboratorio prueba')
            ->assertJsonPath('data.0.tiene_imagen', true)
            ->assertJsonMissingPath('data.0.imagen')
            ->assertJsonPath('meta.total', 1);
        $this->getJson($base.'/familias')->assertOk()
            ->assertJsonPath('data.0.descripcion', 'Analgésico')
            ->assertJsonPath('data.0.presentacion.descripcion', 'Tableta')
            ->assertJsonPath('data.0.administracion.descripcion', 'Oral')
            ->assertJsonPath('meta.total', 1);
        $this->getJson($base.'/unidades')->assertOk()
            ->assertJsonPath('data.0.abreviatura_c', 'CAJ')
            ->assertJsonPath('data.0.abreviatura_v', 'UND')
            ->assertJsonPath('meta.total', 1);
        $this->getJson($base.'/estados')->assertOk()
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.0.descripcion', 'Activo')
            ->assertJsonPath('meta.total', 1);
        $this->getJson($base.'/categorias')->assertOk()
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.0.nombre', 'Categoría prueba')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_family_lookup_loads_only_requested_pages_and_finds_new_families_and_related_labels(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        $rows = [];
        foreach (range(1, 1000) as $number) {
            $rows[] = ['descripcion' => 'Familia '.$number, 'presentacion' => 1, 'administracion' => 1];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('familia')->insert($chunk);
        }
        $presentation = FamPresentacion::create(['descripcion' => 'Jarabe de prueba']);
        $this->postJson($this->catalogBase('familias').'/crear', [
            'descripcion' => 'Familia creada en otra pestaña',
            'presentacion' => $presentation->id, 'administracion' => 1,
        ])->assertCreated();

        $base = $this->productResourceBase('ProductController').'/familias';
        $first = $this->getJson($base.'?per_page=10')->assertOk()
            ->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 1002)
            ->assertJsonPath('meta.last_page', 101)
            ->assertJsonPath('data.0.descripcion', 'Familia creada en otra pestaña');
        $second = $this->getJson($base.'?per_page=10&page=2')->assertOk()
            ->assertJsonCount(10, 'data')->assertJsonPath('meta.current_page', 2);
        $this->assertSame([], array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
        $this->getJson($base.'?per_page=10&page=101')->assertOk()->assertJsonCount(2, 'data');
        foreach (['JARABE', 'otra pestaña'] as $search) {
            $this->getJson($base.'?'.http_build_query(['per_page' => 10, 'search' => $search]))->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.descripcion', 'Familia creada en otra pestaña');
        }
        $this->getJson($base.'?per_page=10&search=oral')->assertOk()->assertJsonPath('meta.total', 1002);
        $this->getJson($base.'?per_page=10&search=sin-coincidencias')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'?per_page=5000')->assertUnprocessable();
        $this->getJson($base.'?search[]=invalido')->assertUnprocessable();
    }

    #[DataProvider('productCatalogFilters')]
    public function test_product_catalog_filters_search_partial_values(string $catalog, array $items, int $matches): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        Laboratorio::query()->create(['nombre' => 'Otro laboratorio']);
        Familia::query()->create(['descripcion' => 'Vitaminas', 'presentacion' => 1, 'administracion' => 1]);
        ProdUnidad::query()->create([
            'abreviatura_c' => 'PAQ', 'abreviatura_v' => 'CAJ', 'cantidad_c' => 1, 'cantidad_v' => 5,
        ]);
        $base = $this->productResourceBase('ProductController');

        $response = $this->getJson($base.'/filter_'.$catalog.'?'.http_build_query([
            'filterItems' => $items,
        ]))->assertOk()->assertJsonPath('meta.total', $matches)->assertJsonCount($matches, 'data');
        if ($catalog === 'laboratorios') {
            $response->assertJsonMissingPath('data.0.imagen')->assertJsonPath('data.0.tiene_imagen', true);
        }
        if ($catalog === 'familias' && $matches > 0) {
            $response->assertJsonPath('data.0.presentacion.descripcion', 'Tableta');
        }
    }

    public static function productCatalogFilters(): array
    {
        return [
            'laboratorio nombre' => ['laboratorios', [['key' => 'nombre', 'value' => 'prueb']], 1],
            'familia descripcion' => ['familias', [['key' => 'descripcion', 'value' => 'lgés']], 1],
            'unidad compra' => ['unidades', [['key' => 'abreviatura_c', 'value' => 'PA']], 1],
            'unidad venta' => ['unidades', [['key' => 'abreviatura_v', 'value' => 'UN']], 1],
            'unidad compra o venta' => ['unidades', [
                ['key' => 'abreviatura_v', 'value' => 'CA'],
                ['key' => 'abreviatura_c', 'value' => 'CA'],
            ], 2],
            'sin resultados' => ['familias', [['key' => 'descripcion', 'value' => 'Inexistente']], 0],
        ];
    }

    public function test_product_catalog_filters_reject_unconfigured_fields_and_client_operators(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        $base = $this->productResourceBase('ProductController');

        foreach (['laboratorios' => 'nombre', 'familias' => 'descripcion', 'unidades' => 'abreviatura_c'] as $catalog => $key) {
            foreach ([
                [['key' => 'id', 'value' => '1']],
                [['key' => $key, 'value' => 'test', 'operator' => '=']],
                [['key' => $key, 'value' => 'test', 'logicalOperator' => 'OR']],
            ] as $items) {
                $this->getJson($base.'/filter_'.$catalog.'?'.http_build_query(['filterItems' => $items]))
                    ->assertStatus(422)->assertJsonPath('code', 422)
                    ->assertJsonStructure(['message', 'code', 'data' => ['errors'], 'meta'])
                    ->assertJsonPath('meta', null);
            }
        }
    }

    public function test_product_catalog_lists_and_filters_paginate(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        for ($index = 0; $index < 31; $index++) {
            ProdEstado::query()->create(['descripcion' => 'Estado extra '.$index]);
            ProdCategoria::query()->create(['nombre' => 'Categoría extra '.$index]);
            Laboratorio::query()->create(['nombre' => 'Laboratorio extra '.$index]);
            Familia::query()->create(['descripcion' => 'Familia extra '.$index, 'presentacion' => 1, 'administracion' => 1]);
            ProdUnidad::query()->create([
                'abreviatura_c' => 'CAJ', 'abreviatura_v' => 'UND', 'cantidad_c' => 2, 'cantidad_v' => $index + 1,
            ]);
        }
        $base = $this->productResourceBase('ProductController');
        foreach (['laboratorios' => ['nombre', 'Laboratorio'], 'familias' => ['descripcion', 'extra'], 'unidades' => ['abreviatura_c', 'CAJ']] as $catalog => [$key, $value]) {
            $this->getJson($base.'/'.$catalog.'?page=2')->assertOk()
                ->assertJsonPath('meta.total', 32)->assertJsonPath('meta.current_page', 2)->assertJsonCount(2, 'data');
            $this->getJson($base.'/filter_'.$catalog.'?'.http_build_query([
                'page' => 2, 'filterItems' => [['key' => $key, 'value' => $value]],
            ]))->assertOk()->assertJsonPath('meta.current_page', 2)
                ->assertJsonCount($catalog === 'familias' ? 1 : 2, 'data');
        }
        foreach (['estados', 'categorias'] as $catalog) {
            $this->getJson($base.'/'.$catalog.'?page=2')->assertOk()
                ->assertJsonPath('meta.total', 32)->assertJsonPath('meta.current_page', 2)->assertJsonCount(2, 'data');
        }
    }

    public function test_product_route_files_keep_urls_and_middleware(): void
    {
        $this->withoutMiddleware();
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/farma/productos/'));
        $this->assertCount(72, $routes);

        foreach ($routes as $route) {
            $this->assertStringNotContainsString('{catalogo}', $route->uri());
            $this->assertContains('auth:api', $route->gatherMiddleware());
            $this->assertContains('idempotency', $route->gatherMiddleware());
            $this->assertNotNull(app($route->getControllerClass()));
            if (in_array('PUT', $route->methods(), true)) {
                $this->assertMatchesRegularExpression('~/(?:update|actualizar)/\{id\}$~', $route->uri());
            }
            if (in_array($route->getActionMethod(), ['show', 'update', 'destroy'], true)) {
                $this->assertSame('[0-9]+', $route->wheres['id'] ?? null);
                $this->json($route->methods()[0], '/'.str_replace('{id}', 'invalid', $route->uri()))
                    ->assertNotFound();
            }
        }

        $this->assertCount(10, $routes->filter(fn ($route) => in_array('PUT', $route->methods(), true)));
        foreach (['listar', 'filter_productos', 'laboratorios', 'familias', 'unidades', 'estados', 'categorias', 'filter_laboratorios', 'filter_familias', 'filter_unidades', 'buscar/1', 'imagen/1', 'activos/listar'] as $path) {
            $controller = str_starts_with($path, 'activos/') ? 'ProductActiveController' : 'ProductController';
            $base = $this->productResourceBase($controller);
            $path = str_replace('activos/', '', $path);
            $route = app('router')->getRoutes()->match(
                Request::create($base.'/'.$path),
            );
            $this->assertStringEndsWith($controller, $route->getControllerClass());
        }
    }

    public function test_catalog_endpoints_preserve_sanitization_and_loaded_relations(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        $base = $this->catalogBase('unidades');

        $this->assertCatalogValidationFails('POST', $base.'/crear', [
            'abreviatura_c' => ' <b>CAJ</b> ', 'abreviatura_v' => ' UND ',
            'cantidad_c' => '1', 'cantidad_v' => '20',
        ], 'cantidad_v');

        $this->postJson($this->catalogBase('categorias').'/crear', ['nombre' => ' <b>Vitaminas</b> '])
            ->assertCreated()->assertJsonPath('data', true);
        $this->assertDatabaseHas('prod_categorias', ['nombre' => 'Vitaminas']);
        $this->postJson($this->catalogBase('estados').'/crear', ['descripcion' => null])
            ->assertCreated()->assertJsonPath('data', true);
        $this->assertDatabaseHas('prod_estado', ['descripcion' => null]);

        $this->getJson($this->catalogBase('familias').'/buscar/1')->assertOk()
            ->assertJsonPath('data.presentacion', ['id' => 1, 'descripcion' => 'Tableta'])
            ->assertJsonPath('data.administracion', ['id' => 1, 'descripcion' => 'Oral']);
        $this->getJson($this->catalogBase('principios-activos').'/buscar/1')->assertOk()
            ->assertJsonPath('data.concentracion_detalle.valor', '500 mg');
        $this->assertCatalogValidationFails('POST', $this->catalogBase('principios-activos').'/crear', [
            'nombre' => 'Nuevo', 'concentracion' => 999,
        ], 'concentracion');
        $this->getJson($base.'/desconocido/listar')->assertNotFound();
    }

    public function test_family_lists_delegate_to_catalog_services_and_return_only_id_and_description(): void
    {
        $this->withoutMiddleware();
        $this->mock(FamAdministracionService::class)
            ->shouldReceive('getAll')->once()->andReturn(new Collection([
                (new FamAdministracion)->forceFill(['id' => 7, 'descripcion' => 'Oral', 'created_at' => now(), 'extra' => 'hidden']),
            ]));
        $this->mock(FamPresentacionService::class)
            ->shouldReceive('getAll')->once()->andReturn(new Collection([
                (new FamPresentacion)->forceFill(['id' => 8, 'descripcion' => 'Tableta', 'created_at' => now(), 'extra' => 'hidden']),
            ]));
        $base = $this->catalogBase('familias');

        $this->getJson($base.'/administraciones')->assertOk()->assertExactJson([
            'message' => 'ok', 'code' => 200, 'data' => [['id' => 7, 'descripcion' => 'Oral']],
        ]);
        $this->getJson($base.'/presentaciones')->assertOk()->assertExactJson([
            'message' => 'ok', 'code' => 200, 'data' => [['id' => 8, 'descripcion' => 'Tableta']],
        ]);
    }

    public function test_family_lists_return_empty_arrays_and_all_catalog_options_without_pagination(): void
    {
        $this->withoutMiddleware();
        $base = $this->catalogBase('familias');

        foreach (['administraciones' => 'fam_administracion', 'presentaciones' => 'fam_presentacion'] as $path => $table) {
            $this->getJson($base.'/'.$path)->assertOk()->assertExactJson([
                'message' => 'ok', 'code' => 200, 'data' => [],
            ]);

            $records = [];
            for ($id = 1; $id <= 31; $id++) {
                $records[] = ['id' => $id, 'descripcion' => 'Opción '.$id];
            }
            DB::table($table)->insert($records);
            $this->getJson($base.'/'.$path)->assertOk()->assertExactJson([
                'message' => 'ok', 'code' => 200, 'data' => array_reverse($records),
            ]);
        }
    }

    public function test_catalog_write_failures_return_false(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        $repository = $this->mock(ProdCategoriaService::class);
        $repository->shouldReceive('create')->once()->andThrow(new \RuntimeException('Write failed'));
        $repository->shouldReceive('update')->once()->andReturnFalse();
        $repository->shouldReceive('delete')->once()->andThrow(new QueryException(
            'sqlite', 'delete from prod_categorias where id = ?', [1], new \PDOException('Referenced record', 23000),
        ));
        $base = $this->catalogBase('categorias');

        $this->postJson($base.'/crear', ['nombre' => 'Nueva'])
            ->assertStatus(500)->assertJsonPath('data', false);
        $this->putJson($this->updatePath($base, '1'), ['nombre' => 'Nueva'])
            ->assertNotFound()->assertJsonPath('data', false);
        $this->deleteJson($base.'/eliminar/1')
            ->assertStatus(409)->assertJsonPath('data', false);
    }

    public function test_catalog_controller_respects_false_from_the_base_repository(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        $repository = $this->mock(ProdCategoriaService::class);
        $repository->shouldReceive('create')->once()->andReturnFalse();
        $repository->shouldReceive('update')->once()->andReturnFalse();
        $repository->shouldReceive('delete')->once()->andReturnFalse();
        $base = $this->catalogBase('categorias');

        $this->postJson($base.'/crear', ['nombre' => 'Nueva'])
            ->assertStatus(422)->assertJsonPath('data', false);
        $this->putJson($this->updatePath($base, 1), ['nombre' => 'Nueva'])
            ->assertNotFound()->assertJsonPath('data', false);
        $this->deleteJson($base.'/eliminar/1')->assertNotFound()->assertJsonPath('data', false);
    }

    public function test_product_repositories_support_inherited_reads_and_boolean_writes(): void
    {
        $this->seedProductDomain();
        foreach (['ProdUnidad', 'ProdEstado', 'ProdCategoria', 'FamPresentacion', 'FamAdministracion',
            'Familia', 'Concentracion', 'PrincipalActivo', 'ProductActive', 'Product'] as $name) {
            $repository = app('App\\Interfaces\\Farma\\'.$name.'Service');
            $this->assertInstanceOf(RepositoryInterface::class, $repository);
            $this->assertInstanceOf(Repository::class, $repository);
            $this->assertCount(1, $repository->getAll());
            $this->assertSame(1, $repository->findById(1)->id);
            $this->assertSame(1, $repository->whereFirst(['id' => 1])->id);
            $this->assertSame(1, $repository->setPerPage(1)->paginate()->perPage());
            $this->assertFalse($repository->update(999999, ['id' => 999999]));
            $this->assertFalse($repository->delete(999999));
        }

        $categories = app(ProdCategoriaService::class);
        $this->assertFalse($categories->create(['nombre' => 'Categoría prueba']));
        $this->assertTrue($categories->create(['nombre' => 'Nueva categoría']));
        $id = $categories->whereFirst(['nombre' => 'Nueva categoría'])->id;
        $this->assertTrue($categories->update($id, ['nombre' => 'Actualizada']));
        $this->assertTrue($categories->delete($id));
    }

    public function test_inherited_writes_preserve_nullable_fields_and_product_image(): void
    {
        [$product] = $this->seedProductDomain();
        $states = app(ProdEstadoService::class);
        $this->assertTrue($states->update(1, ['descripcion' => null]));
        $this->assertDatabaseHas('prod_estado', ['id' => 1, 'descripcion' => null]);

        $repository = app(ProductService::class);
        $image = base64_encode(str_repeat('image-data', 300));
        $this->assertTrue($repository->update($product->id, ['imagen' => $image, 'codigobar' => null]));
        $this->assertSame($image, $repository->getImage($product->id));
        $this->assertDatabaseHas('productos', ['id' => $product->id, 'codigobar' => null]);
        $this->assertArrayNotHasKey('imagen', $repository->findById($product->id)->getAttributes());

        $data = $product->only(['categoria', 'laboratorio', 'unidad', 'familia', 'codigo', 'descripcion', 'estado']);
        $data['codigo'] = 'NEW-PRODUCT';
        $data['imagen'] = $image;
        $this->assertTrue($repository->create($data, [1, 1]));
        $created = $repository->whereFirst(['codigo' => 'NEW-PRODUCT']);
        $this->assertSame([1], $created->principiosActivos->pluck('id')->all());
        $this->assertSame($image, $repository->getImage($created->id));
    }

    public function test_product_writes_roll_back_when_active_principle_sync_fails(): void
    {
        [$product] = $this->seedProductDomain();
        $repository = app(ProductService::class);
        $principle = PrincipalActivo::query()->create(['nombre' => 'Otro activo', 'concentracion' => 1]);
        DB::unprepared("CREATE TRIGGER reject_active_insert BEFORE INSERT ON prod_activo BEGIN SELECT RAISE(ABORT, 'Test sync failure'); END");

        $data = $product->only(['categoria', 'laboratorio', 'unidad', 'familia', 'codigo', 'codigobar', 'descripcion', 'estado']);
        $data['codigo'] = 'ROLLBACK-CREATE';
        $data['codigobar'] = null;
        $this->assertFalse($repository->create($data, [$principle->id]));
        $this->assertDatabaseMissing('productos', ['codigo' => 'ROLLBACK-CREATE']);

        $this->assertFalse($repository->update($product->id, ['descripcion' => 'No debe persistir'], [$principle->id]));
        $this->assertSame($product->descripcion, $product->fresh()->descripcion);
        $this->assertSame([1], $product->fresh()->principiosActivos->pluck('id')->all());
        $this->assertSame(0, DB::transactionLevel());
    }

    #[DataProvider('nestedProductWriteMethods')]
    public function test_product_writes_accept_nested_relation_ids(string $method): void
    {
        $this->withoutMiddleware();
        [$product] = $this->seedProductDomain();
        $category = ProdCategoria::query()->create(['nombre' => 'Otra categoría']);
        $base = $this->productResourceBase('ProductController');
        $expected = $product->only(['categoria', 'laboratorio', 'unidad', 'familia', 'codigo', 'codigobar', 'descripcion', 'estado']);
        $expected['categoria'] = $category->id;
        $expected['codigo'] = $method === 'POST' ? 'NESTED-CREATE' : $product->codigo;
        $expected['codigobar'] = null;
        $expected['descripcion'] = 'Producto anidado';
        $input = $expected;
        foreach (['categoria', 'laboratorio', 'unidad', 'familia', 'estado'] as $field) {
            $input[$field] = ['id' => $expected[$field], 'nombre' => 'Dato de presentación'];
        }
        $input['descripcion'] = ' <b>Producto anidado</b> ';
        $input['codigobar'] = ' ';
        $input['id'] = 999999;
        $path = $method === 'POST' ? $base.'/crear' : $this->updatePath($base, $product->id);

        $this->json($method, $path, $input)
            ->assertStatus($method === 'POST' ? 201 : 200)
            ->assertJsonPath('data', true);

        $this->assertDatabaseHas('productos', $method === 'POST' ? $expected : ['id' => $product->id, ...$expected]);
        $this->assertDatabaseCount('productos', $method === 'POST' ? 2 : 1);
    }

    public static function nestedProductWriteMethods(): array
    {
        return ['crear' => ['POST'], 'actualizar' => ['PUT']];
    }

    public function test_product_http_update_validates_syncs_and_clears_active_principles(): void
    {
        $this->withoutMiddleware();
        [$product] = $this->seedProductDomain();
        $principle = PrincipalActivo::query()->create(['nombre' => 'Nuevo principio', 'concentracion' => 1]);
        $base = $this->productResourceBase('ProductController');
        $path = $this->updatePath($base, $product->id);
        $input = $product->only(['categoria', 'laboratorio', 'unidad', 'familia', 'codigo', 'codigobar', 'descripcion', 'estado']);

        $this->putJson($path, [...$input, 'principios_activos' => [$principle->id]])->assertOk();
        $this->assertSame([$principle->id], $product->fresh()->principiosActivos->pluck('id')->all());
        $this->putJson($path, $input)->assertOk();
        $this->assertSame([$principle->id], $product->fresh()->principiosActivos->pluck('id')->all());
        $this->putJson($path, [...$input, 'principios_activos' => [999999]])
            ->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['principios_activos.0']]]);
        $this->assertSame([$principle->id], $product->fresh()->principiosActivos->pluck('id')->all());
        $this->putJson($path, [...$input, 'principios_activos' => []])->assertOk();
        $this->assertSame([], $product->fresh()->principiosActivos->pluck('id')->all());
    }

    public function test_product_rejects_invalid_nested_relations_before_writing(): void
    {
        $this->withoutMiddleware();
        [$product] = $this->seedProductDomain();
        $base = $this->productResourceBase('ProductController');
        $original = $product->fresh()->getAttributes();
        $input = $product->only(['codigo', 'codigobar', 'descripcion']);
        foreach (['categoria', 'laboratorio', 'unidad', 'familia', 'estado'] as $field) {
            $input[$field] = ['id' => $product->getAttribute($field)];
        }

        foreach (['categoria', 'laboratorio', 'unidad', 'familia', 'estado'] as $field) {
            foreach ([[], ['id' => 999999]] as $invalid) {
                $this->putJson($this->updatePath($base, $product->id), array_replace($input, [$field => $invalid]))
                    ->assertUnprocessable()
                    ->assertJsonPath('code', 422);
            }
        }

        $this->assertDatabaseCount('productos', 1);
        $this->assertSame($original, $product->fresh()->getAttributes());
    }

    public function test_product_update_uses_route_id_and_accepts_body_without_id(): void
    {
        $this->withoutMiddleware();
        [$product] = $this->seedProductDomain();
        $base = $this->productResourceBase('ProductController');
        $input = $product->only(['categoria', 'laboratorio', 'unidad', 'familia', 'codigo', 'codigobar', 'descripcion', 'estado']);

        $this->putJson($this->updatePath($base, $product->id), $input)
            ->assertOk()->assertJsonPath('data', true);
        $input['descripcion'] = 'Producto actualizado por URL';
        $this->putJson($this->updatePath($base, $product->id), ['id' => 999999, ...$input])
            ->assertOk()->assertJsonPath('data', true);
        $this->assertDatabaseHas('productos', ['id' => $product->id, 'descripcion' => $input['descripcion']]);
        $this->assertCatalogValidationFails('PUT', $this->updatePath($base, '999999'), ['id' => $product->id, ...$input], 'id');
        $this->putJson($this->updatePath($base, ''), ['id' => $product->id, ...$input])->assertNotFound();
        $this->putJson($this->updatePath($base, 'invalid'), $input)->assertNotFound();
    }

    public function test_active_update_uses_route_id_and_accepts_body_without_id(): void
    {
        $this->withoutMiddleware();
        [$product, $association] = $this->seedProductDomain();
        $base = $this->productResourceBase('ProductActiveController');
        $input = ['producto' => $product->id, 'pactivo' => $association->pactivo];

        $this->putJson($this->updatePath($base, $association->id), $input)
            ->assertOk()->assertJsonPath('data', true);
        $principle = PrincipalActivo::query()->create(['nombre' => 'Nuevo activo', 'concentracion' => 1]);
        $input['pactivo'] = $principle->id;
        $this->putJson($this->updatePath($base, $association->id), ['id' => 999999, ...$input])->assertOk();
        $this->assertDatabaseHas('prod_activo', ['id' => $association->id, ...$input]);
        $this->assertCatalogValidationFails('PUT', $this->updatePath($base, '999999'), ['id' => $association->id, ...$input], 'id');
        $this->putJson($this->updatePath($base, ''), ['id' => $association->id, ...$input])->assertNotFound();
        $this->putJson($this->updatePath($base, 'invalid'), $input)->assertNotFound();
    }

    public function test_validation_returns_the_first_message_and_all_errors_without_writing(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        $base = $this->catalogBase('familias');
        $original = (array) DB::table('familia')->where('id', 1)->first();

        foreach (['POST' => $base.'/crear', 'PUT' => $this->updatePath($base, 1)] as $method => $path) {
            $this->json($method, $path, [])->assertStatus(422)->assertExactJson([
                'message' => 'El campo presentacion es obligatorio.',
                'code' => 422,
                'data' => ['errors' => [
                    'presentacion' => ['El campo presentacion es obligatorio.'],
                    'administracion' => ['El campo administracion es obligatorio.'],
                    'descripcion' => ['El campo descripcion es obligatorio.'],
                ]],
                'meta' => null,
            ]);
        }

        $this->assertSame(1, DB::table('familia')->count());
        $this->assertSame($original, (array) DB::table('familia')->where('id', 1)->first());
    }

    private function catalogBase(string $catalog): string
    {
        $controller = match ($catalog) {
            'unidades' => 'ProdUnidadController',
            'estados' => 'ProdEstadoController',
            'categorias' => 'ProdCategoriaController',
            'presentaciones' => 'FamPresentacionController',
            'administraciones' => 'FamAdministracionController',
            'familias' => 'FamiliaController',
            'concentraciones' => 'ConcentracionController',
            'principios-activos' => 'PrincipalActivoController',
        };

        return $this->productResourceBase($controller);
    }

    private function productResourceBase(string $controller): string
    {
        $action = 'App\\Http\\Controllers\\API\\Farma\\Productos\\'.$controller.'@index';
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->getActionName() === $action);
        $this->assertNotNull($route, 'Missing list route for '.$controller);

        return '/'.substr($route->uri(), 0, -strlen('/listar'));
    }

    private function updatePath(string $base, int|string $id): string
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => str_starts_with('/'.$route->uri(), $base.'/')
                && $route->getActionMethod() === 'update');
        $this->assertNotNull($route, 'Missing update route for '.$base);

        return rtrim('/'.str_replace('{id}', (string) $id, $route->uri()), '/');
    }

    private function assertCatalogValidationFails(string $method, string $uri, array $input, ?string $field = null): void
    {
        $response = $this->json($method, $uri, $input)
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('code', 422)
            ->assertJsonPath('meta', null);
        $this->assertSame(['message', 'code', 'data', 'meta'], array_keys($response->json()));
        $this->assertNotEmpty($response->json('message'));
        $this->assertNotEmpty($response->json('data.errors'));

        if ($field !== null) {
            $response->assertJsonValidationErrors($field, 'data.errors');
            $this->assertStringContainsString(str_replace('_', ' ', $field), $response->json('message'));
        }
    }

    public function test_product_repository_exposes_options_and_syncs_active_principles(): void
    {
        [$product] = $this->seedProductDomain();
        $repository = new ProductRepository(new Producto);
        $options = $repository->options();

        $this->assertSame('Categoría prueba', $options['categorias'][0]['label']);
        $this->assertSame('Laboratorio prueba', $options['laboratorios'][0]['label']);
        $this->assertSame('Proveedor prueba', $options['proveedores'][0]['label']);
        $this->assertSame('CAJ x 1 → UND x 20', $options['unidades'][0]['label']);
        $this->assertSame('CAJ', $options['unidades'][0]['unidad_compra']);
        $this->assertSame('UND', $options['unidades'][0]['unidad_venta']);
        $this->assertSame('Analgésico', $options['familias'][0]['nombre']);
        $this->assertSame('Tableta', $options['familias'][0]['presentacion']);
        $this->assertSame('Oral', $options['familias'][0]['administracion']);
        $this->assertSame('Paracetamol 500 mg', $options['principios_activos'][0]['label']);
        $this->assertSame('Paracetamol', $options['principios_activos'][0]['nombre']);
        $this->assertSame('500 mg', $options['principios_activos'][0]['concentracion']);

        $secondPrinciple = PrincipalActivo::query()->create([
            'nombre' => 'Cafeína',
            'concentracion' => Concentracion::query()->value('id'),
        ]);
        $updated = $repository->update(
            $product->id,
            ['descripcion' => 'Producto actualizado'],
            [$secondPrinciple->id],
        );

        $this->assertTrue($updated);
        $updated = $repository->findById($product->id);
        $this->assertSame('Producto actualizado', $updated->descripcion);
        $this->assertSame(
            [$secondPrinciple->id],
            $updated->principiosActivos->pluck('id')->all(),
        );
    }

    public function test_product_repository_applies_advanced_search_filters(): void
    {
        [$product] = $this->seedProductDomain();
        $repository = new ProductRepository(new Producto);

        $matches = $repository->paginate(
            perPage: 10,
            search: '750000000001',
            laboratory: (int) $product->laboratorio,
            provider: 1,
        );

        $this->assertSame(1, $matches->total());
        $this->assertSame($product->id, $matches->items()[0]->id);

        $otherProvider = DB::table('proveedores')->insertGetId([
            'nombre' => 'Proveedor sin el producto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, $repository->paginate(provider: $otherProvider)->total());
    }

    public function test_product_filter_searches_text_and_combines_catalog_ids(): void
    {
        $this->withoutMiddleware();
        [$product] = $this->seedProductDomain();
        $otherLaboratory = Laboratorio::query()->create(['nombre' => 'Otro fabricante']);
        $otherCategory = ProdCategoria::query()->create(['nombre' => 'Otra categoría']);
        $otherFamily = Familia::query()->create([
            'presentacion' => 1,
            'administracion' => 1,
            'descripcion' => 'Vitaminas',
        ]);
        $other = $product->replicate();
        $other->fill([
            'codigo' => 'XYZ-999',
            'codigobar' => '890123456789',
            'descripcion' => 'Suplemento diario',
            'laboratorio' => $otherLaboratory->id,
            'familia' => $otherFamily->id,
            'categoria' => $otherCategory->id,
        ])->save();
        $base = $this->productResourceBase('ProductController').'/filter_productos';
        $items = [];

        foreach ([
            'codigo' => 'RD-00',
            'codigobar' => '000000',
            'descripcion' => 'de prueba',
            'laboratorio' => $product->laboratorio,
            'familia' => $product->familia,
            'categoria' => $product->categoria,
        ] as $key => $value) {
            $item = ['key' => $key, 'value' => $value];
            $items[] = $item;
            $response = $this->getJson($base.'?'.http_build_query(['filterItems' => [$item]]))
                ->assertOk()->assertJsonPath('meta.total', 1)
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->id);
            $this->assertArrayNotHasKey('imagen', $response->json('data.0'));
            $this->assertArrayNotHasKey('imagen', $response->json('data.0.laboratorio'));
            $response->assertJsonPath('data.0.laboratorio.nombre', 'Laboratorio prueba')
                ->assertJsonPath('data.0.familia.descripcion', 'Analgésico');
        }

        // El cliente también envía FilterModel en el cuerpo JSON de GET.
        $this->json('GET', $base, ['filterItems' => $items])
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $product->id);
        foreach ([3 => $otherLaboratory->id, 4 => $otherFamily->id, 5 => $otherCategory->id] as $index => $id) {
            $mismatched = $items;
            $mismatched[$index]['value'] = $id;
            $this->json('GET', $base, ['filterItems' => $mismatched])
                ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
        }
    }

    public function test_product_filter_matches_any_text_field_and_keeps_catalog_constraints(): void
    {
        $this->withoutMiddleware();
        [$product] = $this->seedProductDomain();
        $base = $this->productResourceBase('ProductController').'/filter_productos';
        $catalogItems = [];
        foreach (['laboratorio', 'familia', 'categoria'] as $key) {
            $catalogItems[] = ['key' => $key, 'value' => $product->{$key}];
        }

        // Cada texto coincide exclusivamente con uno de los tres campos.
        foreach (['RD-00', '000000', 'de prueba'] as $text) {
            $textItems = [];
            foreach (['codigo', 'codigobar', 'descripcion'] as $key) {
                $textItems[] = ['key' => $key, 'value' => $text];
            }
            $this->json('GET', $base, ['filterItems' => $textItems])
                ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $product->id);
            $this->json('GET', $base, ['filterItems' => [...$textItems, ...$catalogItems]])
                ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $product->id);

            foreach (array_keys($catalogItems) as $index) {
                $mismatched = $catalogItems;
                $mismatched[$index]['value'] = 999999;
                $this->json('GET', $base, ['filterItems' => [...$textItems, ...$mismatched]])
                    ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
            }
        }

        $this->json('GET', $base, ['filterItems' => [
            ['key' => 'codigo', 'value' => 'Inexistente'],
            ['key' => 'codigobar', 'value' => 'Inexistente'],
            ['key' => 'descripcion', 'value' => 'Inexistente'],
            ...$catalogItems,
        ]])->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    }

    public function test_product_list_searches_the_complete_catalog_before_pagination_regardless_of_case(): void
    {
        $this->withoutMiddleware();
        [$product] = $this->seedProductDomain();
        $product->update(['descripcion' => 'Albendafar Suspension, frasco 20ml']);
        for ($index = 1; $index <= 35; $index++) {
            $copy = $product->replicate();
            $copy->fill(['codigo' => 'OTHER-'.$index, 'codigobar' => null, 'descripcion' => 'Otro producto '.$index])->save();
        }
        $base = $this->productResourceBase('ProductController').'/listar';
        $unfiltered = $this->getJson($base)->assertOk()->assertJsonPath('meta.total', 36);
        $this->assertNotContains($product->id, array_column($unfiltered->json('data'), 'id'));

        foreach (['Albendafar', 'albendafar', 'ALBENDAFAR', 'bendafar'] as $search) {
            $this->getJson($base.'?'.http_build_query(['search' => $search]))
                ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $product->id);
        }
        $this->getJson($base.'?search=NoExiste')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_catalog_list_searches_all_pages_and_related_labels(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        $target = Concentracion::create(['valor' => 'Concentracion objetivo 2MG']);
        for ($index = 1; $index <= 35; $index++) {
            Concentracion::create(['valor' => 'Dosis distinta '.$index]);
        }
        $base = $this->productResourceBase('ConcentracionController').'/listar';
        $this->getJson($base.'?search=OBJETIVO')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $target->id);
        $active = PrincipalActivo::create(['nombre' => 'Sustancia buscada', 'concentracion' => $target->id]);
        $this->getJson($this->productResourceBase('PrincipalActivoController').'/listar?search=objetivo')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $active->id);
        $this->getJson($base.'?'.http_build_query(['search' => ['invalid']]))->assertUnprocessable();
    }

    public function test_product_list_searches_related_labels_without_losing_catalog_constraints(): void
    {
        $this->withoutMiddleware();
        [$product] = $this->seedProductDomain();
        $base = $this->productResourceBase('ProductController').'/listar';
        $this->getJson($base.'?'.http_build_query(['search' => 'laboratorio prueba']))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $product->id);
        $this->getJson($base.'?'.http_build_query(['search' => 'laboratorio prueba', 'laboratorio' => $product->laboratorio]))
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_product_filter_paginates_matching_products(): void
    {
        $this->withoutMiddleware();
        [$product] = $this->seedProductDomain();
        for ($index = 1; $index <= 30; $index++) {
            $copy = $product->replicate();
            $copy->fill(['codigo' => 'COPY-'.$index, 'codigobar' => null])->save();
        }
        $base = $this->productResourceBase('ProductController').'/filter_productos';
        $filter = ['filterItems' => [['key' => 'laboratorio', 'value' => $product->laboratorio]]];

        $first = $this->getJson($base.'?'.http_build_query($filter))
            ->assertOk()->assertJsonPath('meta.total', 31)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.current_page', 1)->assertJsonCount(30, 'data');
        $second = $this->getJson($base.'?'.http_build_query([...$filter, 'page' => 2]))
            ->assertOk()->assertJsonPath('meta.total', 31)->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->id);
        $this->assertSame([], array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
    }

    public function test_product_filter_rejects_invalid_fields_operators_and_pages(): void
    {
        $this->withoutMiddleware();
        $base = $this->productResourceBase('ProductController').'/filter_productos';
        foreach ([
            [],
            [['key' => 'id', 'value' => '1']],
            [['key' => 'descripcion', 'value' => '']],
            [['key' => 'codigo', 'value' => 'PRD', 'operator' => '=']],
            [['key' => 'familia', 'value' => 1, 'logicalOperator' => 'OR']],
            [['key' => 'codigo', 'value' => 'PRD'], ['key' => 'codigo', 'value' => 'XYZ']],
        ] as $items) {
            $this->json('GET', $base, ['filterItems' => $items])
                ->assertUnprocessable()->assertJsonPath('code', 422);
        }
        foreach (['laboratorio', 'familia', 'categoria'] as $key) {
            foreach (['nombre', '1%', '1.5', 1.5, 0, -1] as $value) {
                $this->json('GET', $base, ['filterItems' => [['key' => $key, 'value' => $value]]])
                    ->assertUnprocessable()->assertJsonPath('code', 422);
            }
        }
        foreach ([0, -1, 'abc', 1.5] as $page) {
            $this->getJson($base.'?'.http_build_query([
                'filterItems' => [['key' => 'codigo', 'value' => 'PRD']],
                'page' => $page,
            ]))->assertUnprocessable()->assertJsonPath('code', 422);
        }
    }

    public function test_product_filters_active_principles_by_partial_name_without_pagination(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        for ($index = 1; $index <= 35; $index++) {
            PrincipalActivo::query()->create(['nombre' => 'Paracetamol '.$index, 'concentracion' => 1]);
        }
        PrincipalActivo::query()->create(['nombre' => 'Cafeína', 'concentracion' => 1]);
        $base = $this->productResourceBase('ProductController').'/filter_principios_activos';

        $response = $this->getJson($base.'?'.http_build_query([
            'name' => 'principios_activos',
            'filterItems' => [['key' => 'nombre', 'value' => 'racetam']],
        ]))->assertOk()->assertJsonCount(36, 'data');
        foreach ($response->json('data') as $principle) {
            $this->assertStringContainsString('Paracetamol', $principle['nombre']);
            $this->assertSame('500 mg', $principle['concentracion_detalle']['valor']);
            $this->assertArrayNotHasKey('pivot', $principle);
        }
        $this->getJson($base.'?'.http_build_query([
            'filterItems' => [['key' => 'nombre', 'value' => 'Inexistente']],
        ]))->assertOk()->assertJsonPath('data', []);
        // ShApi envia FilterModel como cuerpo JSON, incluso en solicitudes GET.
        $this->json('GET', $base, [
            'name' => 'principios_activos',
            'filterItems' => [['key' => 'nombre', 'value' => 'Cafe']],
        ])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.nombre', 'Cafeína');
        $this->assertDatabaseCount('prod_activo', 1);
    }

    public function test_product_active_principle_filter_rejects_invalid_fields_and_operators(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        $base = $this->productResourceBase('ProductController').'/filter_principios_activos';
        foreach ([
            [],
            [['key' => 'id', 'value' => '1']],
            [['key' => 'concentracion', 'value' => '1']],
            [['key' => 'nombre', 'value' => '']],
            [['key' => 'nombre', 'value' => 'Para', 'operator' => '=']],
            [['key' => 'nombre', 'value' => 'Para', 'logicalOperator' => 'OR']],
        ] as $items) {
            $this->json('GET', $base, ['filterItems' => $items])
                ->assertUnprocessable()->assertJsonPath('code', 422);
        }
        $this->assertDatabaseCount('principal_activos', 1);
    }

    public function test_product_form_can_load_complete_catalogs_in_one_response(): void
    {
        $this->withoutMiddleware();
        $this->seedProductDomain();
        for ($index = 1; $index <= 35; $index++) {
            PrincipalActivo::query()->create(['nombre' => 'Principio '.$index, 'concentracion' => 1]);
            Laboratorio::query()->create(['nombre' => 'Laboratorio '.$index]);
        }

        $base = $this->productResourceBase('ProductController');
        foreach (['categorias' => 1, 'laboratorios' => 36, 'unidades' => 1, 'familias' => 1, 'estados' => 1, 'principios_activos' => 36] as $catalog => $count) {
            $this->getJson($base.'/'.$catalog.'?all=1')->assertOk()
                ->assertJsonCount($count, 'data')
                ->assertJsonPath('meta.total', $count)
                ->assertJsonPath('meta.last_page', 1);
        }

        $this->getJson($base.'/principios_activos?all=1')->assertOk()
            ->assertJsonPath('data.0.concentracion_detalle.valor', '500 mg');
        $this->getJson($base.'/laboratorios')->assertOk()->assertJsonCount(30, 'data');
        $this->getJson($base.'/principios_activos?all=0')->assertOk()->assertJsonCount(15, 'data');
        $this->getJson($base.'/principios_activos?all=invalid')->assertUnprocessable();
    }

    public function test_product_lists_all_active_principles_in_pages_of_fifteen(): void
    {
        $this->withoutMiddleware();
        $base = $this->productResourceBase('ProductController').'/principios_activos';
        $this->getJson($base)->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
        [, $association] = $this->seedProductDomain();
        for ($index = 1; $index <= 35; $index++) {
            PrincipalActivo::query()->create(['nombre' => 'Principio '.$index, 'concentracion' => 1]);
        }

        $this->getJson($base)->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.current_page', 1);
        $ids = [];
        foreach ([1 => 15, 2 => 15, 3 => 6, 4 => 0] as $page => $count) {
            $response = $this->getJson($base.'?page='.$page)->assertOk()
                ->assertJsonCount($count, 'data')->assertJsonPath('meta.total', 36)
                ->assertJsonPath('meta.current_page', $page)->assertJsonPath('meta.last_page', 3);
            foreach ($response->json('data') as $principle) {
                $ids[] = $principle['id'];
                $this->assertSame('500 mg', $principle['concentracion_detalle']['valor']);
                $this->assertArrayNotHasKey('pivot', $principle);
            }
        }
        $this->assertCount(36, array_unique($ids));
        $this->assertContains($association->pactivo, $ids);
        foreach (['0', '-1', 'invalid'] as $page) {
            $this->getJson($base.'?page='.$page)->assertUnprocessable();
        }
        $this->assertDatabaseCount('prod_activo', 1);
    }

    public function test_product_active_principles_list_only_its_associations_with_concentration(): void
    {
        $this->withoutMiddleware();
        [$product, $association] = $this->seedProductDomain();
        PrincipalActivo::query()->create(['nombre' => 'Sin asociar', 'concentracion' => 1]);
        $base = $this->productResourceBase('ProductController').'/principios_activos';

        $response = $this->getJson($base.'/'.$product->id)->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $association->pactivo)
            ->assertJsonPath('data.0.concentracion_detalle.valor', '500 mg');
        $this->assertArrayNotHasKey('pivot', $response->json('data.0'));
        $this->getJson($base.'/999999')->assertNotFound();
        $this->getJson($base.'/invalid')->assertNotFound();
        $product->principiosActivos()->detach();
        $this->getJson($base.'/'.$product->id)->assertOk()->assertJsonPath('data', []);
    }

    public function test_product_active_principles_add_validates_and_preserves_existing_associations(): void
    {
        $this->withoutMiddleware();
        [$product, $association] = $this->seedProductDomain();
        $principle = PrincipalActivo::query()->create(['nombre' => 'Cafeína', 'concentracion' => 1]);
        $base = $this->productResourceBase('ProductController').'/crear_principios_activos';

        $this->postJson($base.'/'.$product->id, ['pactivo' => $principle->id, 'producto' => 999999])
            ->assertCreated()->assertJsonPath('data', true);
        $this->assertDatabaseHas('prod_activo', ['producto' => $product->id, 'pactivo' => $association->pactivo]);
        $this->assertDatabaseHas('prod_activo', ['producto' => $product->id, 'pactivo' => $principle->id]);
        foreach ([[], ['pactivo' => 999999], ['pactivo' => 'invalid'], ['pactivo' => $principle->id]] as $input) {
            $this->postJson($base.'/'.$product->id, $input)->assertUnprocessable();
        }
        $this->postJson($base.'/999999', ['pactivo' => $principle->id])->assertNotFound();
        $this->assertDatabaseCount('prod_activo', 2);
    }

    public function test_product_active_principles_remove_only_the_requested_product_relation(): void
    {
        $this->withoutMiddleware();
        [$product, $association] = $this->seedProductDomain();
        $otherProduct = $product->replicate();
        $otherProduct->codigo = 'OTRO';
        $otherProduct->codigobar = null;
        $otherProduct->save();
        $otherProduct->principiosActivos()->attach($association->pactivo);
        $secondPrinciple = PrincipalActivo::query()->create(['nombre' => 'Cafeína', 'concentracion' => 1]);
        $product->principiosActivos()->attach($secondPrinciple->id);
        $base = $this->productResourceBase('ProductController').'/remove_principios_activos';
        $path = $base.'/'.$product->id;
        $body = ['pactivo' => $association->pactivo, 'producto' => $otherProduct->id];

        foreach ([[], ['pactivo' => 'invalid'], ['pactivo' => 0], ['pactivo' => -1], ['pactivo' => []]] as $invalidBody) {
            $this->deleteJson($path, $invalidBody)->assertUnprocessable();
            $this->assertDatabaseCount('prod_activo', 3);
        }
        $this->deleteJson($path, $body)->assertOk()->assertJsonPath('data', true);
        $this->assertDatabaseMissing('prod_activo', ['producto' => $product->id, 'pactivo' => $association->pactivo]);
        $this->assertDatabaseHas('principal_activos', ['id' => $association->pactivo]);
        $this->assertDatabaseHas('prod_activo', ['producto' => $otherProduct->id, 'pactivo' => $association->pactivo]);
        $this->assertDatabaseHas('prod_activo', ['producto' => $product->id, 'pactivo' => $secondPrinciple->id]);
        $this->deleteJson($path, $body)->assertNotFound();
        $this->deleteJson($base.'/999999', $body)->assertNotFound();
        $this->deleteJson($base.'/invalid', $body)->assertNotFound();
        $this->deleteJson($path, ['pactivo' => 999999])->assertNotFound();
        $this->deleteJson($path.'/'.$association->pactivo)->assertNotFound();
    }

    public function test_parent_lookup_requires_its_own_options_permission_and_active_hierarchy(): void
    {
        foreach (['modulo_estados', 'vista_estados'] as $table) {
            Schema::create($table, function (Blueprint $schema): void {
                $schema->id();
                $schema->string('descripcion');
            });
            DB::table($table)->insert(['id' => 1, 'descripcion' => 'ACTIVO']);
        }
        Schema::create('modulos', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->integer('estado');
        });
        Schema::create('vistas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->integer('estado');
            $table->integer('modulo');
        });
        Schema::create('actionsvistas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo');
            $table->integer('vista');
        });
        Schema::create('permisos', function (Blueprint $table): void {
            $table->id();
            $table->integer('usuario');
            $table->integer('modulo');
            $table->integer('vista');
            $table->integer('actionvista');
            $table->dateTime('lifetime')->nullable();
        });
        DB::table('modulos')->insert(['id' => 1, 'codigo' => 'frm', 'estado' => 1]);
        DB::table('vistas')->insert(['id' => 1, 'codigo' => 'prd', 'estado' => 1, 'modulo' => 1]);
        DB::table('actionsvistas')->insert(['id' => 1, 'codigo' => 'prdbtn07', 'vista' => 1]);
        $middleware = new EnsureProductParentPermission;
        $request = Request::create('/api/farma/productos/producto/padres');
        $request->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier(): int
            {
                return 99;
            }
        });
        $request->headers->set('Action-Btn', 'prdbtn07');
        $next = fn () => response()->json(['ok' => true]);
        $this->assertSame(403, $middleware->handle($request, $next)->getStatusCode());
        DB::table('permisos')->insert(['id' => 1, 'usuario' => 99, 'modulo' => 1, 'vista' => 1, 'actionvista' => 1]);
        $this->assertSame(200, $middleware->handle($request, $next)->getStatusCode());
        $request->headers->set('Action-Btn', 'prdbtn01');
        $this->assertSame(403, $middleware->handle($request, $next)->getStatusCode());
        $request->headers->set('Action-Btn', 'prdbtn07');
        DB::table('permisos')->where('id', 1)->update(['lifetime' => now()->subMinute()]);
        $this->assertSame(403, $middleware->handle($request, $next)->getStatusCode());
        DB::table('permisos')->where('id', 1)->update(['lifetime' => null]);
        DB::table('actionsvistas')->where('id', 1)->update(['vista' => 2]);
        $this->assertSame(403, $middleware->handle($request, $next)->getStatusCode());

        DB::table('actionsvistas')->where('id', 1)->update(['vista' => 1, 'codigo' => 'prdbtn02']);
        $request->headers->set('Action-Btn', 'prdbtn02');
        $this->assertSame(403, $middleware->handle($request, $next, 'prdbtn19')->getStatusCode());
        $request->headers->set('Action-Btn', 'prdbtn19');
        $this->assertSame(403, $middleware->handle($request, $next, 'prdbtn19')->getStatusCode());
        DB::table('actionsvistas')->where('id', 1)->update(['codigo' => 'prdbtn19']);
        $this->assertSame(200, $middleware->handle($request, $next, 'prdbtn19')->getStatusCode());
        DB::table('permisos')->where('id', 1)->update(['lifetime' => now()->subMinute()]);
        $this->assertSame(403, $middleware->handle($request, $next, 'prdbtn19')->getStatusCode());
        $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) => $route->getActionMethod() === 'createChild');
        $this->assertNotNull($route);
        $this->assertContains(EnsureProductParentPermission::class.':prdbtn19', $route->gatherMiddleware());
    }

    public function test_product_parent_lookup_is_paginated_and_escapes_search(): void
    {
        [$parent] = $this->seedProductDomain();
        $this->withoutMiddleware();
        $this->getJson('/api/farma/productos/producto/padres?search=PRD')->assertOk()->assertJsonFragment(['codigo' => 'PRD-001']);
        $this->getJson('/api/farma/productos/producto/padres?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/farma/productos/producto/padres?exclude='.$parent->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/farma/productos/producto/padres?page=0')->assertUnprocessable();
        $this->getJson('/api/farma/productos/producto/padres?exclude=1.2')->assertUnprocessable();
    }

    public function test_product_parent_persists_clears_preserves_omitted_and_rejects_cycles(): void
    {
        [$parent] = $this->seedProductDomain();
        $repository = new ProductRepository(new Producto);
        $data = $parent->only(['categoria', 'laboratorio', 'unidad', 'familia', 'descripcion', 'estado']);
        $this->assertTrue($repository->create([...$data, 'codigo' => 'CHILD-TEST', 'producto_padre_id' => (string) $parent->id]));
        $child = Producto::where('codigo', 'CHILD-TEST')->firstOrFail();
        $this->assertSame((string) $parent->id, (string) $child->producto_padre_id);
        $this->assertTrue($repository->update($child->id, ['descripcion' => 'Changed child']));
        $this->assertSame((string) $parent->id, (string) $child->fresh()->producto_padre_id);
        $resource = ProductResource::make($repository->findById($child->id))->resolve();
        $this->assertSame((string) $parent->id, $resource['producto_padre_id']);
        $this->assertSame($parent->codigo, $resource['producto_padre']['codigo']);
        foreach ([[$child->id, $child->id], [$parent->id, $child->id]] as [$target, $proposed]) {
            try {
                $repository->update($target, ['producto_padre_id' => (string) $proposed]);
                $this->fail('A parent cycle must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('producto_padre_id', $exception->errors());
            }
        }
        $this->assertNull($parent->fresh()->producto_padre_id);
        $this->assertSame((string) $parent->id, (string) $child->fresh()->producto_padre_id);
        try {
            $parent->delete();
            $this->fail('A referenced parent must not be deleted.');
        } catch (QueryException) {
            $this->assertNotNull($parent->fresh());
        }
        $this->assertTrue($repository->update($child->id, ['producto_padre_id' => null]));
        $this->assertNull($child->fresh()->producto_padre_id);
    }

    /** @return array{Producto, ProdActivo} */
    private function seedProductDomain(): array
    {
        $category = ProdCategoria::query()->create(['nombre' => 'Categoría prueba']);
        $laboratory = Laboratorio::query()->create([
            'nombre' => 'Laboratorio prueba',
            'telefono' => '2200-0000',
            'direccion' => 'Tegucigalpa',
            'imagen' => 'imagen-del-laboratorio',
        ]);
        $unit = ProdUnidad::query()->create([
            'abreviatura_c' => 'CAJ',
            'abreviatura_v' => 'UND',
            'cantidad_c' => 1,
            'cantidad_v' => 20,
        ]);
        $presentation = FamPresentacion::query()->create(['descripcion' => 'Tableta']);
        $administration = FamAdministracion::query()->create(['descripcion' => 'Oral']);
        $family = Familia::query()->create([
            'presentacion' => $presentation->id,
            'administracion' => $administration->id,
            'descripcion' => 'Analgésico',
        ]);
        $state = ProdEstado::query()->create(['descripcion' => 'Activo']);
        $concentration = Concentracion::query()->create(['valor' => '500 mg']);
        $principle = PrincipalActivo::query()->create([
            'nombre' => 'Paracetamol',
            'concentracion' => $concentration->id,
        ]);

        $product = Producto::query()->create([
            'categoria' => $category->id,
            'laboratorio' => $laboratory->id,
            'unidad' => $unit->id,
            'familia' => $family->id,
            'codigo' => 'PRD-001',
            'codigobar' => '750000000001',
            'descripcion' => 'Producto de prueba',
            'imagen' => 'imagen-del-producto',
            'estado' => $state->id,
        ]);
        $active = ProdActivo::query()->create([
            'producto' => $product->id,
            'pactivo' => $principle->id,
        ]);

        $provider = DB::table('proveedores')->insertGetId([
            'nombre' => 'Proveedor prueba',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchase = DB::table('compras')->insertGetId(['proveedor' => $provider]);
        DB::table('compra_detalle')->insert([
            'compra' => $purchase,
            'producto' => $product->id,
        ]);

        return [$product, $active];
    }

    private function createSchema(): void
    {
        Schema::create('proveedores', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 180)->unique();
            $table->timestamps();
        });
        Schema::create('laboratorios', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 180)->unique();
            $table->string('telefono', 20)->nullable();
            $table->string('direccion')->nullable();
            $table->binary('imagen')->nullable();
            $table->timestamps();
        });
        Schema::create('prod_unidades', function (Blueprint $table): void {
            $table->id();
            $table->string('abreviatura_c', 10);
            $table->string('abreviatura_v', 10);
            $table->integer('cantidad_c');
            $table->integer('cantidad_v');
            $table->timestamps();
            $table->unique(
                ['abreviatura_c', 'abreviatura_v', 'cantidad_c', 'cantidad_v'],
                'uq_prod_unidades_conversion',
            );
        });
        Schema::create('prod_estado', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion', 200)->nullable()->unique();
            $table->timestamps();
        });
        Schema::create('prod_categorias', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->timestamps();
        });
        Schema::create('fam_presentacion', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion', 150)->unique();
            $table->timestamps();
        });
        Schema::create('fam_administracion', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion', 150)->unique();
            $table->timestamps();
        });
        Schema::create('familia', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('presentacion');
            $table->unsignedBigInteger('administracion');
            $table->string('descripcion', 200);
            $table->timestamps();
            $table->unique(
                ['presentacion', 'administracion', 'descripcion'],
                'uq_familia_composicion',
            );
        });
        Schema::create('concentraciones', function (Blueprint $table): void {
            $table->id();
            $table->string('valor', 100)->unique();
            $table->timestamps();
        });
        Schema::create('principal_activos', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->unsignedBigInteger('concentracion');
            $table->timestamps();
            $table->unique(['nombre', 'concentracion'], 'uq_principal_activo');
        });
        Schema::create('productos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('categoria');
            $table->unsignedBigInteger('laboratorio');
            $table->unsignedBigInteger('unidad');
            $table->unsignedBigInteger('familia');
            $table->string('codigo', 100)->unique();
            $table->string('codigobar', 150)->nullable()->unique();
            $table->string('descripcion');
            $table->binary('imagen')->nullable();
            $table->unsignedBigInteger('estado');
            $table->timestamps();
        });
        Schema::create('prod_activo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('producto')->constrained('productos')->restrictOnDelete();
            $table->unsignedBigInteger('pactivo');
            $table->timestamps();
            $table->unique(['producto', 'pactivo'], 'uq_prod_activo');
        });
        Schema::create('compras', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('proveedor');
        });
        Schema::create('compra_detalle', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('compra');
            $table->foreignId('producto')->constrained('productos')->restrictOnDelete();
        });
    }
}
