<?php

namespace App\Services\DesktopUpdates;

use DOMDocument;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

final readonly class DesktopReleaseArtifact
{
    private function __construct(
        public string $product,
        public string $version,
        public string $architecture,
        public string $sha256,
        public int $size,
        public string $packageName,
        public string $publisher,
    ) {}

    public static function fromArray(array $metadata): self
    {
        $identity = is_string($metadata['Product'] ?? null)
            ? (config('desktop_updates.products')[$metadata['Product']] ?? null) : null;
        $version = $metadata['Version'] ?? null;
        $validVersion = is_string($version)
            && preg_match('/^[1-9][0-9]{0,4}\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})$/D', $version)
            && max(array_map('intval', explode('.', $version))) <= 65535;
        if (($metadata['SchemaVersion'] ?? null) !== 1 || ! is_array($identity) || ! $validVersion
            || ! in_array($metadata['Architecture'] ?? null, ['x64', 'x86', 'arm64'], true)
            || ! is_string($metadata['Sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $metadata['Sha256'])
            || ! is_int($metadata['SizeBytes'] ?? null) || $metadata['SizeBytes'] <= 0
            || $metadata['SizeBytes'] > config('desktop_updates.max_bytes')
            || ($metadata['PackageName'] ?? null) !== $identity['name']
            || ($metadata['Publisher'] ?? null) !== $identity['publisher']) {
            throw new InvalidArgumentException('Metadatos de release inválidos o producto no permitido.');
        }
        if (($metadata['HasSignature'] ?? null) !== true || ($metadata['DistributionEligible'] ?? null) !== true) {
            throw new InvalidArgumentException('La salida de validación sin firma no se puede publicar como actualización.');
        }

        return new self($metadata['Product'], $version, $metadata['Architecture'], $metadata['Sha256'],
            $metadata['SizeBytes'], $identity['name'], $identity['publisher']);
    }

    public function filename(): string
    {
        return "$this->product-$this->version-$this->architecture.msix";
    }

    public function assertPackage(string $path): void
    {
        if (! is_file($path) || filesize($path) !== $this->size || hash_file('sha256', $path) !== $this->sha256) {
            throw new RuntimeException('El paquete local no coincide con tamaño y SHA-256 del release.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('El archivo no es un paquete MSIX legible.');
        }
        try {
            $stat = $zip->statName('AppxManifest.xml');
            $signature = $zip->statName('AppxSignature.p7x');
            if (! $stat || $stat['size'] > 262144 || ! $signature || $signature['size'] < 1) {
                throw new RuntimeException('Falta manifiesto o firma en el MSIX.');
            }
            $text = $zip->getFromName('AppxManifest.xml');
            if (! is_string($text) || stripos($text, '<!DOCTYPE') !== false) {
                throw new RuntimeException('Manifiesto MSIX inválido.');
            }
            $previous = libxml_use_internal_errors(true);
            try {
                $xml = new DOMDocument;
                if (! $xml->loadXML($text, LIBXML_NONET)) {
                    throw new RuntimeException('Manifiesto MSIX inválido.');
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $identities = $xml->getElementsByTagName('Identity');
            $identity = $identities->item(0);
            if ($identities->length !== 1 || $identity?->getAttribute('Name') !== $this->packageName
                || $identity?->getAttribute('Publisher') !== $this->publisher
                || $identity?->getAttribute('Version') !== $this->version
                || strtolower($identity?->getAttribute('ProcessorArchitecture') ?? '') !== $this->architecture) {
                throw new RuntimeException('El MSIX no pertenece al producto, versión o arquitectura del release.');
            }
        } finally {
            $zip->close();
        }
    }
}
