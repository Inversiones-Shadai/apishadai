<?php

namespace Tests\Feature;

use App\Http\Resources\Farma\PurchaseResource;
use App\Http\Resources\Farma\PurchaseTransactionResource;
use App\Models\Farma\Compra;
use App\Models\Farma\CompraTransaccion;
use App\Models\Proveedores;
use App\Services\Images\ImageStorage;
use App\Services\Images\InvoiceStorage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImageStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'images.enabled' => true, 'images.disk' => 'image-test', 'images.prefix' => 'shadmin/images',
            'images.invoice_prefix' => 'invoices']);
        DB::purge('sqlite');
        Storage::fake('image-test')->buildTemporaryUrlsUsing(
            fn ($path, $expiration) => 'https://storage.test/'.$path.'?expires='.$expiration->timestamp,
        );
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        unset($image);

        return base64_encode($binary);
    }

    public function test_upload_stores_uuid_in_database_and_binary_in_storage(): void
    {
        Schema::create('proveedores', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->text('imagen')->nullable();
            $table->timestamps();
        });
        $provider = Proveedores::create(['nombre' => 'Prueba', 'imagen' => $this->png()]);
        $uuid = DB::table('proveedores')->value('imagen');
        $this->assertTrue(Str::isUuid($uuid));
        $stored = Storage::disk('image-test')->get('shadmin/images/'.$uuid);
        $this->assertSame('image/png', getimagesizefromstring($stored)['mime']);
        $provider->update(['imagen' => $this->png()]);
        $this->assertNotSame($uuid, $provider->fresh()->imagen);
    }

    public function test_failed_upload_does_not_replace_existing_database_reference(): void
    {
        Schema::create('proveedores', function (Blueprint $table): void {
            $table->id();
            $table->string('nombre');
            $table->text('imagen')->nullable();
            $table->timestamps();
        });
        $provider = Proveedores::create(['nombre' => 'Prueba', 'imagen' => $this->png()]);
        $uuid = $provider->imagen;
        Storage::shouldReceive('disk')->with('image-test')->andReturnSelf();
        Storage::shouldReceive('put')->once()->andReturn(false);
        try {
            $provider->update(['imagen' => $this->png()]);
            $this->fail('La carga fallida debe lanzar una excepción.');
        } catch (\RuntimeException) {
            $this->assertSame($uuid, DB::table('proveedores')->value('imagen'));
        }
    }

    public function test_invoices_and_payment_documents_use_the_same_disk_in_the_invoices_directory(): void
    {
        foreach ([Compra::class, CompraTransaccion::class] as $model) {
            Schema::create((new $model)->getTable(), function (Blueprint $table): void {
                $table->id();
                $table->text('img')->nullable();
                $table->timestamps();
            });
            $record = $model::create(['img' => $this->png()]);
            $uuid = $record->fresh()->img;
            $this->assertTrue(Str::isUuid($uuid));
            Storage::disk('image-test')->assertExists('invoices/'.$uuid);
            Storage::disk('image-test')->assertMissing('shadmin/images/'.$uuid);
            $document = app(InvoiceStorage::class)->base64($uuid);
            $this->assertSame('image/png', getimagesizefromstring(base64_decode($document, true))['mime']);
            $this->assertStringStartsWith('https://storage.test/invoices/'.$uuid, app(InvoiceStorage::class)->url($uuid));
        }
    }

    public function test_document_resources_sign_the_invoice_path_and_keep_view_permissions(): void
    {
        $uuid = (string) Str::uuid();
        $purchase = new Compra;
        $purchase->setRawAttributes(['id' => 1, 'img' => $uuid, 'total_aplicado' => 0, 'total' => 100]);
        $transaction = new CompraTransaccion;
        $transaction->setRawAttributes(['id' => 1, 'img' => $uuid]);

        foreach ([[new PurchaseResource($purchase), 'cmpbtn06'], [new PurchaseTransactionResource($transaction), 'ctrbtn06']] as [$resource, $permission]) {
            request()->attributes->set('images.permission.'.$permission, false);
            $this->assertNull($resource->resolve()['documento_url']);
            request()->attributes->set('images.permission.'.$permission, true);
            $this->assertStringStartsWith('https://storage.test/invoices/'.$uuid, $resource->resolve()['documento_url']);
        }
    }

    public function test_failed_document_upload_preserves_the_existing_database_uuid(): void
    {
        Schema::create('compras', function (Blueprint $table): void {
            $table->id();
            $table->text('img')->nullable();
            $table->timestamps();
        });
        $purchase = Compra::create(['img' => $this->png()]);
        $uuid = $purchase->img;
        Storage::shouldReceive('disk')->with('image-test')->andReturnSelf();
        Storage::shouldReceive('put')->once()->andReturn(false);

        try {
            $purchase->update(['img' => $this->png()]);
            $this->fail('La carga fallida debe lanzar una excepción.');
        } catch (\RuntimeException) {
            $this->assertSame($uuid, DB::table('compras')->value('img'));
        }
    }

    public function test_unauthorized_list_does_not_expose_signed_image_url(): void
    {
        $images = app(ImageStorage::class);
        $uuid = (string) Str::uuid();
        $this->assertNull($images->url($uuid, 'prdbtn06'));
        $this->assertStringContainsString($uuid, $images->url($uuid));
        $this->assertNull($images->url($this->png()));
    }

    public function test_cors_configuration_rejects_wildcards_without_contacting_storage(): void
    {
        $this->artisan('images:configure-cors', ['origins' => ['*']])->assertFailed();
        $this->artisan('images:configure-cors', ['origins' => ['https://shadmin.example.com/path']])->assertFailed();
    }

    public function test_migration_is_resumable_and_dry_run_does_not_change_data(): void
    {
        foreach (['productos' => 'imagen', 'proveedores' => 'imagen', 'laboratorios' => 'imagen', 'compras' => 'img', 'compra_transc' => 'img'] as $table => $column) {
            Schema::create($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->id();
                $blueprint->text($column)->nullable();
            });
            DB::table($table)->insert([$column => $this->png()]);
        }
        $old = DB::table('productos')->value('imagen');
        $this->artisan('images:migrate', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($old, DB::table('productos')->value('imagen'));
        $this->assertCount(0, Storage::disk('image-test')->allFiles());
        $this->artisan('images:migrate')->assertSuccessful();
        $uuid = DB::table('productos')->value('imagen');
        $this->assertTrue(Str::isUuid($uuid));
        $this->assertCount(5, Storage::disk('image-test')->allFiles());
        foreach (['compras', 'compra_transc'] as $table) {
            Storage::disk('image-test')->assertExists('invoices/'.DB::table($table)->value('img'));
        }
        $this->artisan('images:migrate')->assertSuccessful();
        $this->assertSame($uuid, DB::table('productos')->value('imagen'));
        $this->assertCount(5, Storage::disk('image-test')->allFiles());
    }

    public function test_documents_only_migration_copies_legacy_uuids_and_preserves_catalog_images(): void
    {
        Schema::create('productos', function (Blueprint $table): void {
            $table->id();
            $table->text('imagen');
        });
        $catalogImage = $this->png();
        DB::table('productos')->insert(['imagen' => $catalogImage]);
        $legacy = [];
        foreach (['compras', 'compra_transc'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
                $table->text('img');
            });
            $legacy[$table] = app(ImageStorage::class)->store($this->png());
            DB::table($table)->insert([['img' => $legacy[$table]], ['img' => $this->png()]]);
        }

        $this->artisan('images:migrate', ['--documents-only' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertCount(2, Storage::disk('image-test')->allFiles());
        $this->artisan('images:migrate', ['--documents-only' => true])->assertSuccessful();
        $this->assertSame($catalogImage, DB::table('productos')->value('imagen'));
        foreach ($legacy as $table => $uuid) {
            $this->assertSame($uuid, DB::table($table)->where('id', 1)->value('img'));
            $this->assertSame(app(ImageStorage::class)->base64($uuid), app(InvoiceStorage::class)->base64($uuid));
            Storage::disk('image-test')->assertExists('shadmin/images/'.$uuid);
            Storage::disk('image-test')->assertExists('invoices/'.DB::table($table)->where('id', 2)->value('img'));
        }
        $this->assertCount(6, Storage::disk('image-test')->allFiles());
        $this->artisan('images:migrate', ['--documents-only' => true])->assertSuccessful();
        $this->assertCount(6, Storage::disk('image-test')->allFiles());
    }
}
