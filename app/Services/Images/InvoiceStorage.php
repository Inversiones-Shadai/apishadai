<?php

namespace App\Services\Images;

class InvoiceStorage extends ImageStorage
{
    protected function prefix(): string
    {
        return trim(config('images.invoice_prefix'), '/');
    }
}
