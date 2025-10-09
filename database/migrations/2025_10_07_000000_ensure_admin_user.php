<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $name = env('ADMIN_NAME', 'Admin');
        $password = env('ADMIN_PASSWORD'); // opcional
        $force = filter_var(env('FORCE_ADMIN_PASSWORD', false), FILTER_VALIDATE_BOOLEAN);

        if (!$email) {
            return; // nada a fazer sem email válido
        }

        $now = now();
        $existing = DB::table('users')->where('email', $email)->first();

    $hasRole = Schema::hasColumn('users', 'role');
    $hasActive = Schema::hasColumn('users', 'active');

        if (!$existing) {
            // Cria admin se não existir; usa senha do env ou um padrão forte temporário
            $pwd = $password ?: 'Admin@123';
            $insert = [
                'name' => $name ?: 'Admin',
                'email' => $email,
                'password' => Hash::make($pwd),
                'is_admin' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($hasRole) { $insert['role'] = 'admin'; }
            if ($hasActive) { $insert['active'] = 1; }
            DB::table('users')->insert($insert);
        } else {
            // Garante is_admin=1; só troca a senha se explicitamente solicitado
            $update = [ 'is_admin' => 1, 'updated_at' => $now ];
            if ($hasRole) { $update['role'] = 'admin'; }
            if ($hasActive) { $update['active'] = 1; }
            if ($force && $password) {
                $update['password'] = Hash::make($password);
            }
            DB::table('users')->where('email', $email)->update($update);
        }
    }

    public function down(): void
    {
        // Não removemos o usuário admin num rollback por segurança.
    }
};
