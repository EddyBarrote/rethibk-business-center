<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's mailbox can send its new email to the company's triage
 * (docs/DECISOES.md, "Caixa de pessoa que envia para a triagem"). Each email
 * that went keeps the moment, so the older ones stay with their owners.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->boolean('sends_to_triage')->default(false)->after('kind');
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->timestamp('sent_to_triage_at')->nullable()->after('routed_to_user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropIndex(['sent_to_triage_at']);
            $table->dropColumn('sent_to_triage_at');
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('sends_to_triage');
        });
    }
};
