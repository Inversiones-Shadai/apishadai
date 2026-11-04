<?php

return [
    'rate_limits' => [
        'requests_per_minute' => max(1, (int) env('HTTP_RATE_LIMIT_PER_MINUTE', 600)),
        'login_per_minute' => max(1, (int) env('LOGIN_RATE_LIMIT_PER_MINUTE', 5)),
        'login_ip_per_minute' => max(1, (int) env('LOGIN_IP_RATE_LIMIT_PER_MINUTE', 20)),
    ],
    'headers' => [
        'frame_options' => 'DENY',
        'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'content_security_policy' => "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        'hsts_max_age' => max(0, (int) env('HSTS_MAX_AGE', 31536000)),
    ],
];
