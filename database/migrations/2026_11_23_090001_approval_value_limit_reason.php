<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why an approval waits for a person because of the agent's value limit
 * (docs/DECISOES.md, "Limite de valor por agente"), shown on the card:
 * «Valor 125 000 MZN acima do limite de 50 000 MZN deste agente». The limits
 * themselves live in agents.settings['value_limit'] and the tenant's
 * business settings (agent_value_limit_mzn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->string('value_limit_reason', 500)->nullable()->after('ceiling_reason');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropColumn('value_limit_reason');
        });
    }
};
