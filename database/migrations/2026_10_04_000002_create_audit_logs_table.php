<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Section 5.4. Append-only: there is no updated_at column.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('actor_type', ['user', 'agent', 'system', 'platform_admin']);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->nullableMorphs('subject');
            $table->json('payload')->nullable();
            $table->enum('result', ['ok', 'denied', 'error']);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
