<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AdminSet extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'app:admin-set {email} {--password=} {--name=Admin} {--no-admin}';

    /**
     * The console command description.
     */
    protected $description = 'Cria ou atualiza um usuário com o e-mail informado. Por padrão marca como admin e define a senha passada.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = (string) $this->option('name');
        $password = $this->option('password');
        $isAdmin = !$this->option('no-admin');

        if (!$email) {
            $this->error('Informe um e-mail.');
            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            if (!$password) {
                $this->error('Usuário não existe. Informe --password para criá-lo.');
                return self::FAILURE;
            }
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'is_admin' => $isAdmin,
                'role' => $isAdmin ? 'admin' : 'normal',
            ]);
            $this->info("Usuário criado: {$user->email} (admin=".($isAdmin?'sim':'não').")");
            return self::SUCCESS;
        }

        $changed = false;
        if ($password) {
            $user->password = Hash::make($password);
            $changed = true;
        }
        if ($user->is_admin !== $isAdmin) {
            $user->is_admin = $isAdmin;
            $user->role = $isAdmin ? 'admin' : ($user->role === 'admin' ? 'normal' : $user->role);
            $changed = true;
        }
        if ($name && $name !== $user->name) {
            $user->name = $name;
            $changed = true;
        }
        if ($changed) {
            $user->save();
        }
        $this->info("Usuário atualizado: {$user->email} (admin=".($user->is_admin?'sim':'não').")");
        return self::SUCCESS;
    }
}
