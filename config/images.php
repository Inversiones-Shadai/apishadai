<?php

return [
    'enabled' => env('IMAGE_STORAGE_ENABLED', false),
    'disk' => env('IMAGE_STORAGE_DISK', 's3'),
    'prefix' => trim(env('IMAGE_STORAGE_PREFIX', 'shadmin/images'), '/'),
    'invoice_prefix' => trim(env('INVOICE_STORAGE_PREFIX', 'invoices'), '/'),
    'url_minutes' => (int) env('IMAGE_STORAGE_URL_MINUTES', 720),
];
