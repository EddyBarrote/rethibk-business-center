<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Section 5.5, with the triage fields of E03 (category, priority,
     * summary, extracted fields, routing, deadline) on the message itself.
     */
    public function up(): void
    {
        Schema::create('email_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->string('subject_normalized');
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'mailbox_id', 'subject_normalized']);
        });

        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('provider_message_id')->nullable();
            $table->string('message_id_header')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->text('references')->nullable();
            $table->foreignId('thread_id')->nullable()->constrained('email_threads')->nullOnDelete();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('reply_to')->nullable();
            $table->string('subject')->nullable();
            $table->longText('text_body')->nullable();
            $table->longText('html_body')->nullable();
            $table->string('raw_path')->nullable();
            $table->string('classification')->nullable();
            $table->decimal('classification_confidence', 4, 3)->nullable();
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->nullable();
            $table->text('summary')->nullable();
            $table->json('extracted')->nullable();
            $table->json('flags')->nullable();
            $table->string('erp_lead_id')->nullable();
            $table->foreignId('routed_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('deadline_at')->nullable();
            $table->enum('status', ['received', 'processing', 'processed', 'failed', 'draft', 'queued', 'sent', 'bounced'])->default('received');
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->char('dedup_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['mailbox_id', 'dedup_hash']);
            $table->index(['tenant_id', 'mailbox_id', 'received_at']);
            $table->index(['tenant_id', 'classification']);
            $table->index(['tenant_id', 'deadline_at']);
        });

        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_message_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->enum('ocr_status', ['pending', 'done', 'skipped', 'failed', 'discarded'])->default('pending');
            $table->timestamps();
        });

        Schema::create('tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('reference')->nullable();
            $table->string('title');
            $table->string('entity')->nullable();
            $table->string('url', 2000)->nullable();
            $table->char('url_hash', 64)->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->enum('status', ['new', 'reviewing', 'bidding', 'submitted', 'won', 'lost', 'discarded'])->default('new');
            $table->foreignId('email_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('erp_lead_id')->nullable();
            $table->json('matched_keywords')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'url_hash']);
            $table->index(['tenant_id', 'deadline_at']);
        });

        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('title');
            $table->text('note')->nullable();
            $table->timestamp('due_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'due_at']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('follow_ups');
        Schema::dropIfExists('tenders');
        Schema::dropIfExists('email_attachments');
        Schema::dropIfExists('email_messages');
        Schema::dropIfExists('email_threads');
    }
};
