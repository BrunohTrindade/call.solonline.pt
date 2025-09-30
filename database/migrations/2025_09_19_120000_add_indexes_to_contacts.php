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
                $idxName = 'contacts_processed_at_index';
                $exists = false;
                if ($driver === 'sqlite') {
                    $exists = DB::table('sqlite_master')
                        ->where('type', 'index')
                        ->where('name', $idxName)
                        ->exists();
                } else {
                    // fallback genérico: tenta criar e ignora erro no down()
                }
                if (!$exists) {
                    $table->index('processed_at');
                }
            }
            // Índices opcionais para busca básica (evita duplicar se já existir por outra migração)
            foreach (['email','nome','empresa'] as $col) {
                if (Schema::hasColumn('contacts', $col)) {
                    $idx = 'contacts_'.$col.'_index';
                    $exists = false;
                    if (DB::getDriverName() === 'sqlite') {
                        $exists = DB::table('sqlite_master')->where('type','index')->where('name',$idx)->exists();
                    }
                    if (!$exists) {
                        $table->index($col);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Remove índices se existirem
            try { $table->dropIndex(['numero']); } catch (\Throwable $e) {}
            try { $table->dropIndex(['processed_at']); } catch (\Throwable $e) {}
            try { $table->dropIndex(['email']); } catch (\Throwable $e) {}
            try { $table->dropIndex(['nome']); } catch (\Throwable $e) {}
            try { $table->dropIndex(['empresa']); } catch (\Throwable $e) {}
        });
    }
};
