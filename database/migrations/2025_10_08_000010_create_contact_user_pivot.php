<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('contact_user')) {
            Schema::create('contact_user', function (Blueprint $table) {
                $table->unsignedBigInteger('contact_id');
                $table->unsignedBigInteger('user_id');
                $table->primary(['contact_id','user_id']);
                $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

    // A primary key composta já cria índices eficientes para as junções.
    }

    public function down(): void
    {
        if (Schema::hasTable('contact_user')) {
            Schema::drop('contact_user');
        }
    }
};
