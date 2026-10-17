<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The document type (a skill) a generated document followed, so a copy in
// another format keeps its style and images (docs/CAPACIDADES.md, "Tipos de documento").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->foreignId('skill_id')->nullable()->after('agent_run_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('skill_id');
        });
    }
};
