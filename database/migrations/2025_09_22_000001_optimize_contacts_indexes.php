<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'numero')) {
                $table->unsignedBigInteger('numero')->nullable()->after('id');
            }
        });
        // MySQL/MariaDB: checa information_schema antes de criar
        $dbname = DB::getDatabaseName();
        $stats = collect(DB::select('SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',[ $dbname, 'contacts' ]));
        $indexes = $stats->pluck('INDEX_NAME')->map('strtolower')->all();
        $indexedCols = $stats->pluck('COLUMN_NAME')->map('strtolower')->unique()->all();
        $createSimple = function(string $indexName, string $column, string $sql) use ($indexes, $indexedCols) {
            if (in_array(strtolower($indexName), $indexes, true)) { return; }
            if (in_array(strtolower($column), $indexedCols, true)) { return; }
            DB::statement($sql);
        };
        $createSimple('contacts_numero_index', 'numero', 'CREATE INDEX contacts_numero_index ON contacts (numero)');
        $createSimple('contacts_processed_at_index', 'processed_at', 'CREATE INDEX contacts_processed_at_index ON contacts (processed_at)');
        $createSimple('contacts_email_index', 'email', 'CREATE INDEX contacts_email_index ON contacts (email)');
        $createSimple('contacts_nome_index', 'nome', 'CREATE INDEX contacts_nome_index ON contacts (nome)');
        $createSimple('contacts_empresa_index', 'empresa', 'CREATE INDEX contacts_empresa_index ON contacts (empresa)');
        $createSimple('contacts_telefone_index', 'telefone', 'CREATE INDEX contacts_telefone_index ON contacts (telefone)');
        $createSimple('contacts_nif_index', 'nif', 'CREATE INDEX contacts_nif_index ON contacts (nif)');
    }

    public function down(): void
    {
        // Remover índices (não remove coluna numero)
        $dbname = DB::getDatabaseName();
        $indexes = collect(DB::select('SELECT INDEX_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',[ $dbname, 'contacts']))
            ->pluck('INDEX_NAME')->map('strtolower')->all();
        $drop = function($name, $sql) use ($indexes) { if (in_array(strtolower($name), $indexes, true)) { DB::statement($sql); } };
        $drop('contacts_numero_index', 'DROP INDEX contacts_numero_index ON contacts');
        $drop('contacts_processed_at_index', 'DROP INDEX contacts_processed_at_index ON contacts');
        $drop('contacts_email_index', 'DROP INDEX contacts_email_index ON contacts');
        $drop('contacts_nome_index', 'DROP INDEX contacts_nome_index ON contacts');
        $drop('contacts_empresa_index', 'DROP INDEX contacts_empresa_index ON contacts');
        $drop('contacts_telefone_index', 'DROP INDEX contacts_telefone_index ON contacts');
        $drop('contacts_nif_index', 'DROP INDEX contacts_nif_index ON contacts');
    }
};
