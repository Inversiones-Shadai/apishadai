<?php

namespace App\Console\Commands;

use App\Services\DesktopUpdates\DesktopArtifactRegistry;
use App\Services\DesktopUpdates\DesktopReleaseArtifact;
use Illuminate\Console\Command;
use Throwable;

class RegisterDesktopReleaseArtifact extends Command
{
    protected $signature = 'desktop-updates:register-artifact {metadata : Release JSON del empaquetado confiable}
        {object-id : Identificador devuelto al cargar a Cellar} {--apply : Verificar y registrar el paquete para el catálogo}';

    protected $description = 'Registra un paquete privado verificado para crear borradores; no publica una versión';

    public function handle(DesktopArtifactRegistry $registry): int
    {
        try {
            $path = $this->argument('metadata');
            if (! is_file($path) || filesize($path) > 65536) {
                $this->fail('Metadatos de paquete inválidos.');
            }
            $artifact = DesktopReleaseArtifact::fromArray(json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR));
            if (! preg_match('/^[a-f0-9]{64}$/D', $this->argument('object-id'))) {
                $this->fail('Identificador de objeto inválido.');
            }
            if (! $this->option('apply')) {
                $this->info('Preparado para verificar '.$artifact->product.' '.$artifact->version.'. Usa --apply para registrar.');

                return self::SUCCESS;
            }
            $this->line(json_encode($registry->register($artifact, $this->argument('object-id')), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('No se pudo registrar el paquete. Verificar firma, metadatos, objeto privado y esquema del catálogo.');

            return self::FAILURE;
        }
    }
}
