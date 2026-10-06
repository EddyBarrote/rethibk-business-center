<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The email client (docs/DECISOES.md, "Email: um cliente de email na
 * plataforma"): each mailbox's folders as the IMAP server has them, and each
 * message in its folder with its UID and flags, so reading, moving and
 * deleting here does the same on the server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            // The server's name for it; for a mailbox without IMAP, the role.
            $table->string('path', 500);
            $table->string('name');
            // inbox, drafts, sent, archive, junk, trash; null for the person's own folders.
            $table->string('role', 20)->nullable();
            $table->string('delimiter', 4)->nullable();
            $table->boolean('local')->default(false);
            $table->unsignedBigInteger('uidvalidity')->nullable();
            $table->unsignedBigInteger('last_uid')->nullable();
            // The oldest UID read so far, for "Carregar mais antigos".
            $table->unsignedBigInteger('first_uid')->nullable();
            $table->boolean('has_older')->default(false);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('unread')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['mailbox_id', 'path']);
            $table->index(['tenant_id', 'mailbox_id', 'role']);
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->foreignId('email_folder_id')->nullable()->after('mailbox_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('imap_uid')->nullable()->after('email_folder_id');
            $table->boolean('is_flagged')->default(false)->after('read_at');
            // Gone from the server (deleted or moved elsewhere by another client): out of the folders, kept for the tasks that point to it.
            $table->timestamp('removed_at')->nullable()->after('is_flagged');
            // Who wrote a person's draft or email; null for agents and inbound email.
            $table->foreignId('written_by_user_id')->nullable()->after('agent_run_id')->constrained('users')->nullOnDelete();

            $table->index(['email_folder_id', 'imap_uid']);
        });

        Schema::table('mailboxes', function (Blueprint $table) {
            // Keep a copy of what is sent in the server's Sent folder (Gmail and Microsoft 365 do it themselves).
            $table->boolean('save_sent_copy')->default(true)->after('imap_last_uid');
            $table->timestamp('folders_synced_at')->nullable()->after('save_sent_copy');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn(['save_sent_copy', 'folders_synced_at']);
        });

        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropIndex(['email_folder_id', 'imap_uid']);
            $table->dropConstrainedForeignId('email_folder_id');
            $table->dropConstrainedForeignId('written_by_user_id');
            $table->dropColumn(['imap_uid', 'is_flagged', 'removed_at']);
        });

        Schema::dropIfExists('email_folders');
    }
};
