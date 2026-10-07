<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mailbox a person last opened in Email, to open it again next time
 * (docs/DECISOES.md, "Email: escolher a caixa num menu").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('last_mailbox_id')->nullable()->after('last_seen_at')->constrained('mailboxes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_mailbox_id');
        });
    }
};
