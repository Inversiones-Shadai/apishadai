<?php

namespace App\Console\Commands;

use App\Services\DesktopUpdates\DesktopStorageException;
use App\Services\DesktopUpdates\DesktopStorageProbe;
use App\Services\DesktopUpdates\DesktopStorageProbeException;
use App\Services\DesktopUpdates\DesktopUpdateStorage;
use Illuminate\Console\Command;
use Throwable;

class PrepareDesktopUpdateStorage extends Command
{
    protected $signature = 'desktop-updates:storage
        {--check : Consultar propiedad, ACL y política sin escribir}
        {--create-bucket : Preparar el bucket privado si no existe}
        {--probe : Verificar lectura, escritura privada, rangos, condición y vencimiento con un objeto sintético}
        {--apply : Autorizar creación y prueba sintética}';

    protected $description = 'Prepara y verifica Cellar para actualizaciones privadas sin modificar el bucket de imágenes';

    public function handle(DesktopUpdateStorage $storage, DesktopStorageProbe $probe): int
    {
        try {
            $info = $storage->describe();
            if (! $this->option('apply')) {
                $this->line(json_encode($this->option('check') ? $storage->inspectBucket() : $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                if ($this->option('create-bucket') || $this->option('probe')) {
                    $this->info('Preparación solamente. Usa --apply para crear el bucket o ejecutar la prueba sintética.');
                }

                return self::SUCCESS;
            }
            $this->line(json_encode($storage->inspectBucket((bool) $this->option('create-bucket')), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if ($this->option('probe')) {
                $this->line(json_encode($probe->run($storage), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            return self::SUCCESS;
        } catch (DesktopStorageProbeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } catch (DesktopStorageException $error) {
            $code = preg_match('/^[A-Za-z0-9]{1,64}$/D', $error->storageCode) ? $error->storageCode : 'StorageError';
            $this->error('Verificación de storage rechazada: HTTP '.$error->status.' ('.$code.').');

            return self::FAILURE;
        } catch (Throwable) {
            // SDK/Guzzle error text can include signed queries. Never render raw exceptions.
            $this->error('No se completó la preparación o verificación. Revisar configuración, privacidad y acceso del servidor. No se modificó el bucket de imágenes.');

            return self::FAILURE;
        }
    }
}
