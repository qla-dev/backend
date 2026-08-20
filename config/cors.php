<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('FRONTEND_URLS', 'http://localhost:3000,http://127.0.0.1:3000,https://freightbook.ai'))))),
    'allowed_origins_patterns' => ['#^https://freightbook\.ai$#'],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
