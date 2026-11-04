<?php

namespace Tests\Feature;

use App\Services\DesktopUpdates\DesktopReleaseArtifact;
use App\Services\DesktopUpdates\DesktopStorageException;
use App\Services\DesktopUpdates\DesktopUpdateStorage;
use Aws\Command;
use Aws\CommandInterface;
use Aws\Middleware;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class DesktopUpdateStorageTest extends TestCase
{
    private array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['desktop_updates.enabled' => true, 'desktop_updates.prefix' => 'desktop-updates',
            'desktop_updates.url_seconds' => 600, 'desktop_updates.max_bytes' => 1073741824,
            'filesystems.disks.desktop_updates.driver' => 's3',
            'filesystems.disks.desktop_updates.bucket' => 'test-shadai-updates',
            'filesystems.disks.desktop_updates.endpoint' => 'https://cellar.test',
            'filesystems.disks.desktop_updates.visibility' => 'private',
            'images.disk' => 's3', 'filesystems.disks.s3.bucket' => 'test-images']);
    }

    private function storage(array $results = []): DesktopUpdateStorage
    {
        $client = new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'https://cellar.test',
            'credentials' => ['key' => 'synthetic-key', 'secret' => 'synthetic-secret'],
            'use_path_style_endpoint' => true, 'handler' => new MockHandler($results), 'retries' => 0,
            'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required']);
        $client->getHandlerList()->appendInit(Middleware::tap(function (CommandInterface $command): void {
            $this->commands[] = ['name' => $command->getName(), 'parameters' => $command->toArray()];
        }), 'test-capture');

        return new DesktopUpdateStorage($client);
    }

    private function privateBucket(): array
    {
        return [new Result, new Result(['Owner' => ['ID' => 'owner'], 'Grants' => [
            ['Grantee' => ['Type' => 'CanonicalUser', 'ID' => 'owner'], 'Permission' => 'FULL_CONTROL'],
        ]]), new Result(['Policy' => '{"Version":"2012-10-17","Statement":[]}'])];
    }

    private function metadata(array $overrides = []): array
    {
        return array_replace(['SchemaVersion' => 1, 'Product' => 'shpos', 'Version' => '1.0.1.0',
            'Architecture' => 'x64', 'Sha256' => str_repeat('a', 64), 'SizeBytes' => 100,
            'PackageName' => '2f329250-d131-4a5a-bbdd-df443499ebcc', 'Publisher' => 'CN=USER',
            'HasSignature' => true, 'DistributionEligible' => true], $overrides);
    }

    private function package(bool $withSignature = true): array
    {
        $directory = storage_path('framework/testing/desktop-updates');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/'.bin2hex(random_bytes(16)).'.msix';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('AppxManifest.xml', '<Package><Identity Name="2f329250-d131-4a5a-bbdd-df443499ebcc" Publisher="CN=USER" Version="1.0.1.0" ProcessorArchitecture="x64"/></Package>');
        if ($withSignature) {
            $zip->addFromString('AppxSignature.p7x', 'synthetic-signature-presence-only');
        }
        $zip->close();
        $this->beforeApplicationDestroyed(fn () => is_file($path) ? unlink($path) : null);
        $metadata = $this->metadata(['Sha256' => hash_file('sha256', $path), 'SizeBytes' => filesize($path)]);

        return [$path, DesktopReleaseArtifact::fromArray($metadata), $metadata];
    }

    public static function invalidMetadata(): array
    {
        return [
            'unsigned' => [['HasSignature' => false]], 'not-distributable' => [['DistributionEligible' => false]],
            'wrong-product' => [['Product' => 'other']], 'wrong-type' => [['Product' => []]],
            'wrong-name' => [['PackageName' => 'other']], 'wrong-publisher' => [['Publisher' => 'CN=Other']],
            'wrong-architecture' => [['Architecture' => '../images']], 'version-suffix' => [['Version' => '1.0.0-beta']],
            'version-range' => [['Version' => '65536.0.0.0']], 'version-zero' => [['Version' => '0.1.0.0']],
            'version-padding' => [['Version' => '1.00.0.0']], 'size-type' => [['SizeBytes' => '100']],
            'oversized' => [['SizeBytes' => 1073741825]], 'hash' => [['Sha256' => '../../key']],
        ];
    }

    #[DataProvider('invalidMetadata')]
    public function test_rejects_unapproved_or_invalid_release(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);
        DesktopReleaseArtifact::fromArray($this->metadata($overrides));
    }

    public function test_keys_are_immutable_and_scoped_to_product_version_architecture_and_hash(): void
    {
        $artifact = DesktopReleaseArtifact::fromArray($this->metadata());
        $this->assertSame('desktop-updates/releases/shpos/1.0.1.0/x64/'.str_repeat('a', 64).'/'.str_repeat('b', 64).'.msix', $this->storage()->key($artifact, str_repeat('b', 64)));
        $this->assertSame('shpos-1.0.1.0-x64.msix', $artifact->filename());
    }

    public function test_rejects_image_bucket_and_insecure_endpoint_before_network(): void
    {
        config(['filesystems.disks.desktop_updates.bucket' => 'test-images']);
        try {
            $this->storage()->describe();
            $this->fail('No se permite usar el bucket de imágenes.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], $this->commands);
        }
        config(['filesystems.disks.desktop_updates.bucket' => 'test-updates',
            'filesystems.disks.desktop_updates.endpoint' => 'http://cellar.test']);
        $this->expectException(InvalidArgumentException::class);
        $this->storage()->describe();
    }

    public function test_private_bucket_inspection_never_changes_policy_or_cors(): void
    {
        $this->assertTrue($this->storage($this->privateBucket())->inspectBucket()['private']);
        $this->assertSame(['HeadBucket', 'GetBucketAcl', 'GetBucketPolicy'], array_column($this->commands, 'name'));
        foreach ($this->commands as $command) {
            $this->assertSame('test-shadai-updates', $command['parameters']['Bucket']);
        }
    }

    public function test_rejects_public_bucket_acl(): void
    {
        $results = $this->privateBucket();
        $results[1] = new Result(['Owner' => ['ID' => 'owner'], 'Grants' => [
            ['Grantee' => ['Type' => 'Group', 'URI' => 'http://acs.amazonaws.com/groups/global/AllUsers'], 'Permission' => 'READ'],
        ]]);
        $this->expectException(RuntimeException::class);
        $this->storage($results)->inspectBucket();
    }

    public function test_rejects_public_bucket_policy(): void
    {
        $results = $this->privateBucket();
        $results[2] = new Result(['Policy' => '{"Statement":[{"Effect":"Allow","Principal":{"AWS":["*"]},"Action":"s3:GetObject"}]}']);
        $this->expectException(RuntimeException::class);
        $this->storage($results)->inspectBucket();
    }

    public function test_creates_only_missing_bucket_with_private_acl(): void
    {
        $missing = new S3Exception('synthetic', new Command('HeadBucket'), ['code' => 'NoSuchBucket', 'response' => new Response(404)]);
        $private = $this->privateBucket();
        $storage = $this->storage([$missing, new Result, $private[1], $private[2]]);
        $this->assertTrue($storage->inspectBucket(true)['created']);
        $this->assertSame('CreateBucket', $this->commands[1]['name']);
        $this->assertSame('private', $this->commands[1]['parameters']['ACL']);
    }

    public function test_unknown_or_forbidden_bucket_does_not_trigger_creation(): void
    {
        $error = new S3Exception('signed-url-secret', new Command('HeadBucket'), ['code' => 'AccessDenied', 'response' => new Response(403)]);
        try {
            $this->storage([$error])->inspectBucket(true);
            $this->fail('Debe rechazar acceso ambiguo.');
        } catch (DesktopStorageException $caught) {
            $this->assertSame(403, $caught->status);
            $this->assertStringNotContainsString('signed-url-secret', $caught->getMessage());
            $this->assertNull($caught->getPrevious());
            $this->assertCount(1, $this->commands);
        }
    }

    public function test_private_upload_uses_new_location_and_verifies_remote_bytes(): void
    {
        [$path, $artifact] = $this->package();
        $missing = new S3Exception('missing', new Command('HeadObject'), ['code' => 'NotFound', 'response' => new Response(404)]);
        $results = [...$this->privateBucket(), $missing, new Result,
            new Result(['ContentLength' => $artifact->size, 'ContentType' => 'application/msix', 'Metadata' => ['sha256' => $artifact->sha256]]),
            new Result(['Body' => Utils::streamFor(file_get_contents($path))])];
        $result = $this->storage($results)->upload($path, $artifact);
        $put = $this->commands[4]['parameters'];
        $this->assertArrayNotHasKey('IfNoneMatch', $put);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['object_id']);
        $this->assertStringEndsWith('/'.$result['object_id'].'.msix', $result['key']);
        $this->assertSame('private', $put['ACL']);
        $this->assertSame('application/msix', $put['ContentType']);
        $this->assertSame(base64_encode(hash_file('md5', $path, true)), $put['ContentMD5']);
        $this->assertSame($artifact->sha256, $result['sha256']);
    }

    public function test_existing_generated_location_is_never_overwritten(): void
    {
        [$path, $artifact] = $this->package();
        try {
            $this->storage([...$this->privateBucket(), new Result])->upload($path, $artifact);
            $this->fail('Debe rechazar el duplicado.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('ya existe', $error->getMessage());
            $this->assertSame(['HeadBucket', 'GetBucketAcl', 'GetBucketPolicy', 'HeadObject'], array_column($this->commands, 'name'));
        }
    }

    public function test_repeated_upload_allocates_another_object_without_replacing_first(): void
    {
        [$path, $artifact] = $this->package();
        $results = [];
        for ($i = 0; $i < 2; $i++) {
            $missing = new S3Exception('missing', new Command('HeadObject'), ['code' => 'NotFound', 'response' => new Response(404)]);
            $results = [...$results, ...$this->privateBucket(), $missing, new Result,
                new Result(['ContentLength' => $artifact->size, 'ContentType' => 'application/msix', 'Metadata' => ['sha256' => $artifact->sha256]]),
                new Result(['Body' => Utils::streamFor(file_get_contents($path))])];
        }
        $storage = $this->storage($results);
        $first = $storage->upload($path, $artifact);
        $second = $storage->upload($path, $artifact);
        $this->assertNotSame($first['object_id'], $second['object_id']);
        $this->assertNotSame($first['key'], $second['key']);
        $this->assertSame($first['sha256'], $second['sha256']);
    }

    public function test_corrupted_remote_bytes_are_rejected_even_if_head_metadata_matches(): void
    {
        [$path, $artifact] = $this->package();
        $missing = new S3Exception('missing', new Command('HeadObject'), ['code' => 'NotFound', 'response' => new Response(404)]);
        $storage = $this->storage([...$this->privateBucket(), $missing, new Result,
            new Result(['ContentLength' => $artifact->size, 'ContentType' => 'application/msix', 'Metadata' => ['sha256' => $artifact->sha256]]),
            new Result(['Body' => Utils::streamFor('corrupted')])]);
        $this->expectException(RuntimeException::class);
        $storage->upload($path, $artifact);
    }

    public function test_gateway_does_not_allow_image_bucket_public_write_or_release_deletion(): void
    {
        foreach ([['PutObject', ['Bucket' => 'test-images', 'Key' => 'desktop-updates/probes/test', 'ACL' => 'private']],
            ['CreateBucket', ['Bucket' => 'test-shadai-updates', 'ACL' => 'public-read']],
            ['PutObject', ['Bucket' => 'test-shadai-updates', 'Key' => 'desktop-updates/releases/test', 'ACL' => 'public-read']],
            ['DeleteObject', ['Bucket' => 'test-shadai-updates', 'Key' => 'desktop-updates/releases/test']]] as [$operation, $parameters]) {
            try {
                $this->storage()->call($operation, $parameters);
                $this->fail('Operación fuera de alcance.');
            } catch (InvalidArgumentException) {
                $this->assertSame([], $this->commands);
            }
        }
    }

    public function test_temporary_download_is_signed_get_with_finite_expiry(): void
    {
        $artifact = DesktopReleaseArtifact::fromArray($this->metadata());
        $result = $this->storage([...$this->privateBucket(), new Result(['ContentLength' => 100,
            'ContentType' => 'application/msix', 'Metadata' => ['sha256' => $artifact->sha256]])])->temporaryDownload($artifact, str_repeat('b', 64));
        parse_str(parse_url($result['download_url'], PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('X-Amz-Signature', $query);
        $this->assertLessThanOrEqual(600, (int) $query['X-Amz-Expires']);
        $this->assertSame(600, $result['expires_in_seconds']);
        $this->assertStringNotContainsString('synthetic-secret', $result['download_url']);
    }

    public function test_signer_rejects_write_and_paths_outside_namespace(): void
    {
        foreach ([['PutObject', 'desktop-updates/probes/test'], ['GetObject', 'images/test'], ['GetObject', 'desktop-updates/../test']] as [$operation, $key]) {
            try {
                $this->storage()->signedUrl($operation, $key, new DateTimeImmutable('+1 hour'));
                $this->fail('Firma fuera de alcance.');
            } catch (InvalidArgumentException) {
                $this->assertSame([], $this->commands);
            }
        }
    }

    public function test_temporary_download_is_capped_at_license_expiration(): void
    {
        $this->travelTo(now('UTC')->startOfSecond());
        $deadline = now('UTC')->addSeconds(20)->toDateTimeImmutable();
        $artifact = DesktopReleaseArtifact::fromArray($this->metadata());
        $result = $this->storage([...$this->privateBucket(), new Result(['ContentLength' => 100,
            'ContentType' => 'application/msix', 'Metadata' => ['sha256' => $artifact->sha256]])])
            ->temporaryDownload($artifact, str_repeat('b', 64), $deadline);
        $this->assertSame(20, $result['expires_in_seconds']);
        $this->assertSame($deadline->getTimestamp(), (new DateTimeImmutable($result['expires_at']))->getTimestamp());
        parse_str(parse_url($result['download_url'], PHP_URL_QUERY), $query);
        $this->assertLessThanOrEqual(20, (int) $query['X-Amz-Expires']);
    }

    public function test_disabled_storage_does_not_issue_download_authorization(): void
    {
        config(['desktop_updates.enabled' => false]);
        $this->expectException(RuntimeException::class);
        $this->storage()->temporaryDownload(DesktopReleaseArtifact::fromArray($this->metadata()), str_repeat('b', 64));
    }

    public function test_missing_signature_is_rejected_before_upload(): void
    {
        [$path, $artifact] = $this->package(false);
        $this->expectException(RuntimeException::class);
        $this->storage()->upload($path, $artifact);
    }

    public function test_upload_dry_run_checks_package_without_network(): void
    {
        [$path, $artifact, $metadata] = $this->package();
        $metadataPath = $path.'.json';
        file_put_contents($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));
        $this->beforeApplicationDestroyed(fn () => is_file($metadataPath) ? unlink($metadataPath) : null);
        $this->app->instance(DesktopUpdateStorage::class, $this->storage());
        $this->artisan('desktop-updates:upload', ['metadata' => $metadataPath, 'package' => $path])->assertSuccessful();
        $this->assertSame([], $this->commands);
    }

    public function test_prepare_dry_run_does_not_contact_storage(): void
    {
        $this->app->instance(DesktopUpdateStorage::class, $this->storage());
        $this->artisan('desktop-updates:storage', ['--create-bucket' => true, '--probe' => true])->assertSuccessful();
        $this->assertSame([], $this->commands);
    }
}
