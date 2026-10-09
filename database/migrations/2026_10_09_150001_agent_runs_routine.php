<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each run a routine started points back at it, for the routine's history
 * on the Rotinas page (docs/DECISOES.md, "Fluxos, Rotinas e Execuções nas Definições").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('agent_routine_id')->nullable()->after('task_id')->constrained()->nullOnDelete();
        });

        // Runs routines started before this: dispatched with the routine as their source, or the routine's last run.
        $routines = DB::table('agent_routines')->pluck('id');
        DB::table('agent_runs')->where('trigger_source_type', 'App\\Models\\AgentRoutine')->whereIn('trigger_source_id', $routines)
            ->update(['agent_routine_id' => DB::raw('trigger_source_id')]);
        DB::table('agent_routines')->whereNotNull('last_run_id')->get(['id', 'last_run_id'])
            ->each(fn (object $routine) => DB::table('agent_runs')->where('id', $routine->last_run_id)->update(['agent_routine_id' => $routine->id]));
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agent_routine_id');
        });
    }
};
