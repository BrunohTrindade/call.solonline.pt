<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('numero')->nullable()->unique()->after('id');
        });

        // Popular sequencialmente baseado em ID ascendente
        $next = 1;
        DB::table('contacts')->orderBy('id')->chunkById(1000, function($chunk) use (&$next) {
            foreach ($chunk as $row) {
                DB::table('contacts')->where('id', $row->id)->update(['numero' => $next]);
                $next++;
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Remover índice único se necessário é automático com dropColumn em MySQL
            $table->dropColumn('numero');
        });
    }
};
