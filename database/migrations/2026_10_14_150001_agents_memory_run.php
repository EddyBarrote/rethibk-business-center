<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            // The last run with no task already turned into the agent's work memory (docs/DECISOES.md, "Falhas de contexto").
            $table->unsignedBigInteger('memory_run_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('memory_run_id');
        });
    }
};
