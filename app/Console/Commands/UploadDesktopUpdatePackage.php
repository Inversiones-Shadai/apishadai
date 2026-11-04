<?php

namespace App\Console\Commands;

use App\Services\DesktopUpdates\DesktopReleaseArtifact;
use App\Services\DesktopUpdates\DesktopUpdateStorage;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class UploadDesktopUpdatePackage extends Command
{
    protected $signature = 'desktop-updates:upload {metadata : Archivo release.json del proceso de empaquetado}
        {package : Archivo MSIX firmado correspondiente} {--apply : Subir el paquete privado sin sobrescribir}';

    protected $description = 'Verifica y carga un paquete MSIX aprobado; no publica una versión ni asigna licencias';

    public function handle(DesktopUpdateStorage $storage): int
    {
        try {
            $path = $this->argument('metadata');
            if (! is_file($path) || filesize($path) > 65536) {
                throw new RuntimeException('Metadatos no disponibles.');
            }
            $metadata = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            $artifact = DesktopReleaseArtifact::fromArray($metadata);
            $artifact->assertPackage($this->argument('package'));
            if (! $this->option('apply')) {
                $this->line(json_encode(['product' => $artifact->product, 'version' => $artifact->version,
                    'key_template' => $storage->describe()['prefix']."/releases/$artifact->product/$artifact->version/$artifact->architecture/$artifact->sha256/{new-object-id}.msix",
                    'size_bytes' => $artifact->size, 'apply' => false], JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }
            $this->line(json_encode($storage->upload($this->argument('package'), $artifact), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Carga rechazada: comprobar que el release esté habilitado para distribución, firmado, íntegro y que el storage esté disponible. No se registran claves ni enlaces.');

            return self::FAILURE;
        }
    }
}
