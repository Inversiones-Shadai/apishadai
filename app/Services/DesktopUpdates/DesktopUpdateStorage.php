<?php

namespace App\Services\DesktopUpdates;

use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DesktopUpdateStorage
{
    public function __construct(private ?S3ClientInterface $s3 = null) {}

    public function describe(): array
    {
        $config = config('filesystems.disks.desktop_updates');
        $endpoint = parse_url((string) ($config['endpoint'] ?? ''));
        $bucket = $config['bucket'] ?? '';
        $prefix = config('desktop_updates.prefix');
        $imageBucket = config('filesystems.disks.'.config('images.disk').'.bucket');
        if (($config['driver'] ?? null) !== 's3' || ($config['visibility'] ?? null) !== 'private'
            || ! is_string($bucket) || ! preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket)
            || str_contains($bucket, '..') || filter_var($bucket, FILTER_VALIDATE_IP)
            || $bucket === $imageBucket || ($endpoint['scheme'] ?? '') !== 'https' || empty($endpoint['host'])
            || isset($endpoint['user']) || isset($endpoint['pass']) || isset($endpoint['query']) || isset($endpoint['fragment'])
            || ! is_string($prefix) || ! preg_match('/^[a-z0-9-]+(?:\/[a-z0-9-]+)*$/D', $prefix)
            || ! is_int(config('desktop_updates.url_seconds')) || config('desktop_updates.url_seconds') < 300
            || config('desktop_updates.url_seconds') > 21600
            || ! is_int(config('desktop_updates.max_bytes')) || config('desktop_updates.max_bytes') < 1
            || config('desktop_updates.max_bytes') > 1073741824) {
            throw new InvalidArgumentException('Configuración de actualizaciones inválida: exige HTTPS y un bucket privado separado de imágenes.');
        }

        return ['bucket' => $bucket, 'prefix' => $prefix, 'endpoint_host' => $endpoint['host'],
            'enabled' => config('desktop_updates.enabled') === true, 'url_seconds' => config('desktop_updates.url_seconds')];
    }

    public function client(): S3ClientInterface
    {
        $this->describe();
        if ($this->s3 === null) {
            try {
                $this->s3 = Storage::disk('desktop_updates')->getClient();
            } catch (Throwable) {
                throw new RuntimeException('No se pudo inicializar el almacenamiento privado de actualizaciones.');
            }
        }

        return $this->s3;
    }

    public function call(string $operation, array $parameters): Result
    {
        $info = $this->describe();
        if (($parameters['Bucket'] ?? null) !== $info['bucket']
            || ! in_array($operation, ['HeadBucket', 'CreateBucket', 'GetBucketAcl', 'GetBucketPolicy',
                'HeadObject', 'GetObject', 'PutObject', 'DeleteObject'], true)
            || (isset($parameters['Key']) && (! is_string($parameters['Key'])
                || ! str_starts_with($parameters['Key'], $info['prefix'].'/')
                || ! preg_match('/^[a-zA-Z0-9._\/-]+$/D', $parameters['Key'])
                || preg_match('~(^|/)\.{1,2}(/|$)~', $parameters['Key'])))
            || (in_array($operation, ['PutObject', 'CreateBucket'], true) && ($parameters['ACL'] ?? null) !== 'private')
            || ($operation === 'DeleteObject' && ! preg_match('~^'.preg_quote($info['prefix'], '~').'/probes/[a-f0-9-]{36}/probe\.msix$~D', $parameters['Key'] ?? ''))) {
            throw new InvalidArgumentException('Operación fuera del almacenamiento privado de actualizaciones.');
        }
        try {
            return $this->client()->execute($this->client()->getCommand($operation, $parameters));
        } catch (AwsException $error) {
            throw new DesktopStorageException((string) $error->getAwsErrorCode(), (int) $error->getStatusCode());
        }
    }

    public function inspectBucket(bool $create = false): array
    {
        $info = $this->describe();
        $created = false;
        try {
            $this->call('HeadBucket', ['Bucket' => $info['bucket']]);
        } catch (DesktopStorageException $error) {
            if (! $create || $error->status !== 404) {
                throw $error;
            }
            $this->call('CreateBucket', ['Bucket' => $info['bucket'], 'ACL' => 'private']);
            $created = true;
        }
        $acl = $this->call('GetBucketAcl', ['Bucket' => $info['bucket']]);
        $owner = $acl['Owner']['ID'] ?? null;
        if (! is_string($owner) || $owner === '' || empty($acl['Grants'])) {
            throw new RuntimeException('No se pudo confirmar la propiedad y privacidad del bucket.');
        }
        foreach ($acl['Grants'] as $grant) {
            if (($grant['Grantee']['Type'] ?? '') !== 'CanonicalUser' || ($grant['Grantee']['ID'] ?? null) !== $owner) {
                throw new RuntimeException('El bucket tiene acceso compartido por ACL. Revisar antes de guardar actualizaciones.');
            }
        }
        try {
            $policy = $this->call('GetBucketPolicy', ['Bucket' => $info['bucket']]);
            $document = json_decode((string) $policy['Policy'], true, 32, JSON_THROW_ON_ERROR);
            $statements = $document['Statement'] ?? [];
            $statements = isset($statements['Effect']) ? [$statements] : $statements;
            foreach ($statements as $statement) {
                if (($statement['Effect'] ?? '') !== 'Allow') {
                    continue;
                }
                $principal = $statement['Principal'] ?? '*';
                if (isset($statement['NotPrincipal']) || $principal === '*' || $this->containsWildcard($principal)) {
                    throw new RuntimeException('La política del bucket permite acceso público. No se modificó la política existente.');
                }
            }
        } catch (DesktopStorageException $error) {
            if (! in_array($error->storageCode, ['NoSuchBucketPolicy', 'NoSuchPolicy'], true)) {
                throw $error;
            }
        }

        return $info + ['created' => $created, 'private' => true];
    }

    private function containsWildcard(mixed $value): bool
    {
        if (! is_array($value)) {
            return ! is_string($value) || str_contains($value, '*');
        }
        foreach ($value as $item) {
            if ($this->containsWildcard($item)) {
                return true;
            }
        }

        return false;
    }

    public function key(DesktopReleaseArtifact $artifact, string $objectId): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $objectId)) {
            throw new InvalidArgumentException('Identificador de objeto de actualización inválido.');
        }

        return $this->describe()['prefix']."/releases/$artifact->product/$artifact->version/$artifact->architecture/$artifact->sha256/$objectId.msix";
    }

    public function upload(string $path, DesktopReleaseArtifact $artifact, ?string $reservedObjectId = null): array
    {
        $this->requireEnabled();
        $artifact->assertPackage($path);
        $info = $this->inspectBucket();
        // Cellar returns 200 on a duplicate conditional PUT. Allocate a fresh server-owned ID.
        $objectId = $reservedObjectId ?? bin2hex(random_bytes(32));
        $key = $this->key($artifact, $objectId);
        try {
            $this->call('HeadObject', ['Bucket' => $info['bucket'], 'Key' => $key]);
            if ($reservedObjectId !== null) {
                // A retry may find the immutable object accepted before a lost response.
                // Verify all bytes; never overwrite an existing object to repair it.
                $this->assertStored($artifact, $objectId, true);

                return ['bucket' => $info['bucket'], 'key' => $key, 'object_id' => $objectId,
                    'sha256' => $artifact->sha256, 'size_bytes' => $artifact->size];
            }
            throw new RuntimeException('La dirección generada ya existe; no se reemplazó ningún objeto.');
        } catch (DesktopStorageException $error) {
            if ($error->status !== 404) {
                throw $error;
            }
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('No se pudo abrir el paquete local.');
        }
        try {
            if (! flock($stream, LOCK_SH)) {
                throw new RuntimeException('No se pudo bloquear el paquete para lectura.');
            }
            $this->call('PutObject', ['Bucket' => $info['bucket'], 'Key' => $key, 'Body' => $stream,
                'ACL' => 'private', 'ContentLength' => $artifact->size,
                'ContentMD5' => base64_encode(hash_file('md5', $path, true)), 'ContentType' => 'application/msix',
                'ContentDisposition' => 'attachment; filename="'.$artifact->filename().'"',
                'CacheControl' => 'private, no-store, max-age=0',
                'Metadata' => ['sha256' => $artifact->sha256, 'product' => $artifact->product,
                    'version' => $artifact->version, 'architecture' => $artifact->architecture]]);
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
        $this->assertStored($artifact, $objectId, true);

        return ['bucket' => $info['bucket'], 'key' => $key, 'object_id' => $objectId,
            'sha256' => $artifact->sha256, 'size_bytes' => $artifact->size];
    }

    private function assertStored(DesktopReleaseArtifact $artifact, string $objectId, bool $verifyBytes = false): void
    {
        $head = $this->call('HeadObject', ['Bucket' => $this->describe()['bucket'], 'Key' => $this->key($artifact, $objectId)]);
        if (($head['ContentLength'] ?? null) !== $artifact->size || ($head['Metadata']['sha256'] ?? null) !== $artifact->sha256
            || ($head['ContentType'] ?? null) !== 'application/msix') {
            throw new RuntimeException('El objeto remoto no coincide con los metadatos del release.');
        }
        if ($verifyBytes) {
            $result = $this->call('GetObject', ['Bucket' => $this->describe()['bucket'], 'Key' => $this->key($artifact, $objectId)]);
            $body = $result['Body'];
            $hash = hash_init('sha256');
            $size = 0;
            try {
                while (! $body->eof()) {
                    $chunk = $body->read(1048576);
                    if ($chunk === '') {
                        break;
                    }
                    $size += strlen($chunk);
                    if ($size > $artifact->size) {
                        throw new RuntimeException('Tamaño remoto inesperado.');
                    }
                    hash_update($hash, $chunk);
                }
            } catch (Throwable) {
                throw new RuntimeException('No se pudo verificar el contenido remoto del paquete.');
            } finally {
                $body->close();
            }
            if ($size !== $artifact->size || hash_final($hash) !== $artifact->sha256) {
                throw new RuntimeException('El SHA-256 del contenido remoto no coincide.');
            }
        }
    }

    /** Internal only: verify the artifact registered by the trusted publishing process. */
    public function verifyForCatalog(DesktopReleaseArtifact $artifact, string $objectId): array
    {
        $this->requireEnabled();
        $key = $this->key($artifact, $objectId);
        $info = $this->inspectBucket();
        $this->assertStored($artifact, $objectId, true);

        return ['bucket' => $info['bucket'], 'key' => $key, 'object_id' => $objectId];
    }

    /** Internal only: authorize license/product/device before issuing a download. */
    public function temporaryDownload(DesktopReleaseArtifact $artifact, string $objectId, ?DateTimeImmutable $notAfter = null): array
    {
        $this->requireEnabled();
        $this->inspectBucket();
        $this->assertStored($artifact, $objectId);
        $issuedAt = now('UTC')->timestamp;
        $deadline = min($issuedAt + config('desktop_updates.url_seconds'), $notAfter?->getTimestamp() ?? PHP_INT_MAX);
        if ($deadline <= $issuedAt) {
            throw new RuntimeException('La autorización de descarga venció.');
        }
        $expires = new DateTimeImmutable('@'.$deadline);
        $url = $this->signedUrl('GetObject', $this->key($artifact, $objectId), $expires);

        return ['download_url' => $url, 'expires_at' => $expires->format(DATE_ATOM),
            'expires_in_seconds' => $deadline - $issuedAt];
    }

    public function signedUrl(string $operation, string $key, DateTimeImmutable $expires): string
    {
        if (! in_array($operation, ['GetObject', 'HeadObject'], true)
            || ! str_starts_with($key, $this->describe()['prefix'].'/')
            || ! preg_match('/^[a-zA-Z0-9._\/-]+$/D', $key)
            || preg_match('~(^|/)\.{1,2}(/|$)~', $key)) {
            throw new InvalidArgumentException('Solo se firman lecturas del espacio de actualizaciones.');
        }
        try {
            $command = $this->client()->getCommand($operation, ['Bucket' => $this->describe()['bucket'], 'Key' => $key]);

            return (string) $this->client()->createPresignedRequest($command, $expires)->getUri();
        } catch (Throwable) {
            throw new RuntimeException('No se pudo generar el enlace temporal de lectura.');
        }
    }

    private function requireEnabled(): void
    {
        if (! $this->describe()['enabled']) {
            throw new RuntimeException('El almacenamiento de actualizaciones todavía no está habilitado.');
        }
    }
}
