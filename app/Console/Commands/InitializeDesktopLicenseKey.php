<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InitializeDesktopLicenseKey extends Command
{
    protected $signature = 'licenses:initialize-key {--public= : Destination of the public PEM to embed in desktop builds}';

    protected $description = 'Provision the desktop signing key without replacing an existing private key';

    public function handle(): int
    {
        $path = (string) config('licensing.private_key_path');
        if (! is_file($path)) {
            File::ensureDirectoryExists(dirname($path), 0700);
            $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if (! $key || ! openssl_pkey_export($key, $pem)) {
                $this->error('No se pudo generar la clave de firma. Revisa OpenSSL.');

                return self::FAILURE;
            }
            $handle = fopen($path, 'x');
            if ($handle === false) {
                $this->error('La clave ya existe o no se pudo crear. No se reemplazó.');

                return self::FAILURE;
            }
            try {
                if (fwrite($handle, $pem) !== strlen($pem)) {
                    throw new \RuntimeException('No se pudo guardar la clave de firma completa.');
                }
            } finally {
                fclose($handle);
                unset($pem);
            }
            @chmod($path, 0600);
        }
        $key = openssl_pkey_get_private(file_get_contents($path));
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (! $details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 3072) {
            $this->error('La clave de firma existente no es válida. No se reemplazó.');

            return self::FAILURE;
        }
        if ($destination = $this->option('public')) {
            $parent = realpath(dirname($destination));
            if ($parent === false || strcasecmp($parent.DIRECTORY_SEPARATOR.basename($destination), realpath($path) ?: $path) === 0) {
                $this->error('La ruta pública debe existir y ser distinta de la clave privada.');

                return self::FAILURE;
            }
            File::put($destination, $details['key']);
            $this->info('Clave pública exportada para las aplicaciones. La clave privada permanece solo en la API central.');
        } else {
            $this->info('Firma de licencias preparada. Usa --public para exportar únicamente la clave pública.');
        }

        return self::SUCCESS;
    }
}
