<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Chief of Staff revalidates actions above an agent's trust level before
 * they reach people (docs/DECISOES.md, realinhamento L11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->string('review_stage', 20)->nullable()->after('status');
            $table->foreignId('review_agent_id')->nullable()->after('review_stage')->constrained('agents')->nullOnDelete();
            $table->foreignId('decided_by_agent_id')->nullable()->after('decided_by_user_id')->constrained('agents')->nullOnDelete();
            $table->text('review_note')->nullable()->after('decision_note');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_agent_id');
            $table->dropConstrainedForeignId('decided_by_agent_id');
            $table->dropColumn(['review_stage', 'review_note']);
        });
    }
};
