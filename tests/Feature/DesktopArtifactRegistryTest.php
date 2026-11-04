<?php

namespace Tests\Feature;

use App\Services\DesktopUpdates\DesktopArtifactRegistry;
use App\Services\DesktopUpdates\DesktopReleaseArtifact;
use App\Services\DesktopUpdates\DesktopUpdateStorage;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DesktopArtifactRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'desktop_updates.enabled' => true, 'desktop_updates.prefix' => 'desktop-updates', 'desktop_updates.url_seconds' => 600,
            'filesystems.disks.desktop_updates.driver' => 's3', 'filesystems.disks.desktop_updates.bucket' => 'synthetic-updates',
            'filesystems.disks.desktop_updates.visibility' => 'private', 'filesystems.disks.desktop_updates.endpoint' => 'https://cellar.test']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_10_26_154927_create_desktop_licensing_tables.php'))->up();
        (require database_path('migrations/2026_10_28_000001_create_desktop_release_catalog.php'))->up();
    }

    private function artifact(string $body): DesktopReleaseArtifact
    {
        return DesktopReleaseArtifact::fromArray(['SchemaVersion' => 1, 'Product' => 'shpos', 'Version' => '1.0.1.0',
            'Architecture' => 'x64', 'Sha256' => hash('sha256', $body), 'SizeBytes' => strlen($body),
            'PackageName' => '2f329250-d131-4a5a-bbdd-df443499ebcc', 'Publisher' => 'CN=USER',
            'HasSignature' => true, 'DistributionEligible' => true]);
    }

    private function results(string $expected, string $remote): array
    {
        return [new Result, new Result(['Owner' => ['ID' => 'owner'], 'Grants' => [
            ['Grantee' => ['Type' => 'CanonicalUser', 'ID' => 'owner'], 'Permission' => 'FULL_CONTROL'],
        ]]), new Result(['Policy' => '{"Statement":[]}']),
            new Result(['ContentLength' => strlen($expected), 'ContentType' => 'application/msix', 'Metadata' => ['sha256' => hash('sha256', $expected)]]),
            new Result(['Body' => Utils::streamFor($remote)])];
    }

    private function storage(array $results): void
    {
        $results = array_map(function (Result $result): callable {
            return function () use ($result): Result {
                $this->assertSame(0, DB::transactionLevel(), 'La verificación remota no debe mantener bloqueos SQL.');

                return $result;
            };
        }, $results);
        $client = new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'https://cellar.test',
            'credentials' => ['key' => 'synthetic-key', 'secret' => 'synthetic-secret'],
            'handler' => new MockHandler($results), 'retries' => 0]);
        $this->app->instance(DesktopUpdateStorage::class, new DesktopUpdateStorage($client));
    }

    public function test_registration_checks_bytes_and_is_idempotent_for_same_stored_object(): void
    {
        $body = 'synthetic package bytes';
        $this->storage([...$this->results($body, $body), ...$this->results($body, $body)]);
        $artifact = $this->artifact($body);
        $registry = app(DesktopArtifactRegistry::class);
        $first = $registry->register($artifact, str_repeat('a', 64));
        $this->assertSame($first, $registry->register($artifact, str_repeat('a', 64)));
        $this->assertDatabaseCount('desktop_release_artifacts', 1);
        $this->assertDatabaseCount('desktop_releases', 0);
        $record = DB::table('desktop_release_artifacts')->first();
        $this->assertStringEndsWith('/'.str_repeat('a', 64).'.msix', $record->object_key);
        $this->assertStringNotContainsString('download_url', $record->metadata);
    }

    public function test_corrupted_remote_body_does_not_register_artifact(): void
    {
        $this->storage($this->results('expected', 'corrupt'));
        try {
            app(DesktopArtifactRegistry::class)->register($this->artifact('expected'), str_repeat('b', 64));
            $this->fail('No debe aceptar el objeto corrupto.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('desktop_release_artifacts', 0);
        }
    }
}
