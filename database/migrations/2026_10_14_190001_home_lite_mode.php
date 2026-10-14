<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // "lite" or "full"; null follows the default for the person (docs/DECISOES.md, "Ecrã de entrada e modo lite").
            $table->string('ui_mode', 8)->nullable();
        });

        Schema::table('agents', function (Blueprint $table) {
            // Up to four prompts shown on the entry screen; null shows the ones its capabilities suggest.
            $table->json('suggestions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ui_mode');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('suggestions');
        });
    }
};
