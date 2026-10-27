<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day a person closed their morning summary (docs/DECISOES.md, "Resumo da
 * manhã"), so it shows once a day on any device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('morning_summary_seen_on')->nullable()->after('ui_mode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('morning_summary_seen_on');
        });
    }
};
