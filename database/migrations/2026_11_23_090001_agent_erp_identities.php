<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An agent's own identity in the ERP (docs/DECISOES.md, "Identidade própria de
 * cada agente no ERP"): a static token of the ERP's MCP server that acts as
 * that agent's staff member, with only that agent's tools ticked. Encrypted;
 * without one the agent uses the tenant connection's token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_erp_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->json('last_test')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->json('last_refusal')->nullable();
            $table->timestamp('last_refused_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_erp_identities');
    }
};
