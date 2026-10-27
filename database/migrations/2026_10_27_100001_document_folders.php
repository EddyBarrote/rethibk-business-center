<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Folders for the files of Ficheiros (docs/DECISOES.md, "Gestor de
 * ficheiros"): one level, shared by the company. A file without a folder
 * stays at the top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('generated_documents', function (Blueprint $table) {
            $table->foreignId('document_folder_id')->nullable()->after('skill_id')->constrained('document_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_folder_id');
        });
        Schema::dropIfExists('document_folders');
    }
};
