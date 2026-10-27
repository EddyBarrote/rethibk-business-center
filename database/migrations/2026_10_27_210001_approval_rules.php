<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standing rules on top of the autonomy levels (docs/DECISOES.md, "Regras de
 * aprovação"): for an agent (or all of them) and a capability, always allow,
 * always ask, or always refuse. Each approval keeps the rule that asked for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('capability_key', 120);
            $table->string('effect', 10);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_approval_id')->nullable()->constrained('approvals')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'capability_key']);
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->foreignId('rule_id')->nullable()->after('ceiling_reason')->constrained('approval_rules')->nullOnDelete();
            $table->string('rule_label')->nullable()->after('rule_id');
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rule_id');
            $table->dropColumn('rule_label');
        });
        Schema::dropIfExists('approval_rules');
    }
};
