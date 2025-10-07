<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

        if (!$existing) {
            // Cria admin se não existir; usa senha do env ou um padrão forte temporário
            $pwd = $password ?: 'Admin@123';
            DB::table('users')->insert([
                'name' => $name ?: 'Admin',
                'email' => $email,
                'password' => Hash::make($pwd),
                'is_admin' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            // Garante is_admin=1; só troca a senha se explicitamente solicitado
            $update = [ 'is_admin' => 1, 'updated_at' => $now ];
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
