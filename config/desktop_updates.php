<?php

return [
    'enabled' => (bool) env('DESKTOP_UPDATE_STORAGE_ENABLED', false),
    'api_enabled' => (bool) env('DESKTOP_UPDATE_API_ENABLED', false),
    'requests_per_minute' => 30,
    'authorization_seconds' => 60,
    'disk' => 'desktop_updates',
    'prefix' => env('DESKTOP_UPDATE_PREFIX', 'desktop-updates'),
    'url_seconds' => (int) env('DESKTOP_UPDATE_URL_SECONDS', 3600),
    'max_bytes' => (int) env('DESKTOP_UPDATE_MAX_BYTES', 1073741824),
    'local_pilot' => [
        'thumbprint' => strtoupper(trim((string) env('DESKTOP_UPDATE_LOCAL_PILOT_THUMBPRINT'))),
        'version' => trim((string) env('DESKTOP_UPDATE_LOCAL_PILOT_VERSION')),
        'purpose' => trim((string) env('DESKTOP_UPDATE_LOCAL_PILOT_PURPOSE')),
    ],
    'products' => [
        'shpos' => ['name' => '2f329250-d131-4a5a-bbdd-df443499ebcc', 'publisher' => 'CN=USER'],
        'shpos-administrator' => ['name' => '875d4af2-f4f5-4ded-a521-e7a9a1b64e10', 'publisher' => 'CN=USER'],
        'hq-client' => ['name' => 'fdacf034-7f58-4b35-9cc7-5102c9c2ff7d', 'publisher' => 'CN=USER'],
    ],
];
