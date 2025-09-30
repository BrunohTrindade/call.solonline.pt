<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Índice em updated_at para ETag/ordenamentos auxiliares
            if (Schema::hasColumn('contacts', 'updated_at')) {
                if (in_array(DB::getDriverName(), ['mysql','mariadb'])) {
                    $dbname = DB::getDatabaseName();
                    $exists = DB::table('information_schema.statistics')
                        ->where('TABLE_SCHEMA', $dbname)
                        ->where('TABLE_NAME', 'contacts')
                        ->where('COLUMN_NAME', 'updated_at')
                        ->exists();
                    if (!$exists) { $table->index('updated_at', 'contacts_updated_at_index'); }
                }
            }
            // Índice composto processed_at+numero para filtros e ordenação por numero
            $idxCombo = 'contacts_processed_num_index';
            if (Schema::hasColumn('contacts', 'processed_at') && Schema::hasColumn('contacts', 'numero')) {
                if (in_array(DB::getDriverName(), ['mysql','mariadb'])) {
                    $dbname = DB::getDatabaseName();
                    $existsCombo = DB::table('information_schema.statistics')
                        ->where('TABLE_SCHEMA', $dbname)
                        ->where('TABLE_NAME', 'contacts')
                        ->where('INDEX_NAME', $idxCombo)
                        ->exists();
                    if (!$existsCombo) {
                        $table->index(['processed_at','numero'], $idxCombo);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            try { $table->dropIndex('contacts_updated_at_index'); } catch (\Throwable $e) {}
            try { $table->dropIndex('contacts_processed_num_index'); } catch (\Throwable $e) {}
        });
    }
};
