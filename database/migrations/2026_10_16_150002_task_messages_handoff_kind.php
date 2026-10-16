<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages between agents are a kind of thread entry of their own
 * (docs/DECISOES.md, "Mensagens entre agentes"); the column is an enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_messages', function (Blueprint $table) {
            $table->enum('kind', ['message', 'action', 'event', 'report', 'handoff'])->default('message')->change();
        });
    }

    public function down(): void
    {
        Schema::table('task_messages', function (Blueprint $table) {
            $table->enum('kind', ['message', 'action', 'event', 'report'])->default('message')->change();
        });
    }
};
