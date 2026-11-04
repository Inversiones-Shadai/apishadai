<?php

namespace App\Console\Commands;

use App\Services\DesktopUpdates\DesktopReleasePublisher;
use Illuminate\Console\Command;
use Throwable;

class PublishDesktopRelease extends Command
{
    protected $signature = 'desktop-updates:publish {request : JSON emitido por el pipeline Windows}
        {--actor= : ID de la cuenta autorizada; nunca asigna permisos} {--apply : Cargar y crear borrador o piloto}';

    protected $description = 'Publicación local reanudable con permisos vigentes, verificación remota y auditoría; nunca promueve a estable';

    public function handle(DesktopReleasePublisher $publisher): int
    {
        try {
            $path = $this->argument('request');
            if (! is_file($path) || filesize($path) > 65536 || ! preg_match('/^[1-9][0-9]*$/D', (string) $this->option('actor'))) {
                $this->error('Se necesita solicitud JSON válida y cuenta de publicación explícita.');

                return self::FAILURE;
            }
            $input = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            $this->line(json_encode($publisher->publish($input, (int) $this->option('actor'), (bool) $this->option('apply')), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $code = method_exists($error, 'getStatusCode') ? $error->getStatusCode() : 0;
            $this->error('Publicación detenida'.($code ? ' ('.$code.')' : '').'. Revisa permisos, paquete y diario; reintenta con el mismo UUID y contenido. No se muestran secretos ni enlaces.');

            return self::FAILURE;
        }
    }
}
