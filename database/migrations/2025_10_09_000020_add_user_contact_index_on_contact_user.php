<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Criar índice (user_id, contact_id) para acelerar buscas por usuário
        $exists = DB::selectOne(
            "SELECT COUNT(1) AS c FROM information_schema.statistics WHERE table_schema = schema() AND table_name = 'contact_user' AND index_name = 'contact_user_user_contact_idx'"
        );
        if (!$exists || (int)$exists->c === 0) {
            DB::statement('CREATE INDEX contact_user_user_contact_idx ON contact_user (user_id, contact_id)');
        }
    }

    public function down(): void
    {
        try {
            DB::statement('DROP INDEX contact_user_user_contact_idx ON contact_user');
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
