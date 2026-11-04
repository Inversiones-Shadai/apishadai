<?php

return [
    'private_key_path' => env('DESKTOP_LICENSE_PRIVATE_KEY_PATH', storage_path('app/private/desktop-license-signing.pem')),
    'term_months' => [1, 6, 12],
    'products' => ['hq-client', 'shpos', 'shpos-administrator'],
];
