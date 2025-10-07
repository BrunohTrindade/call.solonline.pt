<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql','mariadb'], true)) {
            return; // apenas MySQL/MariaDB
        }
        $dbname = DB::getDatabaseName();
        $existing = collect(DB::select('SELECT INDEX_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_TYPE = "FULLTEXT"', [ $dbname, 'contacts' ]))
            ->pluck('INDEX_NAME')->map('strtolower')->all();
        if (!in_array('contacts_search_fulltext', $existing, true)) {
            // InnoDB + MySQL 5.6+ suporta FULLTEXT; nomes/empresa/telefone/email
            DB::statement('ALTER TABLE contacts ADD FULLTEXT contacts_search_fulltext (nome, email, empresa, telefone)');
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (!in_array($driver, ['mysql','mariadb'], true)) { return; }
        $dbname = DB::getDatabaseName();
        $existing = collect(DB::select('SELECT INDEX_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_TYPE = "FULLTEXT"', [ $dbname, 'contacts' ]))
            ->pluck('INDEX_NAME')->map('strtolower')->all();
        if (in_array('contacts_search_fulltext', $existing, true)) {
            DB::statement('ALTER TABLE contacts DROP INDEX contacts_search_fulltext');
        }
    }
};
