<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $dbStatus = 'down';
        try {
            // Consulta simples para verificar conexão sem custo alto
            DB::select('SELECT 1');
            $dbStatus = 'up';
        } catch (\Throwable $e) {
            $dbStatus = 'down';
        }

        return response()->json([
            'status' => 'ok',
            'db' => $dbStatus,
            'time' => now()->toIso8601String(),
        ]);
    }
}
