<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * E04 to E08: briefings (section 5.7), the documents agents draft for
     * people to review, bank statements for reconciliation, purchase
     * requests, supplier ratings and contracts.
     */
    public function up(): void
    {
        Schema::create('briefings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['daily', 'weekly', 'meeting', 'adhoc']);
            $table->foreignId('for_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->longText('content');
            $table->json('highlights')->nullable();
            $table->json('decisions_pending')->nullable();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'for_user_id', 'created_at']);
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 50);
            $table->string('title');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('subject_ref')->nullable();
            $table->longText('content');
            $table->json('data')->nullable();
            $table->enum('status', ['draft', 'reviewed'])->default('draft');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'created_at']);
        });

        Schema::create('bank_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('account_name');
            $table->string('bank')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('closing_balance', 15, 2)->nullable();
            $table->char('currency', 3)->default('MZN');
            $table->enum('source', ['upload', 'email']);
            $table->string('original_name')->nullable();
            $table->string('path')->nullable();
            $table->foreignId('email_attachment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('transaction_count')->default(0);
            $table->timestamps();
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_statement_id')->constrained()->cascadeOnDelete();
            $table->date('booked_at');
            $table->string('description', 500);
            $table->string('reference')->nullable();
            $table->decimal('amount', 15, 2);
            $table->decimal('balance', 15, 2)->nullable();
            $table->char('fingerprint', 64);
            $table->enum('status', ['unmatched', 'suggested', 'reconciled', 'ignored'])->default('unmatched');
            $table->string('match_type', 30)->nullable();
            $table->string('match_ref')->nullable();
            $table->text('match_note')->nullable();
            $table->foreignId('suggested_by_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->foreignId('reconciled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'fingerprint']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_ref')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('items');
            $table->date('needed_by')->nullable();
            $table->decimal('budget', 15, 2)->nullable();
            $table->enum('status', ['submitted', 'rfq', 'quoting', 'compared', 'po_draft', 'ordered', 'received', 'cancelled'])->default('submitted');
            $table->string('erp_rfq_id')->nullable();
            $table->string('erp_po_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('supplier_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_ref');
            $table->string('supplier_name');
            $table->string('po_ref')->nullable();
            $table->unsignedTinyInteger('on_time');
            $table->unsignedTinyInteger('quality');
            $table->unsignedTinyInteger('price');
            $table->text('notes')->nullable();
            $table->string('rated_by_type', 20);
            $table->unsignedBigInteger('rated_by_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'supplier_ref']);
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('party_type', ['client', 'supplier', 'other']);
            $table->string('party_ref')->nullable();
            $table->string('party_name');
            $table->string('party_domain')->nullable();
            $table->string('title');
            $table->string('reference')->nullable();
            $table->decimal('value', 15, 2)->nullable();
            $table->char('currency', 3)->default('MZN');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->unsignedSmallInteger('notice_days')->default(60);
            $table->boolean('auto_renews')->default(false);
            $table->unsignedSmallInteger('sla_response_hours')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['active', 'renewing', 'ended', 'cancelled'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('supplier_ratings');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_statements');
        Schema::dropIfExists('reports');
        Schema::dropIfExists('briefings');
    }
};
