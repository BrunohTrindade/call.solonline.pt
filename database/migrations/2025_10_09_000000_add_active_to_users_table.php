<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('active')->default(true)->after('role');
                $table->index('active');
            });
            // Preencher valores existentes como ativos
            DB::table('users')->update(['active' => 1]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'active')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['active']);
                $table->dropColumn('active');
            });
        }
    }
};
