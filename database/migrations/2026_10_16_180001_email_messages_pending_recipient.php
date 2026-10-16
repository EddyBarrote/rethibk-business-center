<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who an agent's draft is for when no source had the address: shown on the
 * email card as missing, never guessed (docs/DECISOES.md, "Email proposto na conversa").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->string('pending_recipient')->nullable()->after('bcc');
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropColumn('pending_recipient');
        });
    }
};
