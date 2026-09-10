<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://127.0.0.1:3000'))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    // Wajib: tanpa ini browser menyembunyikan Content-Disposition dari JavaScript
    // pada respons lintas origin, sehingga nama file unduhan tidak terbaca.
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 0,
    'supports_credentials' => false,
];
