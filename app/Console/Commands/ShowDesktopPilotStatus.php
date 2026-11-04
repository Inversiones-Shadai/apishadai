<?php

namespace App\Console\Commands;

use App\Services\DesktopUpdates\DesktopPilotStatus;
use Illuminate\Console\Command;
use Throwable;

class ShowDesktopPilotStatus extends Command
{
    protected $signature = 'desktop-updates:pilot-status {--product= : Producto; vacío incluye los tres}
        {--architecture=x64 : x64, x86 o arm64} {--pending-minutes=30 : Umbral de informe atrasado, no tiempo de instalación}
        {--devices : Incluir UUID de activación y último resultado; tratar la salida como privada}';

    protected $description = 'Diagnóstico de solo lectura para operadores con acceso a la consola del servidor; nunca modifica permisos ni canales';

    public function handle(DesktopPilotStatus $status): int
    {
        try {
            $minutes = (string) $this->option('pending-minutes');
            if (! preg_match('/^[1-9][0-9]{0,4}$/D', $minutes)) {
                $this->error('Indica un umbral entre 1 y 10080 minutos.');

                return self::FAILURE;
            }
            $this->line(json_encode($status->snapshot($this->option('product') ?: null,
                (string) $this->option('architecture'), (int) $minutes, (bool) $this->option('devices')), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('No se pudo consultar el piloto. Revisa opciones, conexión y tablas de actualizaciones del entorno. No se muestran datos de conexión.');

            return self::FAILURE;
        }
    }
}
