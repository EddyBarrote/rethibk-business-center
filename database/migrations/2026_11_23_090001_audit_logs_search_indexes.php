<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Definições › Auditoria filters the tenant's trail by action (exact or a
 * family such as "erp."), by result and by the kind of object, and counts
 * each filtered set for the pager (docs/DECISOES.md, "Auditoria pesquisável
 * e exportável"). The existing (tenant_id, created_at), (actor_type,
 * actor_id) and (subject_type, subject_id) indexes cover the period, the
 * actor and one object; these cover the rest, and the lists of actions and
 * kinds of object the filters offer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['tenant_id', 'action', 'created_at']);
            $table->index(['tenant_id', 'result', 'created_at']);
            $table->index(['tenant_id', 'subject_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'action', 'created_at']);
            $table->dropIndex(['tenant_id', 'result', 'created_at']);
            $table->dropIndex(['tenant_id', 'subject_type', 'created_at']);
        });
    }
};
