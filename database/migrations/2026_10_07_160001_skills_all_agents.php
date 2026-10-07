<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A company skill can reach every agent without assigning it one by
     * one, like the capabilities every agent has (documents.generate).
     */
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->boolean('all_agents')->default(false)->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('all_agents');
        });
    }
};
