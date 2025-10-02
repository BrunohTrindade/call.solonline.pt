<?php

$defaultOrigins = 'http://localhost:5173,http://127.0.0.1:5173,http://localhost:3000,http://127.0.0.1:3000,http://localhost:8081,http://127.0.0.1:8081,';
$origins = array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', $defaultOrigins))));
$frontendUrl = env('FRONTEND_URL');
if (!empty($frontendUrl)) {
    $origins[] = rtrim($frontendUrl, '/');
}
$origins = array_values(array_unique($origins));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    // Configure via env:
    // - Produção (domínios): CORS_ALLOWED_ORIGINS="https://app.seudominio.com,https://admin.seudominio.com"
    // - Forma A (portas): backend 8080, frontend 8081
    //   Ex.: CORS_ALLOWED_ORIGINS="http://SEU_IP:8081" (para browser externo)
    //   Para desenvolvimento local já habilitamos 8081 por padrão.
    'allowed_origins' => array_merge($origins, ['http://89.117.58.152:8081']),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
