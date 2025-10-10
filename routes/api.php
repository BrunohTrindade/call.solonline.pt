<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\HealthController;

Route::post('/login', [AuthController::class, 'login']);
// Ajuda: se alguém chamar GET /api/login no navegador, retornamos orientação clara
Route::get('/login', function () {
    return response()->json([
        'message' => 'Use POST /api/login com JSON {"email","password"}. Esta rota não aceita GET.'
    ], 405);
});
Route::get('/health', [HealthController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Listagem de usuários:
    // - Admin: acesso completo com paginação e filtros
    // - Usuário normal/comercial: apenas role=comercial com campos mínimos
    Route::get('/users', [AuthController::class, 'listUsers']);

    // admin only
    Route::middleware('admin')->group(function () {
        // users admin
        Route::post('/users', [AuthController::class, 'createUser']);
        Route::put('/users/{user}', [AuthController::class, 'updateUser']);
    Route::put('/users/{user}/active', [AuthController::class, 'setUserActive']);
        Route::delete('/users/{user}', [AuthController::class, 'deleteUser']);
        // importações só para admin
        Route::post('/contacts/import', [ContactController::class, 'import']);
        Route::post('/contacts/import/background', [ContactController::class, 'importBackground']);
    // visibilidade por contato (admin)
    Route::get('/contacts/{contact}/visibility', [ContactController::class, 'visibilityList']);
        // settings (admin)
        Route::put('/settings/script', [SettingsController::class, 'saveScript']);
    });

    // Atualizar visibilidade: permitido para admin e também para usuário normal
    Route::put('/contacts/{contact}/visibility', [ContactController::class, 'visibilityUpdate']);

    // contacts (rotas públicas autenticadas)
    Route::get('/contacts/stats', [ContactController::class, 'stats']);
    Route::get('/contacts', [ContactController::class, 'index']);
    Route::get('/contacts/{contact}', [ContactController::class, 'show']);
    Route::put('/contacts/{contact}', [ContactController::class, 'update']);
    Route::delete('/contacts/{contact}', [ContactController::class, 'destroy']);

    // settings (leitura para todos autenticados)
    Route::get('/settings/script', [SettingsController::class, 'getScript']);

    // SSE endpoints
    Route::get('/events/contacts', [EventController::class, 'streamContacts']);
    Route::get('/events/import/{jobId}', [EventController::class, 'streamImport']);
});
