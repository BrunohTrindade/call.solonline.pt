<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;

Route::get('/', function () {
    return view('welcome');
});

// Trigger de CRON via HTTP (para hospedagens sem SSH). Protegido por token e IP allowlist opcional.
Route::get('/cron/pulse', function () {
    $enabled = filter_var(env('WEB_CRON_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    if (!$enabled) { abort(403, 'Web cron disabled'); }

    $token = Request::query('token');
    $expected = env('WEB_CRON_TOKEN');
    if (!$expected || !hash_equals($expected, (string)$token)) { abort(403, 'Invalid token'); }

    $allowed = trim((string) env('WEB_CRON_ALLOWED_IPS', ''));
    if ($allowed !== '') {
        $ips = array_map('trim', explode(',', $allowed));
        $clientIp = Request::ip();
        if (!in_array($clientIp, $ips, true)) { abort(403, 'IP not allowed'); }
    }

    // Debounce simples: evita chamadas simultâneas em hosts lentos
    $lock = Cache::lock('webcron:pulse', 50); // 50 segundos
    if (!$lock->get()) { return response()->json(['status' => 'busy']); }
    try {
        // Executa tasks agendadas deste minuto
        Artisan::call('schedule:run');
        // Processa fila rápida (imports), encerrando quando vazio
        Artisan::call('queue:work', [
            '--queue' => 'imports',
            '--sleep' => 1,
            '--tries' => 1,
            '--stop-when-empty' => true,
        ]);
    } finally {
        optional($lock)->release();
    }
    return response()->json([
        'status' => 'ok',
        'ts' => now()->toIso8601String(),
        'out_schedule' => Artisan::output(),
    ]);
});
