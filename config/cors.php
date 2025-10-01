<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    // Configure via env:
    // - Produção (domínios): CORS_ALLOWED_ORIGINS="https://app.seudominio.com,https://admin.seudominio.com"
    // - Forma A (portas): backend 8080, frontend 8081
    //   Ex.: CORS_ALLOWED_ORIGINS="http://SEU_IP:8081"
    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173,http://localhost:3000,http://127.0.0.1:3000')))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
