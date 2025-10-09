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
            ->select(['id','name','email','is_admin','role','active','created_at','updated_at']);

        $result = $query->paginate($perPage, ['*'], 'page', $page);
        $result->getCollection()->transform(function ($u) {
            $role = $u->role ?? ($u->is_admin ? 'admin' : 'normal');
            $isActive = (bool) ($u->active ?? true);
            return array_merge($u->toArray(), [
                'is_commercial' => $role === 'comercial',
                'is_active' => $isActive,
                'status' => $isActive ? 'active' : 'inactive',
            ]);
        });
        return response()->json($result);
    }

    // Admin cria usuários internos
    public function createUser(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'email' => ['required','email','max:255', Rule::unique('users','email')],
            'password' => ['required','string','min:6'],
            'role' => ['sometimes','in:admin,normal,comercial'],
            'is_commercial' => ['sometimes','boolean'],
            'active' => ['sometimes','boolean'],
        ]);
        // Determinar role a partir de role explícito, is_admin e is_commercial
        $isAdminFlag = $request->boolean('is_admin');
        $isCommercialFlag = $request->boolean('is_commercial');
        $resolvedRole = $data['role']
            ?? ($isAdminFlag ? 'admin' : ($isCommercialFlag ? 'comercial' : 'normal'));
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_admin' => $isAdminFlag,
            'role' => $resolvedRole,
            'active' => array_key_exists('active', $data) ? (bool)$data['active'] : true,
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
            'role' => ['sometimes','in:admin,normal,comercial'],
            'is_commercial' => ['sometimes','boolean'],
            'active' => ['sometimes','boolean'],
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
        if (array_key_exists('active', $data)) {
            $user->active = (bool) $data['active'];
        }
        // Resolver role conforme prioridade: role explícito > is_admin > is_commercial
        if (array_key_exists('role', $data)) {
            $user->role = $data['role'];
        } else {
            $isCommercialFlag = $request->has('is_commercial') ? $request->boolean('is_commercial') : null;
            if (array_key_exists('is_admin', $data)) {
                $user->role = $user->is_admin ? 'admin' : ($isCommercialFlag === true ? 'comercial' : ($isCommercialFlag === false ? 'normal' : ($user->role ?? 'normal')));
            } elseif ($isCommercialFlag !== null) {
                $user->role = $isCommercialFlag ? 'comercial' : ($user->role === 'comercial' ? 'normal' : ($user->role ?? 'normal'));
            }
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

    // Ativa/Desativa usuário (admin)
    public function setUserActive(Request $request, User $user)
    {
        $data = $request->validate([
            'active' => ['required','boolean'],
        ]);
        $user->active = (bool) $data['active'];
        $user->save();
        $isActive = (bool) $user->active;
        return response()->json([
            'id' => $user->id,
            'active' => $isActive,
            'is_active' => $isActive,
            'status' => $isActive ? 'active' : 'inactive',
        ]);
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
        if (property_exists($user, 'active') && $user->active === false) {
            return response()->json(['message' => 'Usuário inativo'], 403);
        }
        $token = $user->createToken('auth')->plainTextToken;
        return response()->json([
            'token' => $token,
            'user' => array_merge($user->only(['id','name','email','is_admin','role','active']), [
                'is_commercial' => ($user->role ?? '') === 'comercial',
                'is_active' => (bool) ($user->active ?? true),
                'status' => (bool)($user->active ?? true) ? 'active' : 'inactive',
            ]),
        ]);
    }

    public function me(Request $request)
    {
        $u = $request->user();
        if (!$u) { return response()->json(null, 401); }
        return response()->json(array_merge($u->only(['id','name','email','is_admin','role','active','created_at','updated_at']), [
            'is_commercial' => ($u->role ?? '') === 'comercial',
            'is_active' => (bool) ($u->active ?? true),
            'status' => (bool)($u->active ?? true) ? 'active' : 'inactive',
        ]));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'ok']);
    }
}
