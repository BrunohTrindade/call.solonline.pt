<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    // Lista usuários (admin) com paginação simples
    public function listUsers(Request $request)
    {
        // Aceita per_page | perPage | limit e page (consistente com contatos)
        $perPage = $request->integer('per_page')
            ?? $request->integer('perPage')
            ?? $request->integer('limit')
            ?? 15;
        $perPage = max(1, min(100, (int) $perPage));
        $page = max(1, (int) $request->query('page', 1));

        $query = User::query()
            ->orderBy('id', 'asc')
            ->select(['id','name','email','is_admin','created_at','updated_at']);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    // Admin cria usuários internos
    public function createUser(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'email' => ['required','email','max:255', Rule::unique('users','email')],
            'password' => ['required','string','min:6'],
        ]);
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => $request->boolean('is_admin'),
        ]);
        return response()->json($user, 201);
    }

    // Atualiza usuário (admin)
    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['sometimes','required','string','max:255'],
            'email' => ['sometimes','required','email','max:255', Rule::unique('users','email')->ignore($user->id)],
            'password' => ['nullable','string','min:6'],
            'is_admin' => ['sometimes','boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }
        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
        }
        if (array_key_exists('is_admin', $data)) {
            $user->is_admin = (bool) $data['is_admin'];
        }
        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();
        return response()->json($user);
    }

    // Exclui usuário (admin)
    public function deleteUser(Request $request, User $user)
    {
        // Não permitir excluir a si mesmo por segurança
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'Você não pode excluir a si mesmo.'
            ], 422);
        }
        $user->delete();
        return response()->json(['message' => 'Usuário removido']);
    }

    // Login retorna token Sanctum
    public function login(Request $request)
    {
        // Exigir JSON para evitar chamadas GET/FORM confundirem a rota
        $contentType = $request->header('Content-Type') ?? '';
        if (!str_starts_with(strtolower($contentType), 'application/json')) {
            return response()->json([
                'message' => 'Use Content-Type: application/json e envie {"email","password"} no corpo.'
            ], 400);
        }

        $credentials = $request->validate([
            'email' => ['required','email'],
            'password' => ['required','string'],
        ]);
        $user = User::where('email', $credentials['email'])->first();
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Credenciais inválidas'], 422);
        }
        $token = $user->createToken('auth')->plainTextToken;
        return response()->json([
            'token' => $token,
            'user' => $user->only(['id','name','email','is_admin']),
        ]);
    }

    public function me(Request $request)
    {
        return $request->user();
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'ok']);
    }
}
