<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each agent's own memory, consolidated from all its conversations and tasks
 * (docs/DECISOES.md, realinhamento L7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->string('kind', 20)->default('work');
            $table->foreignId('about_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignId('knowledge_item_id')->nullable()->constrained('knowledge_items')->nullOnDelete();
            $table->foreignId('edited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'agent_id', 'kind']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            // The last message already turned into memory, so each pass reads only what is new.
            $table->unsignedBigInteger('memory_message_id')->nullable()->after('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('memory_message_id');
        });

        Schema::dropIfExists('agent_memories');
    }
};
