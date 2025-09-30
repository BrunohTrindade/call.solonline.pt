<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetDatabasePreserveAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Options:
     *  --yes            Confirma sem pergunta interativa
     *  --admin-email=   E-mail do admin a preservar (padrão: ADMIN_EMAIL do .env)
     */
    protected $signature = 'app:reset-data {--yes} {--admin-email=}';

    /**
     * The console command description.
     */
    protected $description = 'Zera os dados do sistema preservando o(s) usuário(s) admin. Apaga contatos, jobs, tokens, sessões, cache, etc.';

    public function handle(): int
    {
        if (!$this->option('yes')) {
            $this->warn('ATENÇÃO: Esta ação apagará quase todos os dados.');
            if (!$this->confirm('Confirmar limpeza do banco preservando admin?', false)) {
                $this->info('Operação cancelada.');
                return self::SUCCESS;
            }
        }

        // Identificar admin a preservar
        $adminEmail = $this->option('admin-email') ?: env('ADMIN_EMAIL');
        $preserveIds = [];

        if ($adminEmail) {
            $admin = User::where('email', $adminEmail)->first();
            if ($admin) {
                $preserveIds[] = $admin->id;
            } else {
                $this->warn("Usuário admin com email {$adminEmail} não encontrado. Será preservado todo usuário com is_admin=1.");
            }
        }

        if (empty($preserveIds)) {
            $adminIds = User::where('is_admin', true)->pluck('id')->all();
            $preserveIds = array_map('intval', $adminIds);
        }

        if (empty($preserveIds)) {
            $this->error('Nenhum usuário admin encontrado para preservar. Abortei por segurança.');
            return self::FAILURE;
        }

        $this->info('Preservando IDs de admin: '.implode(', ', $preserveIds));

        $driver = DB::getDriverName();
        $disableFk = function () use ($driver) {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
            }
        };
        $enableFk = function () use ($driver) {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        };

        $disableFk();

        try {
            // Limpar tabelas conhecidas (se existirem)
            $tables = [
                'contacts',
                'jobs',
                'failed_jobs',
                'personal_access_tokens',
                'cache',
                'cache_locks',
                'sessions',
            ];

            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                    $this->line("Truncated: {$table}");
                }
            }

            // Limpar tabela de settings (se desejar resetar preferências)
            if (Schema::hasTable('settings')) {
                DB::table('settings')->truncate();
                $this->line('Truncated: settings');
            }

            // Usuarios: remover todos menos os preservados
            $deleted = User::whereNotIn('id', $preserveIds)->delete();
            $this->line("Users removidos (não-admin): {$deleted}");

        } finally {
            $enableFk();
        }

        $this->info('Limpeza concluída. Admin preservado.');
        return self::SUCCESS;
    }
}
