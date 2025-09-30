<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Em ambientes não-produtivos, cria um usuário de teste opcional
        if (app()->environment() !== 'production' && !User::where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);
        }

        // Admin configurável via variáveis de ambiente
        $adminEmail = env('ADMIN_EMAIL', 'admin@example.com');
        $adminPassword = env('ADMIN_PASSWORD', 'admin123');
        $adminName = env('ADMIN_NAME', 'Admin');

        $admin = User::where('email', $adminEmail)->first();
        if (!$admin) {
            User::create([
                'name' => $adminName,
                'email' => $adminEmail,
                'password' => Hash::make($adminPassword),
                'is_admin' => true,
            ]);
        } else {
            // Garante flag de admin
            if (!$admin->is_admin) {
                $admin->is_admin = true;
                $admin->save();
            }
        }
    }
}
