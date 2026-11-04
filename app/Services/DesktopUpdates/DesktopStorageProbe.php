<?php

namespace App\Services\DesktopUpdates;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Str;

final class DesktopStorageProbe
{
    public function run(DesktopUpdateStorage $storage): array
    {
        $info = $storage->inspectBucket();
        $key = $info['prefix'].'/probes/'.Str::uuid().'/probe.msix';
        $bytes = "Shadai private storage probe\n".random_bytes(128);
        $parameters = ['Bucket' => $info['bucket'], 'Key' => $key, 'ACL' => 'private', 'IfNoneMatch' => '*',
            'Body' => $bytes, 'ContentLength' => strlen($bytes), 'ContentType' => 'application/msix',
            'ContentMD5' => base64_encode(md5($bytes, true)), 'CacheControl' => 'private, no-store, max-age=0'];
        $http = new Client(['http_errors' => false, 'allow_redirects' => false, 'cookies' => false,
            'connect_timeout' => 10, 'timeout' => 20,
            'verify' => config('filesystems.disks.desktop_updates.http.verify', true)]);
        $created = false;
        $checks = [];
        try {
            $storage->call('PutObject', $parameters);
            $created = true;
            $original = $bytes;
            $replacement = 'must not replace the existing probe';
            try {
                $duplicate = $storage->call('PutObject', array_replace($parameters, ['Body' => $replacement,
                    'ContentLength' => strlen($replacement), 'ContentMD5' => base64_encode(md5($replacement, true))]));
                $checks['conditional_put_http_status'] = $duplicate['@metadata']['statusCode'] ?? 0;
            } catch (DesktopStorageException $error) {
                if ($error->status !== 412) {
                    throw $error;
                }
                $checks['conditional_put_http_status'] = 412;
            }
            $observed = $storage->call('GetObject', ['Bucket' => $info['bucket'], 'Key' => $key]);
            try {
                $bytes = (string) $observed['Body'];
            } finally {
                $observed['Body']->close();
            }
            $this->assert($bytes === $original || $bytes === $replacement, 'Contenido inesperado en la prueba de escritura.');
            $checks['conditional_put_preserves_original'] = $bytes === $original;
            $unsigned = $storage->client()->getObjectUrl($info['bucket'], $key);
            $anonymous = $http->get($unsigned);
            $this->assert(in_array($anonymous->getStatusCode(), [401, 403, 404], true), 'La descarga anónima no fue denegada.');
            $checks['anonymous_read_denied'] = true;
            $anonymousPut = $http->put($unsigned, ['body' => $bytes]);
            $this->assert(in_array($anonymousPut->getStatusCode(), [401, 403, 404], true), 'La escritura anónima no fue denegada.');
            $checks['anonymous_write_denied'] = true;
            $getUrl = $storage->signedUrl('GetObject', $key, new DateTimeImmutable('+5 minutes'));
            $headUrl = $storage->signedUrl('HeadObject', $key, new DateTimeImmutable('+5 minutes'));
            $head = $http->head($headUrl);
            $this->assert($head->getStatusCode() === 200 && (int) $head->getHeaderLine('Content-Length') === strlen($bytes)
                && $head->getHeaderLine('Content-Type') === 'application/msix', 'HEAD, tamaño o MIME incorrectos: '.json_encode([
                    'status' => $head->getStatusCode(), 'length' => (int) $head->getHeaderLine('Content-Length'),
                    'expected_length' => strlen($bytes), 'mime' => substr($head->getHeaderLine('Content-Type'), 0, 100),
                ]));
            $checks['signed_head_and_mime'] = true;
            $get = $http->get($getUrl);
            $this->assert($get->getStatusCode() === 200 && hash('sha256', (string) $get->getBody()) === hash('sha256', $bytes), 'Descarga firmada o SHA-256 incorrectos.');
            $checks['signed_get_sha256'] = true;
            $range = $http->get($getUrl, ['headers' => ['Range' => 'bytes=0-15']]);
            $this->assert($range->getStatusCode() === 206 && (string) $range->getBody() === substr($bytes, 0, 16), 'El servidor no devolvió el rango solicitado.');
            $checks['range_get'] = true;
            $expiring = $storage->signedUrl('GetObject', $key, new DateTimeImmutable('+2 seconds'));
            usleep(4_000_000);
            $expired = $http->get($expiring);
            $this->assert($expired->getStatusCode() === 403, 'El enlace vencido no fue rechazado.');
            $checks['expired_url_denied'] = true;
        } catch (GuzzleException) {
            throw new DesktopStorageProbeException('No se pudo completar la prueba HTTPS del almacenamiento; no se registró la URL firmada.');
        } finally {
            if ($created) {
                // Only this run's randomly named probe, never a release or an image.
                $storage->call('DeleteObject', ['Bucket' => $info['bucket'], 'Key' => $key]);
                $checks['probe_deleted'] = true;
            }
        }

        return ['bucket' => $info['bucket'], 'checks' => $checks,
            'release_write_strategy' => 'new-server-generated-256-bit-object-id-and-sha256'];
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new DesktopStorageProbeException($message);
        }
    }
}
