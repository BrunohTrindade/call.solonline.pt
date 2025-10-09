<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $isAdmin = $user && ((bool)($user->is_admin ?? false) || ($user->role ?? '') === 'admin');
        if (!$isAdmin) {
            return response()->json(['message' => 'Apenas administradores'], 403);
        }
        return $next($request);
    }
}
