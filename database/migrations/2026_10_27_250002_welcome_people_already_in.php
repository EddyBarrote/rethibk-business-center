<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Whoever already used the platform had their first day (docs/DECISOES.md,
 * "Primeiro dia"): only people who never came in are welcomed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('welcomed_at')->whereNotNull('last_seen_at')->update(['welcomed_at' => DB::raw('last_seen_at')]);
    }

    public function down(): void
    {
        // Nothing to undo: the column goes with the previous migration.
    }
};
