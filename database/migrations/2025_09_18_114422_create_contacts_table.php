<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('empresa');
            $table->string('nome');
            $table->string('telefone');
            $table->string('email');
            $table->string('nif')->nullable();
            // observação adicionada na tela de movimentação
            $table->text('observacao')->nullable();
            // para priorizar itens processados ao final: registrar quando e por quem
            $table->timestamp('processed_at')->nullable()->index();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
