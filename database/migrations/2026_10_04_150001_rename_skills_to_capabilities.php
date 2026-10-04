<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What used to be called a skill is an executable tool, now a capability
     * (docs/DECISOES.md); "skill" names the instruction packages instead. The
     * create migrations already use the new names, so this only renames the
     * tables of installs migrated before the change.
     */
    public function up(): void
    {
        if (! Schema::hasTable('skills') || Schema::hasTable('capabilities')) {
            return;
        }

        Schema::rename('skills', 'capabilities');
        Schema::rename('agent_skill', 'agent_capability');

        Schema::table('agent_capability', function (Blueprint $table) {
            $table->renameColumn('skill_id', 'capability_id');
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->renameColumn('skill_id', 'capability_id');
        });
    }

    public function down(): void
    {
        // The create migrations use the new names; nothing to undo.
    }
};
