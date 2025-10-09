<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role', 20)->default('normal')->after('is_admin');
                $table->index('role');
            });
        }

        // Retrocompatibilidade: preencher role baseado em is_admin
        DB::table('users')->where('is_admin', 1)->update(['role' => 'admin']);
        DB::table('users')->where('is_admin', 0)->update(['role' => 'normal']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['role']);
                $table->dropColumn('role');
            });
        }
    }
};
