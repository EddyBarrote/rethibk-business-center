<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal mailboxes (docs/DECISOES.md, "Caixas de email por pessoa"): a
 * mailbox belongs to an agent or to one or more people, and its owners say
 * which agents read it. The access matrix grows to everything a person can
 * do; existing roles keep what they could do before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('kind', 20)->default('agent')->after('agent_id');
        });

        Schema::create('mailbox_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['mailbox_id', 'user_id']);
        });

        Schema::create('mailbox_readers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->boolean('processes_new')->default(false);
            $table->timestamps();

            $table->unique(['mailbox_id', 'agent_id']);
        });

        $admin = ['work.read_all', 'projects.manage', 'org.manage', 'agents.manage', 'agents.memory', 'catalog.manage', 'emails.triage', 'emails.manage_mailboxes',
            'knowledge.manage', 'documents.read_all', 'reports.read_all', 'costs.view', 'costs.manage', 'people.manage'];
        $everyone = ['emails.own_mailboxes', 'knowledge.write', 'documents.create'];

        foreach (DB::table('access_roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true) ?: [];
            $added = [
                ...$everyone,
                ...(in_array('company.manage', $permissions, true) ? $admin : []),
                ...(in_array('work.manage', $permissions, true) ? ['projects.manage'] : []),
            ];

            DB::table('access_roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_unique([...$permissions, ...$added]))),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_readers');
        Schema::dropIfExists('mailbox_owners');

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
