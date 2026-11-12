<?php

use Illuminate\Support\Facades\DB;

function prcDirectory(?string $argument): string
{
    $directory = $argument ?? config('prc.directory');
    if (! is_string($directory) || trim($directory) === '') {
        throw new RuntimeException('Indica el directorio de trabajo o configura PRC_IMPORT_DIRECTORY en .env.');
    }

    if ($argument === null && ! preg_match('/^[A-Za-z]:/', $directory)
        && ! str_starts_with($directory, '/') && ! str_starts_with($directory, '\\')) {
        $directory = base_path($directory);
    }

    $path = realpath($directory);
    if ($path === false || ! is_file($path.'/plan.json')) {
        throw new RuntimeException('El directorio de trabajo debe contener plan.json.');
    }

    return $path;
}

function assertLocalPrcDatabase(): void
{
    $expected = config('prc.database');
    $connection = DB::connection();
    if (! is_string($expected) || $expected === ''
        || ! app()->environment('local')
        || $connection->getDriverName() !== 'mysql'
        || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)
        || $connection->getDatabaseName() !== $expected) {
        throw new RuntimeException('La conexión debe coincidir con la base MySQL local indicada en PRC_IMPORT_DATABASE.');
    }
}
