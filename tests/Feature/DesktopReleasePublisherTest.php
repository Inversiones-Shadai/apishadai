<?php

namespace Tests\Feature;

use App\Services\DesktopUpdates\DesktopReleasePermissions;
use App\Services\DesktopUpdates\DesktopReleasePublisher;
use App\Services\DesktopUpdates\DesktopUpdateStorage;
use Aws\Command;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class DesktopReleasePublisherTest extends TestCase
{
    private string $root;

    private array $objects = [];

    private int $puts = 0;

    private bool $loseUploadResponse = false;

    private bool $revokeAfterUpload = false;

    private bool $revoked = false;

    private bool $corruptUpload = false;

    private array $actions = ['updbtn02', 'updbtn04', 'updbtn05'];

    private array $request;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('user_estados', function (Blueprint $table): void {
            $table->id();
            $table->string('descripcion');
        });
        DB::table('user_estados')->insert([['id' => 1, 'descripcion' => 'ACTIVO'], ['id' => 2, 'descripcion' => 'INACTIVO']]);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->integer('estado');
        });
        DB::table('users')->insert([['id' => 7, 'estado' => 1], ['id' => 8, 'estado' => 1]]);
        (require database_path('migrations/2026_10_26_154927_create_desktop_licensing_tables.php'))->up();
        (require database_path('migrations/2026_10_28_000001_create_desktop_release_catalog.php'))->up();
        $this->root = storage_path('framework/testing/desktop-publisher-'.Str::uuid());
        mkdir($this->root, 0700, true);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->root));
        config(['desktop_updates.enabled' => true, 'desktop_updates.prefix' => 'desktop-updates', 'desktop_updates.url_seconds' => 600,
            'desktop_updates.max_bytes' => 1073741824, 'desktop_updates.publication_state_directory' => $this->root.'/journal',
            'filesystems.disks.desktop_updates.driver' => 's3', 'filesystems.disks.desktop_updates.bucket' => 'synthetic-updates',
            'filesystems.disks.desktop_updates.endpoint' => 'https://cellar.test', 'filesystems.disks.desktop_updates.visibility' => 'private',
            'images.disk' => 's3', 'filesystems.disks.s3.bucket' => 'synthetic-images']);
        $permissions = Mockery::mock(DesktopReleasePermissions::class);
        $permissions->shouldReceive('allows')->andReturnUsing(fn ($actor, $action) => ! $this->revoked && in_array($action, $this->actions, true));
        $this->app->instance(DesktopReleasePermissions::class, $permissions);
        $s3 = Mockery::mock(S3ClientInterface::class);
        $s3->shouldReceive('getCommand')->andReturnUsing(fn ($operation, $parameters) => new Command($operation, $parameters));
        $s3->shouldReceive('execute')->andReturnUsing(function (CommandInterface $command): Result {
            $parameters = $command->toArray();
            $name = $command->getName();
            if ($name === 'HeadBucket') {
                return new Result;
            }
            if ($name === 'GetBucketAcl') {
                return new Result(['Owner' => ['ID' => 'owner'], 'Grants' => [
                    ['Grantee' => ['Type' => 'CanonicalUser', 'ID' => 'owner'], 'Permission' => 'FULL_CONTROL']]]);
            }
            if ($name === 'GetBucketPolicy') {
                return new Result(['Policy' => '{"Statement":[]}']);
            }
            $key = $parameters['Key'];
            if ($name === 'PutObject') {
                $this->puts++;
                $bytes = stream_get_contents($parameters['Body']);
                $this->objects[$key] = ['bytes' => $this->corruptUpload ? str_repeat('x', strlen($bytes)) : $bytes, 'metadata' => $parameters['Metadata']];
                if ($this->revokeAfterUpload) {
                    $this->revoked = true;
                }
                if ($this->loseUploadResponse) {
                    $this->loseUploadResponse = false;
                    throw new RuntimeException('Synthetic lost upload response');
                }

                return new Result;
            }
            if (! isset($this->objects[$key])) {
                throw new S3Exception('Synthetic missing object', $command, ['code' => 'NoSuchKey', 'response' => new Response(404)]);
            }
            $object = $this->objects[$key];
            if ($name === 'HeadObject') {
                return new Result(['ContentLength' => strlen($object['bytes']), 'ContentType' => 'application/msix', 'Metadata' => $object['metadata']]);
            }
            if ($name === 'GetObject') {
                return new Result(['Body' => Utils::streamFor($object['bytes'])]);
            }
            throw new RuntimeException('Unexpected synthetic S3 operation');
        });
        $this->app->instance(DesktopUpdateStorage::class, new DesktopUpdateStorage($s3));
        $package = $this->root.'/fixture.msix';
        $zip = new ZipArchive;
        $zip->open($package, ZipArchive::CREATE);
        $zip->addFromString('AppxManifest.xml', '<Package><Identity Name="2f329250-d131-4a5a-bbdd-df443499ebcc" Publisher="CN=USER" Version="1.2.0.0" ProcessorArchitecture="x64"/></Package>');
        $zip->addFromString('AppxSignature.p7x', 'Synthetic signature presence; native trust is tested in Windows pipeline');
        $zip->close();
        $metadata = ['SchemaVersion' => 1, 'Product' => 'shpos', 'Version' => '1.2.0.0', 'Architecture' => 'x64',
            'PackageName' => config('desktop_updates.products.shpos.name'), 'Publisher' => 'CN=USER',
            'Sha256' => hash_file('sha256', $package), 'SizeBytes' => filesize($package), 'HasSignature' => true, 'DistributionEligible' => true];
        file_put_contents($this->root.'/release.json', json_encode($metadata));
        $this->request = ['schema_version' => 1, 'request_id' => (string) Str::uuid(), 'metadata' => $this->root.'/release.json',
            'package' => $package, 'target' => 'draft', 'notes' => 'Synthetic publication', 'min_api_contract' => 1,
            'min_schema_version' => 258, 'max_schema_version' => null, 'required' => false, 'pilot_activation_ids' => [], 'source_sha256' => str_repeat('a', 64)];
    }

    private function publish(array $changes = [], int $actor = 7, bool $apply = true): array
    {
        return app(DesktopReleasePublisher::class)->publish(array_replace($this->request, $changes), $actor, $apply);
    }

    private function denied(int $code, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected rejection');
        } catch (HttpException $error) {
            $this->assertSame($code, $error->getStatusCode());
        }
    }

    private function pilot(string $product = 'shpos', bool $enabled = true): string
    {
        $license = (string) Str::uuid();
        $device = (string) Str::uuid();
        DB::table('desktop_licenses')->insert(['id' => $license, 'label' => 'Synthetic', 'product' => $product,
            'key_hash' => bin2hex(random_bytes(32)), 'key_suffix' => 'TESTONLY', 'seats' => 1, 'enabled' => $enabled,
            'expires_at' => now('UTC')->addMonth(), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
        DB::table('desktop_license_devices')->insert(['id' => $device, 'license_id' => $license, 'device_name' => 'Synthetic device',
            'device_hash' => bin2hex(random_bytes(32)), 'key_hash' => bin2hex(random_bytes(32)), 'public_key' => 'synthetic',
            'active_identity' => bin2hex(random_bytes(32)), 'last_validated_at' => now('UTC'), 'reserved_until' => now('UTC')->addDays(3),
            'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

        return $device;
    }

    public function test_preview_never_uploads_or_creates_journal_or_catalog(): void
    {
        $this->assertFalse($this->publish(apply: false)['apply']);
        $this->assertSame(0, $this->puts);
        $this->assertDirectoryDoesNotExist($this->root.'/journal');
        $this->assertDatabaseCount('desktop_releases', 0);
    }

    public function test_draft_retries_preserve_object_artifact_release_and_audit_actor(): void
    {
        $first = $this->publish();
        $this->assertSame($first, $this->publish());
        $this->assertSame('draft', $first['status']);
        $this->assertSame(1, $this->puts);
        $this->assertDatabaseCount('desktop_release_artifacts', 1);
        $this->assertDatabaseCount('desktop_releases', 1);
        $this->assertDatabaseCount('desktop_release_events', 1);
        $this->assertDatabaseHas('desktop_release_events', ['actor_id' => 7, 'action' => 'create']);
    }

    public function test_pilot_uses_existing_product_license_and_independent_permissions(): void
    {
        $device = $this->pilot();
        $changes = ['target' => 'pilot', 'pilot_activation_ids' => [$device]];
        $result = $this->publish($changes);
        $this->assertSame('pilot', $result['status']);
        $this->assertSame(3, $result['revision']);
        $this->assertSame($result, $this->publish($changes));
        $this->assertSame(1, $this->puts);
        $this->assertDatabaseHas('desktop_release_pilot_devices', ['activation_id' => $device]);
        $this->assertDatabaseCount('desktop_release_events', 3);
    }

    public function test_upload_response_loss_recovers_same_reserved_object_without_second_put(): void
    {
        $this->loseUploadResponse = true;
        try {
            $this->publish();
            $this->fail('Expected upload interruption');
        } catch (RuntimeException) {
        }
        $this->assertSame('draft', $this->publish()['status']);
        $this->assertSame(1, $this->puts);
        $this->assertCount(1, $this->objects);
    }

    public function test_permissions_removed_after_upload_block_catalog_and_can_resume_after_restoration(): void
    {
        $this->revokeAfterUpload = true;
        $this->denied(403, fn () => $this->publish());
        $this->assertDatabaseCount('desktop_releases', 0);
        $this->revoked = false;
        $this->revokeAfterUpload = false;
        $this->assertSame('draft', $this->publish()['status']);
        $this->assertSame(1, $this->puts);
    }

    public function test_actor_zero_or_missing_account_cannot_publish(): void
    {
        $this->denied(403, fn () => $this->publish(actor: 0));
        $this->denied(403, fn () => $this->publish(actor: 99));
        $this->assertSame(0, $this->puts);
    }

    public function test_draft_permission_does_not_grant_pilot_promotion(): void
    {
        $this->actions = ['updbtn02'];
        $device = $this->pilot();
        $this->denied(403, fn () => $this->publish(['target' => 'pilot', 'pilot_activation_ids' => [$device]]));
        $this->assertSame(0, $this->puts);
    }

    public function test_disabled_publisher_is_rejected_even_with_permission_rows(): void
    {
        DB::table('users')->where('id', 7)->update(['estado' => 2]);
        $this->denied(403, fn () => $this->publish());
        $this->assertSame(0, $this->puts);
    }

    public function test_wrong_product_or_disabled_license_is_rejected_before_upload(): void
    {
        foreach ([$this->pilot('hq-client'), $this->pilot(enabled: false)] as $device) {
            $this->denied(422, fn () => $this->publish(['target' => 'pilot', 'pilot_activation_ids' => [$device]]));
        }
        $this->assertSame(0, $this->puts);
    }

    public function test_same_uuid_rejects_changed_source_or_different_actor(): void
    {
        $this->publish();
        $this->denied(409, fn () => $this->publish(['source_sha256' => str_repeat('b', 64)]));
        $this->denied(409, fn () => $this->publish(actor: 8));
        $this->assertSame(1, $this->puts);
    }

    public function test_second_job_cannot_republish_cataloged_version(): void
    {
        $this->publish();
        $this->denied(409, fn () => $this->publish(['request_id' => (string) Str::uuid()]));
        $this->assertSame(1, $this->puts);
    }

    public function test_stable_and_unrecognized_fields_are_not_accepted(): void
    {
        foreach ([['target' => 'stable'], ['bucket' => 'untrusted'], ['min_schema_version' => 258, 'max_schema_version' => 1]] as $change) {
            try {
                $this->publish($change);
                $this->fail('Expected invalid input');
            } catch (ValidationException|HttpException $error) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, $this->puts);
    }

    public function test_remote_corrupt_object_is_not_overwritten_on_retry(): void
    {
        $this->corruptUpload = true;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->publish();
                $this->fail('Expected remote verification failure');
            } catch (RuntimeException) {
            }
        }
        $this->assertSame(1, $this->puts);
        $this->assertDatabaseCount('desktop_releases', 0);
    }

    public function test_package_modified_before_publication_never_reaches_storage(): void
    {
        file_put_contents($this->request['package'], 'changed');
        try {
            $this->publish();
            $this->fail('Expected local verification failure');
        } catch (RuntimeException) {
        }
        $this->assertSame(0, $this->puts);
    }

    public function test_artisan_is_preview_by_default_and_requires_actor(): void
    {
        $path = $this->root.'/request.json';
        file_put_contents($path, json_encode($this->request));
        $this->artisan('desktop-updates:publish', ['request' => $path])->assertFailed();
        $this->artisan('desktop-updates:publish', ['request' => $path, '--actor' => 7])->assertSuccessful();
        $this->assertSame(0, $this->puts);
        $this->assertDatabaseCount('desktop_releases', 0);
    }
}
