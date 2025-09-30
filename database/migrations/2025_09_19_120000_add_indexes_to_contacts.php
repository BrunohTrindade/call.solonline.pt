<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // numero já possui unique index em migração anterior; não criar índice adicional
            // Índice para filtros por processed_at (pending/processed)
            if (Schema::hasColumn('contacts', 'processed_at')) {
                $driver = DB::getDriverName();
                if (in_array($driver, ['mysql','mariadb'])) {
                    $dbname = DB::getDatabaseName();
                    $exists = DB::table('information_schema.statistics')
                        ->where('TABLE_SCHEMA', $dbname)
                        ->where('TABLE_NAME', 'contacts')
                        ->where('COLUMN_NAME', 'processed_at')
                        ->exists();
                    if (!$exists) {
                        $table->index('processed_at', 'contacts_processed_at_index');
                    }
                }
            }

            // Índices opcionais para busca básica (evita duplicar se já existir por outra migração)
            foreach (['email','nome','empresa'] as $col) {
                if (Schema::hasColumn('contacts', $col)) {
                    if (in_array(DB::getDriverName(), ['mysql','mariadb'])) {
                        $dbname = DB::getDatabaseName();
                        $exists = DB::table('information_schema.statistics')
                            ->where('TABLE_SCHEMA', $dbname)
                            ->where('TABLE_NAME', 'contacts')
                            ->where('COLUMN_NAME', $col)
                            ->exists();
                        if (!$exists) {
                            $table->index($col, 'contacts_'.$col.'_index');
                        }
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Remove apenas índices que esta migration pode ter criado
            try { $table->dropIndex(['email']); } catch (\Throwable $e) {}
            try { $table->dropIndex(['nome']); } catch (\Throwable $e) {}
            try { $table->dropIndex(['empresa']); } catch (\Throwable $e) {}
            // Não remover processed_at ou numero aqui (podem ter sido criados por outras migrations)
        });
    }
};
