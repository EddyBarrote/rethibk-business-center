<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work an agent offers to do on its own (docs/DECISOES.md, "Iniciativas dos
 * agentes"), found at its heartbeat, each with the evidence it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_initiatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('why');
            $table->text('plan');
            $table->json('evidence');
            $table->string('dedupe_key', 191);
            $table->string('status', 12)->default('open');
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'status']);
            $table->index(['tenant_id', 'agent_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_initiatives');
    }
};
