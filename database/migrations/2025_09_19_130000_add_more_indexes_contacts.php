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
                $idx = 'contacts_updated_at_index';
                $exists = false;
                if (DB::getDriverName() === 'sqlite') {
                    $exists = DB::table('sqlite_master')->where('type','index')->where('name',$idx)->exists();
                }
                if (!$exists) { $table->index('updated_at', $idx); }
            }
            // Índice composto processed_at+numero para filtros e ordenação por numero
            $idxCombo = 'contacts_processed_num_index';
            $existsCombo = false;
            if (DB::getDriverName() === 'sqlite') {
                $existsCombo = DB::table('sqlite_master')->where('type','index')->where('name',$idxCombo)->exists();
            }
            if (!$existsCombo && Schema::hasColumn('contacts', 'processed_at') && Schema::hasColumn('contacts', 'numero')) {
                $table->index(['processed_at','numero'], $idxCombo);
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
