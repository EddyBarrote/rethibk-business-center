<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capabilities every agent gets switched on once, which admins can then turn
 * off per agent (docs/DECISOES.md, "Pesquisa na internet"). The date marks
 * that the one-off grant already happened in this tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capabilities', function (Blueprint $table) {
            $table->timestamp('granted_to_agents_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('capabilities', function (Blueprint $table) {
            $table->dropColumn('granted_to_agents_at');
        });
    }
};
