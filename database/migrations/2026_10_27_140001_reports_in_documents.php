<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports join the files in Documentos (docs/DECISOES.md, "Documentos:
 * relatórios e ficheiros juntos"): a report can sit in a folder, and a file
 * exported from a report remembers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('document_folder_id')->nullable()->after('agent_run_id')->constrained('document_folders')->nullOnDelete();
        });

        Schema::table('generated_documents', function (Blueprint $table) {
            $table->foreignId('report_id')->nullable()->after('document_folder_id')->constrained('reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('report_id');
        });
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_folder_id');
        });
    }
};
