<?php

namespace App\Services\DesktopUpdates;

use RuntimeException;

final class DesktopStorageException extends RuntimeException
{
    public function __construct(public readonly string $storageCode, public readonly int $status)
    {
        // Do not retain SDK exceptions: their requests can contain credentials or signed URLs.
        parent::__construct('No se pudo completar la operación de almacenamiento (HTTP '.$status.').');
    }
}
