<?php

namespace App\Models\Concerns;

use App\Services\Images\ImageStorage;
use App\Services\Images\InvoiceStorage;

trait StoresImageUuid
{
    public function setImagenAttribute(?string $value): void
    {
        $this->attributes['imagen'] = app(ImageStorage::class)->store($value);
    }

    public function setImgAttribute(?string $value): void
    {
        $this->attributes['img'] = app(InvoiceStorage::class)->store($value);
    }
}
