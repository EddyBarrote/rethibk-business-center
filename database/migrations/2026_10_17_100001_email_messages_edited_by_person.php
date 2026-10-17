<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a person changed an agent's draft: the agent no longer rewrites it
 * (docs/DECISOES.md, "Um só rascunho por email").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->timestamp('edited_by_person_at')->nullable()->after('written_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropColumn('edited_by_person_at');
        });
    }
};
