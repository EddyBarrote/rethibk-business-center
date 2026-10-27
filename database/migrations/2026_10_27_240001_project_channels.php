<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project as a channel (docs/DECISOES.md, "Canais por projecto"): its icon
 * and colour, which its tasks wear, and the conversation where its agent works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('color', 20)->nullable()->after('description');
            $table->string('icon', 40)->nullable()->after('color');
            $table->foreignId('channel_task_id')->nullable()->after('icon')->constrained('tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_task_id');
            $table->dropColumn(['color', 'icon']);
        });
    }
};
