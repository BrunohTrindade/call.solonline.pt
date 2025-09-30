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
    $driver = DB::getDriverName();
    if ($driver === 'sqlite') {
            DB::statement('CREATE INDEX IF NOT EXISTS contacts_numero_index ON contacts(numero)');
            DB::statement('CREATE INDEX IF NOT EXISTS contacts_processed_at_index ON contacts(processed_at)');
            DB::statement('CREATE INDEX IF NOT EXISTS contacts_email_index ON contacts(email)');
            DB::statement('CREATE INDEX IF NOT EXISTS contacts_nome_index ON contacts(nome)');
            DB::statement('CREATE INDEX IF NOT EXISTS contacts_empresa_index ON contacts(empresa)');
            DB::statement('CREATE INDEX IF NOT EXISTS contacts_telefone_index ON contacts(telefone)');
            DB::statement('CREATE INDEX IF NOT EXISTS contacts_nif_index ON contacts(nif)');
        } else {
            // MySQL/MariaDB: checa information_schema antes de criar
            $dbname = DB::getDatabaseName();
            $indexes = collect(DB::select('SELECT INDEX_NAME FROM information_schema.statistics WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',[ $dbname, 'contacts']))
                ->pluck('INDEX_NAME')->map('strtolower')->all();
            $create = function($name, $sql) use ($indexes) { if (!in_array(strtolower($name), $indexes, true)) { DB::statement($sql); } };
            $create('contacts_numero_index', 'CREATE INDEX contacts_numero_index ON contacts (numero)');
            $create('contacts_processed_at_index', 'CREATE INDEX contacts_processed_at_index ON contacts (processed_at)');
            $create('contacts_email_index', 'CREATE INDEX contacts_email_index ON contacts (email)');
            $create('contacts_nome_index', 'CREATE INDEX contacts_nome_index ON contacts (nome)');
            $create('contacts_empresa_index', 'CREATE INDEX contacts_empresa_index ON contacts (empresa)');
            $create('contacts_telefone_index', 'CREATE INDEX contacts_telefone_index ON contacts (telefone)');
            $create('contacts_nif_index', 'CREATE INDEX contacts_nif_index ON contacts (nif)');
        }
    }

    public function down(): void
    {
        // Remover índices (não remove coluna numero)
    $driver = DB::getDriverName();
    if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS contacts_numero_index');
            DB::statement('DROP INDEX IF EXISTS contacts_processed_at_index');
            DB::statement('DROP INDEX IF EXISTS contacts_email_index');
            DB::statement('DROP INDEX IF EXISTS contacts_nome_index');
            DB::statement('DROP INDEX IF EXISTS contacts_empresa_index');
            DB::statement('DROP INDEX IF EXISTS contacts_telefone_index');
            DB::statement('DROP INDEX IF EXISTS contacts_nif_index');
        } else {
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
    }
};
