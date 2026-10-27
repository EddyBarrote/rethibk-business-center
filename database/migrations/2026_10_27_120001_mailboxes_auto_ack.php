<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmação de recepção (docs/DECISOES.md, "Confirmação automática de
 * recepção"): a company mailbox may answer, by itself, that the email arrived
 * and is being handled. Off unless an admin turns it on for that mailbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->boolean('auto_ack')->default(false)->after('sends_to_triage');
            $table->json('auto_ack_categories')->nullable()->after('auto_ack');
            $table->text('auto_ack_body')->nullable()->after('auto_ack_categories');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn(['auto_ack', 'auto_ack_categories', 'auto_ack_body']);
        });
    }
};
